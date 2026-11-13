<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/AdminStaffScope.php';
require_once 'includes/AdminAppraisalVisibility.php';
require_once 'includes/CompanyExportAccess.php';
require_once __DIR__ . '/../utils/SimpleXlsx.php';
require_once __DIR__ . '/../../includes/ExportLocalization.php';

function appraisalExportFetchAll(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Database error: ' . $conn->error, 500);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Exception('Bad Request: Only GET method is allowed', 405);

    $userData = requireRoles(['super_admin', 'admin', 'supervisor']);
    $locale = exportLocale($userData);
    $role = strtolower(str_replace(' ', '_', trim((string)($userData['role'] ?? ''))));
    $userId = (int)($userData['id'] ?? 0);
    $companyId = (int)($userData['company_id'] ?? 0);
    $actingContext = $role === 'admin' ? resolveActingCompanyContext($userData) : null;
    $cycleId = (int)($_GET['cycle_id'] ?? 0);
    if ($cycleId <= 0) throw new Exception('Please select an appraisal cycle to export.', 400);

    $cycleStmt = $conn->prepare('SELECT id, year, title, company_id FROM appraisal_cycles WHERE id = ? LIMIT 1');
    $cycleStmt->bind_param('i', $cycleId);
    $cycleStmt->execute();
    $cycle = $cycleStmt->get_result()->fetch_assoc();
    $cycleStmt->close();
    if (!$cycle) throw new Exception('Selected appraisal cycle was not found.', 404);

    $companyScope = $actingContext !== null
        ? (int)$actingContext['company_id'] : resolveCompanyScope($userData);
    companyExportAssertCycleScope($role === 'admin'
        ? $actingContext : ['company_id' => $companyScope], (int)$cycle['company_id']);

    $where = ['ap.cycle_id = ?'];
    $types = 'i';
    $params = [$cycleId];

    if ($role === 'admin') {
        $where[] = 'ap.company_id = ?';
        $types .= 'i';
        $params[] = (int)$actingContext['company_id'];
        if (empty($actingContext['is_home'])) {
            $grantFilter = companyExportGrantAppraisalFilter($actingContext, $userId, 'ap');
            $where[] = substr($grantFilter['sql'], 5); // leading ' AND ' stripped
            $types .= $grantFilter['types'];
            array_push($params, ...$grantFilter['params']);
        } else {
            // Keep the home-company staff-only scope, except the admin's own assigned team.
            $where[] = adminAppraisalVisibilityWhereSql($userId, $companyId, 'ap');
            $staffScope = trim((string)($userData['staff_scope'] ?? 'All'));
            if (in_array($staffScope, ['Local', 'Expatriate'], true)) {
                $where[] = staffScopeHomeAppraisalSql($userData, 'ap', true);
                $types .= 's';
                $params[] = $staffScope;
            }
        }
    } elseif ($role === 'supervisor') {
        $where[] = 'ap.supervisor_id = ?';
        $types .= 'i';
        $params[] = $userId;
    } else {
        $requestedType = trim((string)($_GET['staff_type'] ?? ''));
        if (in_array($requestedType, ['Local', 'Expatriate'], true)) {
            $where[] = 'ap.staff_type = ?';
            $types .= 's';
            $params[] = $requestedType;
        }
    }

    $whereSql = implode(' AND ', $where);
    $sections = appraisalExportFetchAll($conn, "
        SELECT id, code, label, type, weight
        FROM appraisal_sections
        WHERE cycle_id = ? AND is_active = 1
        ORDER BY sort_order ASC, id ASC
    ", 'i', [$cycleId]);

    $records = appraisalExportFetchAll($conn, "
        SELECT
            ap.id,
            ap.staff_fullname,
            ap.staff_location,
            ap.staff_department,
            ap.staff_job_title,
            ap.staff_type,
            COALESCE(ap.date_of_joining, u.date_of_joining, '') AS date_of_joining,
            ac.year AS appraisal_year,
            COALESCE(ap.duration_years, '') AS duration_in_lambert,
            COALESCE(ap.scoring_profile_label, '') AS scoring_profile,
            ap.development,
            ap.status_upgrade,
            ap.salary_upgrade,
            ap.evaluation_statement,
            ap.appraisal_summary,
            COALESCE(ap.raw_appraisal_summary, ap.appraisal_summary) AS raw_appraisal_summary,
            ap.serious_breach_declared,
            ap.breach_cap_active,
            ap.serious_breach_review_status,
            ap.status,
            ap.feedback,
            ap.update_count,
            ap.created_at,
            ap.updated_at,
            TRIM(CONCAT(COALESCE(sup.first_name,''), ' ', COALESCE(sup.last_name,''))) AS supervisor_name,
            CASE
                WHEN mr.id IS NULL THEN 'Unreviewed'
                WHEN mr.appraisal_revision_no <> (COALESCE(ap.update_count,0) + 1) THEN 'Unreviewed - Changed Since Review'
                WHEN mr.review_status = 'follow_up' THEN 'Follow-up Required'
                ELSE 'Reviewed'
            END AS management_review_status
        FROM appraisals ap
        INNER JOIN appraisal_cycles ac ON ac.id = ap.cycle_id
        LEFT JOIN users u ON u.id = ap.staff_user_id
        LEFT JOIN users sup ON sup.id = ap.supervisor_id
        LEFT JOIN appraisal_management_reviews mr ON mr.id = (
            SELECT MAX(mr2.id) FROM appraisal_management_reviews mr2 WHERE mr2.appraisal_id = ap.id
        )
        WHERE {$whereSql}
        ORDER BY ap.staff_department ASC, ap.staff_fullname ASC
    ", $types, $params);

    $sectionRows = appraisalExportFetchAll($conn, "
        SELECT ap.id AS appraisal_id, ss.section_id, ss.section_code, ss.section_label,
               ss.section_weight, ss.section_avg, ss.weighted_score
        FROM appraisals ap
        INNER JOIN appraisal_section_scores ss ON ss.appraisal_id = ap.id
        WHERE {$whereSql}
        ORDER BY ap.id ASC, ss.id ASC
    ", $types, $params);
    $scoresByAppraisal = [];
    foreach ($sectionRows as $score) {
        $scoresByAppraisal[(int)$score['appraisal_id']][(int)$score['section_id']] = $score;
    }

    // Collect all eligible DB-authored text once after the access-scoped reads.
    // Never send personal writing (feedback, development notes, job titles) to memory.
    $approved = exportContentMap($conn, $locale, [
        ['context' => 'section', 'values' => array_column($sections, 'label')],
        ['context' => 'section', 'values' => array_column($sectionRows, 'section_label')],
        ['context' => 'department', 'values' => array_column($records, 'staff_department')],
        ['context' => 'job_title', 'values' => array_column($records, 'staff_job_title')],
        ['context' => 'scoring_profile', 'values' => array_column($records, 'scoring_profile')],
        ['context' => 'evaluation_statement', 'values' => array_column($records, 'evaluation_statement')],
    ]);

    $headers = [
        'S/N', 'Full Name', 'Location', 'Department', 'Job Title', 'Staff Type', 'Date of Joining',
        'Appraisal Year', 'Duration In Lambert', 'Scoring Profile', 'Supervisor'
    ];
    foreach ($sections as $section) {
        $prefix = trim((string)$section['code']) !== '' ? $section['code'] . ' - ' : '';
        $translatedSection = exportContent($approved, 'section', $section['label']);
        $headers[] = $prefix . $translatedSection . ' ' . exportLabel($locale, 'Score');
        $headers[] = $prefix . $translatedSection . ' ' . exportLabel($locale, 'Weight Used');
    }
    $headers = array_merge($headers, [
        'Original Weighted Score', 'Final Score', 'Breach Declared', 'Breach Cap', 'Safety Verification', 'Evaluation Statement', 'Employee Status', 'Management Review',
        'Development', 'Status Upgrade', 'Salary Upgrade', 'Employee Feedback',
        'Supervisor Updates', 'Submitted At', 'Last Updated'
    ]);

    $rows = [];
    foreach ($records as $index => $row) {
        $line = [
            $index + 1,
            $row['staff_fullname'],
            $row['staff_location'],
            exportContent($approved, 'department', $row['staff_department']),
            exportContent($approved, 'job_title', $row['staff_job_title']),
            exportLabel($locale, $row['staff_type']),
            exportDate($locale, $row['date_of_joining']),
            $row['appraisal_year'],
            $row['duration_in_lambert'],
            exportContent($approved, 'scoring_profile', $row['scoring_profile']),
            $row['supervisor_name'],
        ];
        foreach ($sections as $section) {
            $score = $scoresByAppraisal[(int)$row['id']][(int)$section['id']] ?? [];
            $line[] = exportNumber($score['section_avg'] ?? '');
            $line[] = exportNumber($score['section_weight'] ?? '');
        }
        $line = array_merge($line, [
            exportNumber($row['raw_appraisal_summary']),
            exportNumber($row['appraisal_summary']),
            exportLabel($locale, $row['serious_breach_declared'] === null ? 'Not recorded' : ((int)$row['serious_breach_declared'] === 1 ? 'Yes' : 'No')),
            exportLabel($locale, (int)$row['breach_cap_active'] === 1 ? 'Applied' : 'Not applied'),
            exportLabel($locale, $row['serious_breach_review_status']),
            exportContent($approved, 'evaluation_statement', $row['evaluation_statement']),
            exportLabel($locale, $row['status']),
            exportLabel($locale, $row['management_review_status']),
            $row['development'],
            $row['status_upgrade'],
            $row['salary_upgrade'],
            $row['feedback'],
            $row['update_count'],
            exportDate($locale, $row['created_at'], true),
            exportDate($locale, $row['updated_at'], true),
        ]);
        $rows[] = $line;
    }

    $suffix = $role === 'super_admin' && !empty($_GET['staff_type']) ? '_' . $_GET['staff_type'] : '';
    companyExportAudit($conn, $userData, $actingContext, (int)$cycle['company_id'],
        'export_appraisals_excel', 'appraisal_cycles', $cycleId, count($records));
    streamSimpleXlsx(($locale === 'fr' ? 'Evaluations_' : 'Appraisals_') . $cycle['year'] . $suffix . '.xlsx',
        array_map(static fn($label) => exportLabel($locale, $label), $headers),
        $rows, exportLabel($locale, 'Appraisals') . ' ' . $cycle['year']);
} catch (Exception $e) {
    $code = $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
