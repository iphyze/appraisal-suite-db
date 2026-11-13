<?php
/**
 * Performance analytics calculated inside AppraisalSuite. No OpenAI calls, no tokens.
 * Every query is scoped with the same permission rules as the Appraisals page:
 *  - admins / super admins: aiReadWhere(..., 'visible') for appraisals
 *  - supervisors: their own team only (progress view)
 * Groups with fewer than three distinct people never show averages.
 */
require_once __DIR__ . '/AiFoundation.php';
require_once __DIR__ . '/AiReadTools.php';
require_once __DIR__ . '/AdminStaffScope.php';

const AI_ANALYTICS_MIN_GROUP = 3;

/** Score bands for 2026 (also applied to earlier cycles for an indicative comparison). */
function aiAnalyticsBands(): array
{
    return [
        ['key' => 'outstanding', 'label' => 'Outstanding', 'min' => 4.5, 'guide' => [5, 10]],
        ['key' => 'exceeds', 'label' => 'Exceeds', 'min' => 3.5, 'guide' => [15, 20]],
        ['key' => 'fully_meets', 'label' => 'Fully Meets', 'min' => 2.5, 'guide' => [60, 70]],
        ['key' => 'needs_improvement', 'label' => 'Needs Improvement', 'min' => 1.5, 'guide' => [5, 10]],
        ['key' => 'unsatisfactory', 'label' => 'Unsatisfactory', 'min' => 0, 'guide' => [0, 5]],
    ];
}

function aiAnalyticsBandSql(string $column = 'ap.appraisal_summary'): string
{
    return "CASE WHEN {$column} >= 4.5 THEN 'outstanding' WHEN {$column} >= 3.5 THEN 'exceeds'
        WHEN {$column} >= 2.5 THEN 'fully_meets' WHEN {$column} >= 1.5 THEN 'needs_improvement' ELSE 'unsatisfactory' END";
}

function aiAnalyticsSetting(string $key, float $fallback, float $min, float $max): float
{
    $raw = aiEnv($key, '');
    if ($raw === '' || !is_numeric($raw)) return $fallback;
    return max($min, min($max, (float)$raw));
}

function aiAnalyticsIsManagement(array $user): bool
{
    return in_array((string)($user['role_key'] ?? ''), ['admin', 'super_admin'], true);
}

function aiAnalyticsIsSupervisor(array $user): bool
{
    $role = (string)($user['role_key'] ?? '');
    return $role === 'supervisor' || ($role === 'admin' && (int)($user['is_supervisor'] ?? 0) === 1);
}

/** Cycles for the selector. Progress can use cycles without appraisals yet. */
function aiAnalyticsCycles(mysqli $conn, int $companyId): array
{
    $stmt = $conn->prepare('SELECT id, title, year, is_active FROM appraisal_cycles WHERE company_id = ? ORDER BY year DESC, id DESC LIMIT 20');
    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map(static fn($c) => ['id' => (int)$c['id'], 'title' => (string)$c['title'],
        'year' => (int)$c['year'], 'active' => (int)$c['is_active'] === 1], $rows);
}

/** Resolve the cycle: requested (must belong to the company), else the active one, else the latest. */
function aiAnalyticsPickCycle(array $cycles, int $requested): ?array
{
    foreach ($cycles as $cycle) if ($requested && $cycle['id'] === $requested) return $cycle;
    if ($requested) throw new Exception('That appraisal cycle is not available.', 404);
    foreach ($cycles as $cycle) if ($cycle['active']) return $cycle;
    return $cycles[0] ?? null;
}

function aiAnalyticsRound($value): ?float
{
    return $value === null ? null : round((float)$value, 2);
}

/* ------------------------------------------------------------------ */
/*  Rankings                                                           */
/* ------------------------------------------------------------------ */

function aiAnalyticsRankings(mysqli $conn, array $user, int $companyId, int $cycleId, string $groupBy): array
{
    if (!aiAnalyticsIsManagement($user)) throw new Exception('Rankings are available to management only.', 403);
    $where = aiReadWhere($conn, $user, $companyId, 'visible') . " AND ap.cycle_id = {$cycleId}";
    $band = aiAnalyticsBandSql();

    if ($groupBy === 'band') {
        $stmt = $conn->prepare("SELECT {$band} AS band_key, COUNT(*) AS appraisals, COUNT(DISTINCT ap.staff_user_id) AS people
            FROM appraisals ap WHERE {$where} AND ap.appraisal_summary IS NOT NULL GROUP BY band_key");
        $stmt->execute();
        $counts = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $counts[$r['band_key']] = (int)$r['appraisals'];
        $stmt->close();
        return ['group_by' => 'band', 'distribution' => aiAnalyticsDistribution($counts)];
    }

    $label = [
        'department' => "COALESCE(NULLIF(TRIM(ap.staff_department), ''), 'Unspecified')",
        'supervisor' => "COALESCE(NULLIF(TRIM(sup.fullname), ''), CONCAT('Supervisor #', ap.supervisor_id))",
        'level' => "COALESCE(NULLIF(TRIM(ap.scoring_profile_label), ''), 'Level not recorded')",
    ][$groupBy] ?? null;
    if ($label === null) throw new Exception('Choose department, supervisor, level or rating band.', 422);
    $join = $groupBy === 'supervisor' ? 'LEFT JOIN users sup ON sup.id = ap.supervisor_id' : '';

    $stmt = $conn->prepare("SELECT {$label} AS label, COUNT(*) AS appraisals, COUNT(DISTINCT ap.staff_user_id) AS people,
        AVG(ap.appraisal_summary) AS average, MIN(ap.appraisal_summary) AS lowest, MAX(ap.appraisal_summary) AS highest,
        SUM(ap.appraisal_summary >= 4.5) AS outstanding,
        SUM(ap.status = 'Pending') AS pending
        FROM appraisals ap {$join} WHERE {$where} GROUP BY label ORDER BY average DESC, label ASC LIMIT 60");
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $out = [];
    foreach ($rows as $r) {
        $enough = (int)$r['people'] >= AI_ANALYTICS_MIN_GROUP;
        $count = (int)$r['appraisals'];
        $out[] = [
            'label' => (string)$r['label'], 'appraisals' => $count, 'people' => (int)$r['people'],
            'average' => $enough ? aiAnalyticsRound($r['average']) : null,
            'lowest' => $enough ? aiAnalyticsRound($r['lowest']) : null,
            'highest' => $enough ? aiAnalyticsRound($r['highest']) : null,
            'outstanding_share' => $enough && $count ? (int)round((int)$r['outstanding'] * 100 / $count) : null,
            'pending' => (int)$r['pending'],
            'small_group' => !$enough,
        ];
    }
    // Ranked groups first (highest average), small groups after.
    usort($out, static function ($a, $b) {
        if ($a['small_group'] !== $b['small_group']) return $a['small_group'] ? 1 : -1;
        return ($b['average'] ?? 0) <=> ($a['average'] ?? 0) ?: strcmp($a['label'], $b['label']);
    });
    $rank = 0;
    foreach ($out as &$row) $row['rank'] = $row['small_group'] ? null : ++$rank;
    unset($row);
    return ['group_by' => $groupBy, 'rows' => $out];
}

/** Band counts → shares compared with the framework's guideline ranges. */
function aiAnalyticsDistribution(array $counts): array
{
    $total = array_sum($counts);
    $rows = [];
    foreach (aiAnalyticsBands() as $band) {
        $count = (int)($counts[$band['key']] ?? 0);
        $share = $total ? round($count * 100 / $total, 1) : 0.0;
        [$lo, $hi] = $band['guide'];
        $rows[] = [
            'key' => $band['key'], 'label' => $band['label'], 'count' => $count, 'share' => $share,
            'guide_low' => $lo, 'guide_high' => $hi,
            'position' => $total === 0 ? 'none' : ($share > $hi ? 'above' : ($share < $lo ? 'below' : 'within')),
        ];
    }
    return ['total' => $total, 'bands' => $rows];
}

/* ------------------------------------------------------------------ */
/*  Calibration                                                        */
/* ------------------------------------------------------------------ */

function aiAnalyticsCalibration(mysqli $conn, array $user, int $companyId, int $cycleId): array
{
    if (!aiAnalyticsIsManagement($user)) throw new Exception('Calibration is available to management only.', 403);
    $where = aiReadWhere($conn, $user, $companyId, 'visible') . " AND ap.cycle_id = {$cycleId} AND ap.appraisal_summary IS NOT NULL";
    $gap = aiAnalyticsSetting('AI_CALIBRATION_GAP', 0.5, 0.2, 2.0);

    $stmt = $conn->prepare("SELECT AVG(ap.appraisal_summary) AS average, COUNT(*) AS appraisals FROM appraisals ap WHERE {$where}");
    $stmt->execute();
    $company = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $companyAvg = aiAnalyticsRound($company['average'] ?? null);

    $band = aiAnalyticsBandSql();
    $stmt = $conn->prepare("SELECT {$band} AS band_key, COUNT(*) AS n FROM appraisals ap WHERE {$where} GROUP BY band_key");
    $stmt->execute();
    $counts = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $counts[$r['band_key']] = (int)$r['n'];
    $stmt->close();

    $stmt = $conn->prepare("SELECT ap.supervisor_id, COALESCE(NULLIF(TRIM(sup.fullname), ''), CONCAT('Supervisor #', ap.supervisor_id)) AS name,
        COUNT(*) AS appraisals, COUNT(DISTINCT ap.staff_user_id) AS people, AVG(ap.appraisal_summary) AS average,
        SUM(ap.appraisal_summary >= 4.5) AS outstanding, SUM(ap.appraisal_summary < 2.5) AS below_meets
        FROM appraisals ap LEFT JOIN users sup ON sup.id = ap.supervisor_id
        WHERE {$where} GROUP BY ap.supervisor_id, name ORDER BY average DESC LIMIT 80");
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $supervisors = [];
    foreach ($rows as $r) {
        $enough = (int)$r['people'] >= AI_ANALYTICS_MIN_GROUP;
        $avg = aiAnalyticsRound($r['average']);
        $diff = ($enough && $companyAvg !== null) ? round($avg - $companyAvg, 2) : null;
        $count = (int)$r['appraisals'];
        $supervisors[] = [
            'name' => (string)$r['name'], 'appraisals' => $count, 'people' => (int)$r['people'],
            'average' => $enough ? $avg : null, 'difference' => $diff,
            'outstanding_share' => $enough && $count ? (int)round((int)$r['outstanding'] * 100 / $count) : null,
            'below_meets_share' => $enough && $count ? (int)round((int)$r['below_meets'] * 100 / $count) : null,
            'flag' => $diff === null ? ($enough ? 'typical' : 'too_few') : ($diff >= $gap ? 'high' : ($diff <= -$gap ? 'low' : 'typical')),
            'small_group' => !$enough,
        ];
    }
    usort($supervisors, static function ($a, $b) {
        $order = ['high' => 0, 'low' => 1, 'typical' => 2, 'too_few' => 3];
        return ($order[$a['flag']] <=> $order[$b['flag']]) ?: (abs($b['difference'] ?? 0) <=> abs($a['difference'] ?? 0));
    });

    return [
        'company_average' => $companyAvg, 'appraisals' => (int)($company['appraisals'] ?? 0),
        'gap' => $gap, 'distribution' => aiAnalyticsDistribution($counts), 'supervisors' => $supervisors,
        'flagged' => count(array_filter($supervisors, static fn($s) => in_array($s['flag'], ['high', 'low'], true))),
    ];
}

/* ------------------------------------------------------------------ */
/*  Alerts                                                             */
/* ------------------------------------------------------------------ */

function aiAnalyticsAlerts(mysqli $conn, array $user, int $companyId, array $cycle): array
{
    if (!aiAnalyticsIsManagement($user)) throw new Exception('Alerts are available to management only.', 403);
    $year = (int)$cycle['year'];
    $fromYear = $year - 4;
    $drop = aiAnalyticsSetting('AI_ALERT_DROP', 0.5, 0.2, 3.0);
    $where = aiReadWhere($conn, $user, $companyId, 'visible')
        . " AND ac.year BETWEEN {$fromYear} AND {$year} AND ap.appraisal_summary IS NOT NULL";

    $stmt = $conn->prepare("SELECT ap.id, ap.staff_user_id, ap.staff_fullname, ap.staff_department, ac.year, ap.appraisal_summary
        FROM appraisals ap INNER JOIN appraisal_cycles ac ON ac.id = ap.cycle_id
        WHERE {$where} ORDER BY ap.staff_user_id, ac.year, ap.id LIMIT 6000");
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // One score per person per year (the latest appraisal that year).
    $people = [];
    $completePeople = [];
    foreach ($rows as $r) {
        $id = (int)$r['staff_user_id'];
        $people[$id]['name'] = (string)$r['staff_fullname'];
        $people[$id]['department'] = (string)$r['staff_department'];
        $people[$id]['years'][(int)$r['year']] = ['score' => (float)$r['appraisal_summary'], 'id' => (int)$r['id']];
    }

    $drops = $sustained = $support = [];
    foreach ($people as $person) {
        $years = $person['years'];
        if (!isset($years[$year])) continue;
        $now = $years[$year];
        $base = ['name' => $person['name'], 'department' => $person['department'],
            'score' => round($now['score'], 2), 'link' => '/appraisals/view/' . $now['id']];
        if (isset($years[$year - 1]) && $years[$year - 1]['score'] - $now['score'] >= $drop) {
            $drops[] = $base + ['previous' => round($years[$year - 1]['score'], 2), 'change' => round($now['score'] - $years[$year - 1]['score'], 2)];
        }
        $run = 0;
        for ($y = $year; isset($years[$y]) && $years[$y]['score'] >= 4.5; $y--) $run++;
        if ($run >= 3) $sustained[] = $base + ['years' => $run];
        if ($now['score'] < 2.5) $support[] = $base;
    }
    usort($drops, static fn($a, $b) => $a['change'] <=> $b['change']);
    usort($sustained, static fn($a, $b) => ($b['years'] <=> $a['years']) ?: ($b['score'] <=> $a['score']));
    usort($support, static fn($a, $b) => $a['score'] <=> $b['score']);

    return [
        'year' => $year, 'drop_threshold' => $drop,
        'drops' => array_slice($drops, 0, 50),
        'sustained' => array_slice($sustained, 0, 50),
        'support' => array_slice($support, 0, 50),
        'note' => 'Earlier cycles used different questions and scales, so year-on-year changes are indicative.',
    ];
}

/* ------------------------------------------------------------------ */
/*  Progress                                                           */
/* ------------------------------------------------------------------ */

/** Validate a department filter before using it in a bound SQL parameter. */
function aiAnalyticsProgressDepartment($raw): string
{
    if (!is_string($raw) || strlen($raw) > 200 || !preg_match('//u', $raw)) {
        throw new Exception('Invalid department filter.', 422);
    }
    $department = trim($raw);
    if (preg_match('/[\x00-\x1F\x7F]/u', $department)) {
        throw new Exception('Invalid department filter.', 422);
    }
    return $department;
}

function aiAnalyticsProgress(mysqli $conn, array $user, int $companyId, int $cycleId, string $department = ''): array
{
    $userId = (int)$user['id'];
    $management = aiAnalyticsIsManagement($user);
    if (!$management && !aiAnalyticsIsSupervisor($user)) throw new Exception('Progress is available to supervisors and management.', 403);

    $context = aiReadCompanyContext($user, $companyId);
    $isGrant = $management && (string)($user['role_key'] ?? '') === 'admin'
        && empty($context['is_home']) && ($context['access_level'] ?? '') !== 'full';
    $filters = ["sa.cycle_id = {$cycleId}", "st.company_id = {$companyId}"];
    if ($isGrant) {
        $grantScope = (string)($context['staff_scope'] ?? '');
        if (!in_array($grantScope, ['All', 'Local', 'Expatriate'], true)) throw companyAccessForbidden();
        // All appraisees, including supervisors and other admins, follow strict scope.
        if ($grantScope !== 'All') $filters[] = "st.staff_type = '" . $grantScope . "'";
        $filters[] = adminAppraiseeVisibilityWhereSql($userId, $companyId, 'st', 'sr', $cycleId, false);
    }
    if (!$management) {
        $filters[] = "sa.supervisor_id = {$userId}";
    } elseif ((string)$user['role_key'] === 'admin' && !$isGrant) {
        // Admins see staff and supervisors (and themselves); peer admins stay private.
        $filters[] = "(st.id = {$userId} OR LOWER(REPLACE(TRIM(sr.name), ' ', '_')) IN ('staff', 'supervisor'))";
        $filters[] = staffScopeHomeUserSql($user, $cycleId, 'st', 'sr');
    }
    // When Smart Search names a department, scope every progress total and
    // supervisor row in SQL, not just the visible people list in the browser.
    if ($department !== '') $filters[] = 'st.department = ?';
    $where = implode(' AND ', $filters);
    // Historical appraisal classifications must also remain within the grant.
    // A changed current staff_type never permits an out-of-scope old appraisal.
    $appraisalSnapshotScope = $isGrant && $context['staff_scope'] !== 'All'
        ? " AND ap.staff_type = '" . $context['staff_scope'] . "'" : '';
    $appraisalCompanyWhere = " AND ap.company_id = {$companyId}" . $appraisalSnapshotScope;

    $stmt = $conn->prepare("SELECT sa.staff_id, st.fullname AS staff_name, st.department, sa.supervisor_id,
        COALESCE(NULLIF(TRIM(sup.fullname), ''), CONCAT('Supervisor #', sa.supervisor_id)) AS supervisor_name,
        (SELECT COUNT(*) FROM appraisal_staff_scoring_profiles ssp WHERE ssp.cycle_id = sa.cycle_id AND ssp.staff_user_id = sa.staff_id) AS has_level,
        (SELECT COUNT(*) FROM staff_objective_plans op WHERE op.cycle_id = sa.cycle_id AND op.staff_user_id = sa.staff_id AND op.status = 'locked') AS locked_plans,
        (SELECT ap.id FROM appraisals ap WHERE ap.cycle_id = sa.cycle_id AND ap.staff_user_id = sa.staff_id{$appraisalCompanyWhere} ORDER BY ap.id DESC LIMIT 1) AS appraisal_id,
        (SELECT ap.status FROM appraisals ap WHERE ap.cycle_id = sa.cycle_id AND ap.staff_user_id = sa.staff_id{$appraisalCompanyWhere} ORDER BY ap.id DESC LIMIT 1) AS appraisal_status,
        (SELECT COUNT(*) FROM supervisor_onboarding so WHERE so.cycle_id = sa.cycle_id AND so.supervisor_id = sa.supervisor_id AND so.onboarded_at IS NOT NULL) AS onboarded
        FROM supervisor_assignments sa
        INNER JOIN users st ON st.id = sa.staff_id
        LEFT JOIN roles sr ON sr.id = st.role_id
        LEFT JOIN users sup ON sup.id = sa.supervisor_id
        WHERE {$where} ORDER BY supervisor_name, st.fullname LIMIT 1500");
    if ($department !== '') $stmt->bind_param('s', $department);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stages = ['level_needed' => 0, 'objectives_needed' => 0, 'ready' => 0, 'awaiting_ack' => 0, 'complete' => 0];
    $bySupervisor = [];
    $people = [];
    $completePeople = [];
    foreach ($rows as $r) {
        if ($r['appraisal_id']) $stage = $r['appraisal_status'] === 'Acknowledged' ? 'complete' : 'awaiting_ack';
        elseif (!(int)$r['has_level']) $stage = 'level_needed';
        elseif (!(int)$r['locked_plans']) $stage = 'objectives_needed';
        else $stage = 'ready';
        $stages[$stage]++;
        $sid = (int)$r['supervisor_id'];
        if (!isset($bySupervisor[$sid])) {
            $bySupervisor[$sid] = ['name' => (string)$r['supervisor_name'], 'onboarded' => (int)$r['onboarded'] > 0,
                'assigned' => 0] + array_fill_keys(array_keys($stages), 0);
        }
        $bySupervisor[$sid]['assigned']++;
        $bySupervisor[$sid][$stage]++;
        // Preserve pending people first, but also include completed records so
        // searches for acknowledged appraisals have actual results to open.
        if ($stage === 'complete' && count($completePeople) >= 400) continue;
        if ($stage !== 'complete' && count($people) >= 400) continue;
        $person = ['name' => (string)$r['staff_name'], 'department' => (string)$r['department'],
            'supervisor' => (string)$r['supervisor_name'], 'stage' => $stage,
            'link' => $r['appraisal_id'] ? '/appraisals/view/' . (int)$r['appraisal_id'] : null];
        if ($stage === 'complete') $completePeople[] = $person;
        else $people[] = $person;
    }
    $total = count($rows);
    $supervisors = array_values($bySupervisor);
    foreach ($supervisors as &$s) $s['percent_done'] = $s['assigned'] ? (int)round($s['complete'] * 100 / $s['assigned']) : 0;
    unset($s);
    usort($supervisors, static fn($a, $b) => ($a['percent_done'] <=> $b['percent_done']) ?: strcmp($a['name'], $b['name']));

    return [
        'assigned' => $total, 'stages' => $stages,
        'percent_submitted' => $total ? (int)round(($stages['awaiting_ack'] + $stages['complete']) * 100 / $total) : 0,
        'percent_complete' => $total ? (int)round($stages['complete'] * 100 / $total) : 0,
        'supervisors' => $management ? $supervisors : [],
        'people' => array_merge($people, $completePeople),
        'department' => $department,
        'assignment_limited' => $total >= 1500,
        'people_limited' => count($people) < array_sum(array_intersect_key($stages, array_flip(['level_needed', 'objectives_needed', 'ready', 'awaiting_ack']))) || count($completePeople) < $stages['complete'],
        'scope' => $management ? 'company' : 'team',
    ];
}
