<?php
/** Management-only, bounded, read-only appraisal aggregates. No model-generated SQL. */
require_once __DIR__ . '/AiReadTools.php';

function aiManagementRoleAllowed(string $role): bool
{
    return in_array($role, ['admin', 'super_admin'], true);
}

function aiManagementCycleId($requested): int
{
    if ($requested === null || $requested === '') return 0;
    if (!is_scalar($requested) || !preg_match('/^(0|[1-9][0-9]{0,9})$/D', (string)$requested)) {
        throw new Exception('Invalid appraisal cycle.', 422);
    }
    return (int)$requested;
}

function aiManagementFocus($requested): string
{
    if (!in_array($requested, ['overview', 'departments', 'follow_ups'], true)) {
        throw new Exception('Choose a valid insight focus.', 422);
    }
    return (string)$requested;
}

/** The role and the selected company are resolved by PHP, never by the model. */
function aiManagementScope(mysqli $conn, array $user, ?int $viewCompanyId): array
{
    if (!aiManagementRoleAllowed((string)($user['role_key'] ?? ''))) {
        throw new Exception('Management insights are restricted to authorized management.', 403);
    }
    $companyId = aiReadCompanyId($user, $viewCompanyId);
    // Applies the same admin-to-admin and staff_scope protections as Appraisal list.
    $where = aiReadWhere($conn, $user, $companyId, 'visible');
    return [$companyId, $where];
}

function aiManagementRead(mysqli $conn, array $user, ?int $viewCompanyId, int $requestedCycleId): array
{
    [$companyId, $where] = aiManagementScope($conn, $user, $viewCompanyId);
    // Only offer cycles with at least one authorized appraisal in the selected company.
    $stmt = $conn->prepare("SELECT ac.id, ac.title, ac.year, COUNT(ap.id) AS record_count
        FROM appraisal_cycles ac JOIN appraisals ap ON ap.cycle_id = ac.id AND ap.company_id = ac.company_id
        WHERE ac.company_id = ? AND {$where}
        GROUP BY ac.id, ac.title, ac.year ORDER BY ac.year DESC, ac.id DESC LIMIT 20");
    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $cycles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $allowed = array_column($cycles, 'id');
    if ($requestedCycleId && !in_array((string)$requestedCycleId, array_map('strval', $allowed), true)) {
        throw new Exception('The selected cycle has no appraisals available to you.', 404);
    }
    $cycleId = $requestedCycleId ?: (int)($cycles[0]['id'] ?? 0);
    $options = array_map(static fn($c) => [
        'id' => (int)$c['id'], 'title' => (string)$c['title'], 'year' => (int)$c['year'],
        'records' => (int)$c['record_count'],
    ], $cycles);
    if (!$cycleId) return [
        'cycle_id' => null, 'cycles' => [], 'summary' => [
            'appraisals' => 0, 'unique_staff' => 0, 'average_score' => null, 'acknowledged' => 0,
            'pending_acknowledgements' => 0, 'open_follow_ups' => 0,
            'responded_follow_ups' => 0, 'pending_breach_reviews' => 0,
        ], 'departments' => [], 'trend' => [], 'distribution' => [],
        'note' => 'No submitted appraisals are accessible in the selected company.',
    ];

    $criteria = "{$where} AND ap.cycle_id = {$cycleId}";
    $stmt = $conn->prepare("SELECT COUNT(*) AS appraisals, COUNT(DISTINCT ap.staff_user_id) AS unique_staff,
        ROUND(AVG(ap.appraisal_summary), 2) AS average_score,
        SUM(CASE WHEN ap.status='Acknowledged' THEN 1 ELSE 0 END) AS acknowledged,
        SUM(CASE WHEN ap.status='Pending' THEN 1 ELSE 0 END) AS pending_acknowledgements,
        SUM(CASE WHEN ap.serious_breach_review_status='pending' THEN 1 ELSE 0 END) AS pending_breach_reviews
        FROM appraisals ap WHERE {$criteria}");
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $stmt = $conn->prepare("SELECT
        SUM(CASE WHEN mr.follow_up_state='open' THEN 1 ELSE 0 END) AS open_follow_ups,
        SUM(CASE WHEN mr.follow_up_state='responded' THEN 1 ELSE 0 END) AS responded_follow_ups
        FROM appraisal_management_reviews mr
        INNER JOIN appraisals ap ON ap.id=mr.appraisal_id AND ap.company_id=mr.company_id
        WHERE {$criteria} AND mr.review_status='follow_up'");
    $stmt->execute();
    $followUps = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $summary = [
        'appraisals' => (int)($summary['appraisals'] ?? 0),
        'unique_staff' => (int)($summary['unique_staff'] ?? 0),
        'average_score' => isset($summary['average_score']) ? (float)$summary['average_score'] : null,
        'acknowledged' => (int)($summary['acknowledged'] ?? 0),
        'pending_acknowledgements' => (int)($summary['pending_acknowledgements'] ?? 0),
        'open_follow_ups' => (int)($followUps['open_follow_ups'] ?? 0),
        'responded_follow_ups' => (int)($followUps['responded_follow_ups'] ?? 0),
        'pending_breach_reviews' => (int)($summary['pending_breach_reviews'] ?? 0),
    ];

    $stmt = $conn->prepare("SELECT COALESCE(NULLIF(TRIM(ap.staff_department), ''), 'Unspecified') AS department,
       COUNT(*) AS appraisals, COUNT(DISTINCT ap.staff_user_id) AS unique_staff,
       ROUND(AVG(ap.appraisal_summary), 2) AS average_score,
       SUM(CASE WHEN ap.status='Pending' THEN 1 ELSE 0 END) AS pending_acknowledgements
       FROM appraisals ap WHERE {$criteria}
       GROUP BY COALESCE(NULLIF(TRIM(ap.staff_department), ''), 'Unspecified')
       ORDER BY appraisals DESC, department ASC LIMIT 25");
    $stmt->execute();
    $departments = array_map(static function ($r) {
        $count = (int)$r['appraisals'];
        $enoughPeople = (int)$r['unique_staff'] >= 3;
        return [
            'department' => (string)$r['department'], 'appraisals' => $count,
            // Prevent AI inference from cohorts smaller than three people.
            'average_score' => $enoughPeople ? (float)$r['average_score'] : null,
            'pending_acknowledgements' => (int)$r['pending_acknowledgements'],
            'small_cohort' => !$enoughPeople,
        ];
    }, $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();

    // These are numeric score ranges, NOT evaluation labels (which vary by company/cycle).
    $stmt = $conn->prepare("SELECT
      SUM(CASE WHEN ap.appraisal_summary < 2 THEN 1 ELSE 0 END) AS below_2,
      SUM(CASE WHEN ap.appraisal_summary >= 2 AND ap.appraisal_summary < 3 THEN 1 ELSE 0 END) AS between_2_3,
      SUM(CASE WHEN ap.appraisal_summary >= 3 AND ap.appraisal_summary < 4 THEN 1 ELSE 0 END) AS between_3_4,
      SUM(CASE WHEN ap.appraisal_summary >= 4 THEN 1 ELSE 0 END) AS at_least_4,
      SUM(CASE WHEN ap.appraisal_summary IS NULL THEN 1 ELSE 0 END) AS unscored
      FROM appraisals ap WHERE {$criteria}");
    $stmt->execute();
    $distributionRow = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $distribution = [
        ['label' => 'Below 2.0', 'count' => (int)($distributionRow['below_2'] ?? 0)],
        ['label' => '2.0–2.99', 'count' => (int)($distributionRow['between_2_3'] ?? 0)],
        ['label' => '3.0–3.99', 'count' => (int)($distributionRow['between_3_4'] ?? 0)],
        ['label' => '4.0–5.0', 'count' => (int)($distributionRow['at_least_4'] ?? 0)],
        ['label' => 'Unscored', 'count' => (int)($distributionRow['unscored'] ?? 0)],
    ];

    $trend = [];
    foreach (array_slice($cycles, 0, 6) as $cycle) {
        $cid = (int)$cycle['id'];
        $stmt = $conn->prepare("SELECT COUNT(*) AS appraisals, COUNT(DISTINCT ap.staff_user_id) AS unique_staff,
               ROUND(AVG(ap.appraisal_summary),2) AS average_score
             FROM appraisals ap WHERE {$where} AND ap.cycle_id = {$cid}");
        $stmt->execute();
        $record = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $count = (int)($record['appraisals'] ?? 0);
        $trend[] = ['cycle_id' => $cid, 'label' => (string)$cycle['title'], 'year' => (int)$cycle['year'],
            'appraisals' => $count, 'average_score' => (int)($record['unique_staff'] ?? 0) >= 3 ? (float)$record['average_score'] : null];
    }
    return [
        'cycle_id' => $cycleId, 'cycles' => $options, 'summary' => $summary,
        'departments' => $departments, 'trend' => array_reverse($trend),
        'distribution' => $distribution,
        'note' => 'Submitted appraisal records only; does not measure employees not yet appraised. Pending = staff acknowledgement. Score values are final scores, including any HSE/integrity cap. Averages for fewer than 3 distinct staff are hidden. Different cycles may use different scoring rules.',
    ];
}

/** Only aggregated and suppressed values are sent to OpenAI, not individual appraisal records. */
function aiManagementModelPayload(array $report): array
{
    // A cohort with fewer than 3 DISTINCT people must not be sent to an external model.
    // This check applies to the total score/distribution as well as departments.
    if ((int)($report['summary']['unique_staff'] ?? 0) < 3) {
        throw new Exception('AI analysis requires at least three distinct appraised employees in this cycle.', 422);
    }
    $summary = $report['summary'];
    unset($summary['unique_staff']);
    // Score bands containing only one or two appraisals are too revealing for
    // an external AI model; retain the full distribution in the local UI only.
    $distribution = $report['distribution'];
    foreach ($distribution as $band) {
        $count = (int)($band['count'] ?? 0);
        if ($count > 0 && $count < 3) {
            $distribution = [];
            break;
        }
    }
    return [
        'cycle_id' => $report['cycle_id'], 'summary' => $summary,
        'departments' => array_values(array_filter($report['departments'], static fn($d) => !$d['small_cohort'])),
        'trend' => array_values(array_filter($report['trend'], static fn($t) => $t['average_score'] !== null)),
        'distribution' => $distribution,
        'cautions' => $report['note'],
    ];
}
