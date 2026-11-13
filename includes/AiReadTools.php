<?php
/** Read-only, hard-scoped AppraisalSuite AI tools. Never run model-provided SQL. */
require_once __DIR__ . '/AdminAppraisalVisibility.php';
require_once __DIR__ . '/AdminStaffScope.php';
require_once __DIR__ . '/CompanyAccess.php';

/** Private records require an explicit company context for super admins. */
function aiReadCompanyId(array $user, ?int $selectedCompanyId): int
{
    // Do not trust routes that integer-cast view_company_id; retain the original
    // selector so an invalid grant selector is rejected instead of using home.
    $requested = array_key_exists('view_company_id', $_GET)
        ? $_GET['view_company_id'] : $selectedCompanyId;
    $context = resolveActingCompanyContext($user, $requested);
    $companyId = (int)($context['company_id'] ?? 0);
    if ($companyId <= 0) {
        if (companyAccessRoleKey($user['role_key'] ?? '') === 'super_admin') {
            throw new Exception('Select a company from View Context before querying appraisal records.', 422);
        }
        throw new Exception('No company is assigned to your account.', 403);
    }
    return $companyId;
}

/** Live acting company context for a specific, already-selected AI company. */
function aiReadCompanyContext(array $user, int $companyId): array
{
    if ($companyId <= 0) throw companyAccessForbidden();
    return resolveActingCompanyContext($user, $companyId);
}

/**
 * Returned SQL must never broaden a granted-company read to home/team records.
 * Called for both appraisal snapshots and AI-generated summaries/reports.
 */
function aiGrantAppraisalConditions(array $user, array $context, string $scope): array
{
    if (!empty($context['is_home']) || ($context['access_level'] ?? '') === 'full') return [];
    if (!in_array($context['access_level'] ?? '', ['view', 'manage'], true)
        || (int)($context['company_id'] ?? 0) <= 0) throw companyAccessForbidden();
    if ($scope !== 'visible') {
        // My Appraisals and the recorded supervisor's team always belong to the
        // actor's own company, never a foreign acting-company context.
        throw new Exception('Personal and supervisor appraisal data belongs to your home company.', 403);
    }
    $scopeValue = (string)($context['staff_scope'] ?? '');
    if (!in_array($scopeValue, ['All', 'Local', 'Expatriate'], true)) throw companyAccessForbidden();
    $where = [adminAppraisalVisibilityWhereSql((int)$user['id'], (int)$context['company_id'], 'ap', false)];
    if ($scopeValue !== 'All') {
        // Enum value is validated above; SQL is a server-side constant.
        $where[] = "ap.staff_type = '" . $scopeValue . "'";
    }
    return $where;
}

/** Cache isolation for generated AI content: never reuse a home/other-grant result. */
function aiGrantCacheIdentity(array $user, int $companyId): array
{
    $context = aiReadCompanyContext($user, $companyId);
    if (!empty($context['is_home']) || ($context['access_level'] ?? '') === 'full') return [];
    return [
        'grant_id' => (int)$context['grant_id'],
        'admin_id' => (int)$user['id'],
        'access_level' => (string)$context['access_level'],
        'staff_scope' => (string)$context['staff_scope'],
        // A change of this company's peer policy must also invalidate the cache.
        'peer_visibility' => aiPeerPermissionFingerprint($GLOBALS['conn'], (int)$user['id'], $companyId),
    ];
}

/** Specific-company peer policy fingerprint (never substitute the home policy). */
function aiPeerPermissionFingerprint(mysqli $conn, int $viewerId, int $companyId): array
{
    $stmt = $conn->prepare('SELECT visibility_mode FROM admin_appraisal_visibility_policies WHERE viewer_admin_id = ? AND company_id = ? LIMIT 1');
    $stmt->bind_param('ii', $viewerId, $companyId);
    $stmt->execute();
    $mode = $stmt->get_result()->fetch_assoc()['visibility_mode'] ?? 'none';
    $stmt->close();
    $stmt = $conn->prepare('SELECT target_admin_id FROM admin_appraisal_visibility_targets WHERE viewer_admin_id = ? AND company_id = ? ORDER BY target_admin_id');
    $stmt->bind_param('ii', $viewerId, $companyId);
    $stmt->execute();
    $targets = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'target_admin_id');
    $stmt->close();
    return [$mode, $targets];
}

function aiReadScopeAllowed(array $user, string $scope): bool
{
    $role = (string)($user['role_key'] ?? '');
    if ($scope === 'mine') return $role !== 'super_admin';
    if ($scope === 'team') return $role === 'supervisor' || ($role === 'admin' && (int)($user['is_supervisor'] ?? 0) === 1);
    if ($scope === 'visible') return in_array($role, ['admin', 'super_admin'], true);
    return false;
}

/** SQL is composed ONLY of trusted, server-derived IDs, role and predefined conditions. */
function aiReadWhere(mysqli $conn, array $user, int $companyId, string $scope): string
{
    if (!aiReadScopeAllowed($user, $scope)) {
        throw new Exception('This appraisal scope is not available to your role.', 403);
    }
    $role = (string)$user['role_key'];
    $userId = (int)$user['id'];
    $context = aiReadCompanyContext($user, $companyId);
    $where = ["ap.company_id = {$companyId}"];
    $grantConditions = aiGrantAppraisalConditions($user, $context, $scope);
    if ($grantConditions) return implode(' AND ', array_merge($where, $grantConditions));

    if ($scope === 'mine') {
        $where[] = "ap.staff_user_id = {$userId}";
    } elseif ($scope === 'team') {
        $where[] = "ap.supervisor_id = {$userId}";
    } elseif ($role === 'admin') {
        // Reuse the exact peer-admin visibility policy from the normal appraisal list.
        $where[] = adminAppraisalVisibilityWhereSql($userId, $companyId, 'ap');
        $where[] = staffScopeHomeAppraisalSql($user, 'ap');
    }
    return implode(' AND ', $where);
}

function aiReadList(mysqli $conn, array $user, int $companyId, array $args): array
{
    $scope = is_string($args['scope'] ?? null) ? $args['scope'] : '';
    $where = aiReadWhere($conn, $user, $companyId, $scope);
    $cycleId = filter_var($args['cycle_id'] ?? 0, FILTER_VALIDATE_INT);
    if ($cycleId === false || $cycleId < 0) throw new Exception('Invalid cycle identifier.', 422);
    if ($cycleId > 0) $where .= ' AND ap.cycle_id = ' . $cycleId;

    $yearFrom = filter_var($args['year_from'] ?? 0, FILTER_VALIDATE_INT);
    $yearTo = filter_var($args['year_to'] ?? 0, FILTER_VALIDATE_INT);
    if ($yearFrom === false || $yearTo === false ||
        ($yearFrom === 0) !== ($yearTo === 0) ||
        ($yearFrom && ($yearFrom < 1900 || $yearTo > 2100 || $yearFrom > $yearTo))) {
        throw new Exception('Invalid appraisal year range.', 422);
    }
    if ($yearFrom) $where .= ' AND ac.year BETWEEN ' . $yearFrom . ' AND ' . $yearTo;

    $search = $args['search'] ?? '';
    if (!is_string($search) || strlen($search) > 80 || !preg_match('//u', $search)) {
        throw new Exception('Invalid employee search.', 422);
    }
    $search = trim($search);
    // People are matched by who they are, not by how their name was typed in a given year:
    // "Ifeanyi Nzekwue", "Nzekwue Ifeanyi", "Nzekwue, Ifeanyi" and "Ifeanyi Nzekwue's" all find the
    // same employee, and every year of their appraisals is returned (even if one year's record
    // spelled the name differently). Department searches still work. Wildcards are escaped.
    $tokens = aiReadNameTokens($search);
    $nameTerms = [];
    $deptTerms = [];
    $patterns = [];
    foreach ($tokens as $token) {
        $patterns[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $token) . '%';
        $nameTerms[] = "CONCAT_WS(' ', a2.staff_fullname, u2.fullname, u2.first_name, u2.last_name) LIKE ?";
        $deptTerms[] = 'ap.staff_department LIKE ?';
    }
    $searchClause = $tokens ? ' AND (ap.staff_user_id IN (SELECT a2.staff_user_id FROM appraisals a2
            LEFT JOIN users u2 ON u2.id = a2.staff_user_id
            WHERE a2.company_id = ' . (int)$companyId . ' AND ' . implode(' AND ', $nameTerms) . ')
        OR (' . implode(' AND ', $deptTerms) . '))' : '';
    $patterns = array_merge($patterns, $patterns);
    $matchNote = null;

    // No exact match: try the closest spelling among people this user may see (typos, missing letters).
    if ($tokens) {
        $probe = $conn->prepare("SELECT COUNT(*) FROM appraisals ap INNER JOIN appraisal_cycles ac ON ac.id = ap.cycle_id WHERE {$where}{$searchClause}");
        $probe->bind_param(str_repeat('s', count($patterns)), ...$patterns);
        $probe->execute();
        $found = (int)($probe->get_result()->fetch_row()[0] ?? 0);
        $probe->close();
        if ($found === 0) {
            [$ids, $names] = aiReadClosestPeople($conn, $where, $tokens);
            if ($ids) {
                $searchClause = ' AND ap.staff_user_id IN (' . implode(',', $ids) . ')';
                $patterns = [];
                $matchNote = 'No exact name match for "' . $search . '". Showing the closest match: ' . implode(', ', $names)
                    . '. Tell the user which name was matched.';
            }
        }
    }
    $bindSearch = static function ($stmt) use (&$patterns): void {
        if ($patterns) $stmt->bind_param(str_repeat('s', count($patterns)), ...$patterns);
    };

    $stmt = $conn->prepare("SELECT COUNT(*) AS total, ROUND(AVG(ap.appraisal_summary), 2) AS average_score,
          SUM(CASE WHEN ap.status = 'Pending' THEN 1 ELSE 0 END) AS pending_acknowledgements
          FROM appraisals ap INNER JOIN appraisal_cycles ac ON ac.id = ap.cycle_id
          WHERE {$where}{$searchClause}");
    $bindSearch($stmt);
    $stmt->execute();
    $totals = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    // Use appraisal CYCLE YEAR, not creation/import timestamp, for chronology.
    // Bound the tool response; if truncated, the assistant must disclose it.
    $stmt = $conn->prepare("SELECT ap.id, ap.staff_fullname, ap.staff_department, ap.cycle_id,
          ac.title AS cycle_title, ac.year AS cycle_year, ap.status, ap.appraisal_summary, ap.evaluation_statement,
          ap.created_at, ap.updated_at
          FROM appraisals ap INNER JOIN appraisal_cycles ac ON ac.id = ap.cycle_id
          WHERE {$where}{$searchClause} ORDER BY ac.year DESC, ap.id DESC LIMIT 30");
    $bindSearch($stmt);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $items = array_map(static function ($r) {
        return [
            'appraisal_id' => (int)$r['id'], 'employee' => $r['staff_fullname'],
            'department' => $r['staff_department'], 'cycle' => $r['cycle_title'],
            'year' => $r['cycle_year'], 'status' => $r['status'],
            'final_score' => $r['appraisal_summary'], 'evaluation' => $r['evaluation_statement'],
            'link' => '/appraisals/view/' . (int)$r['id'],
        ];
    }, $rows);
    return [
        'scope' => $scope, 'search' => $search,
        'match_note' => $matchNote,
        'year_from' => $yearFrom ?: null, 'year_to' => $yearTo ?: null,
        'total_existing_appraisals' => (int)($totals['total'] ?? 0),
        'average_final_score' => $totals['average_score'] ?? null,
        'pending_acknowledgements' => (int)($totals['pending_acknowledgements'] ?? 0),
        'items' => $items, 'truncated' => (int)($totals['total'] ?? 0) > count($items),
        'note' => 'Results are limited to authorized existing submissions, filtered by appraisal-cycle year (not creation date). Name tokens match in any order. Pending means awaiting employee acknowledgement. If truncated, do not claim these are all matching records.',
    ];
}

function aiReadDetails(mysqli $conn, array $user, int $companyId, array $args): array
{
    $id = filter_var($args['appraisal_id'] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || $id === null || $id <= 0) throw new Exception('A valid appraisal ID is required.', 422);
    $scope = $user['role_key'] === 'super_admin' ? 'visible' : 'mine';
    // Supervisors/admins may read their own, team and (for admins) other authorized records.
    $scopes = [$scope];
    if (aiReadScopeAllowed($user, 'team')) $scopes[] = 'team';
    if (aiReadScopeAllowed($user, 'visible')) $scopes[] = 'visible';
    $context = aiReadCompanyContext($user, $companyId);
    if (empty($context['is_home']) && ($context['access_level'] ?? '') !== 'full') $scopes = ['visible'];
    $conditions = [];
    foreach (array_unique($scopes) as $candidate) {
        $conditions[] = '(' . aiReadWhere($conn, $user, $companyId, $candidate) . ')';
    }
    $where = implode(' OR ', $conditions);
    $stmt = $conn->prepare("SELECT ap.id, ap.staff_fullname, ap.staff_department, ap.status,
        ap.appraisal_summary, ap.evaluation_statement, ac.title AS cycle_title, ac.year AS cycle_year
        FROM appraisals ap INNER JOIN appraisal_cycles ac ON ac.id = ap.cycle_id
        WHERE ap.id = ? AND ({$where}) LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$record) throw new Exception('Appraisal not found or not accessible.', 404);

    $stmt = $conn->prepare('SELECT section_label, section_avg, section_weight, weighted_score
          FROM appraisal_section_scores WHERE appraisal_id = ? ORDER BY id ASC LIMIT 15');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $sections = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return [
        'appraisal_id' => (int)$record['id'], 'employee' => $record['staff_fullname'],
        'department' => $record['staff_department'], 'cycle' => $record['cycle_title'],
        'year' => $record['cycle_year'], 'status' => $record['status'],
        'final_score' => $record['appraisal_summary'], 'evaluation' => $record['evaluation_statement'],
        'section_scores' => $sections, 'link' => '/appraisals/view/' . (int)$record['id'],
        'note' => 'The final score may include a management-reviewed HSE/integrity cap. Section scores show underlying ratings.',
    ];
}

function aiReadDispatch(mysqli $conn, array $user, ?int $selectedCompanyId, string $tool, array $args): array
{
    if (!in_array($tool, ['list_appraisals', 'get_appraisal', 'summarize_appraisals'], true)) {
        throw new Exception('This data action is unavailable.', 403);
    }
    $companyId = aiReadCompanyId($user, $selectedCompanyId);
    if ($tool === 'summarize_appraisals') {
        require_once __DIR__ . '/AiFeatureFlags.php';
        require_once __DIR__ . '/AiAnalytics.php';
        aiRequireFeature($conn, 'rankings');
        return aiReadSummary($conn, $user, $companyId, $args);
    }
    return $tool === 'list_appraisals'
        ? aiReadList($conn, $user, $companyId, $args)
        : aiReadDetails($conn, $user, $companyId, $args);
}

/** Totals and rankings for one cycle year, using the analytics engine (management only). */
function aiReadSummary(mysqli $conn, array $user, int $companyId, array $args): array
{
    $groupBy = is_string($args['group_by'] ?? null) ? $args['group_by'] : '';
    if (!in_array($groupBy, ['department', 'supervisor', 'level', 'band'], true)) throw new Exception('Invalid grouping.', 422);
    $year = filter_var($args['year'] ?? 0, FILTER_VALIDATE_INT);
    if ($year === false || ($year !== 0 && ($year < 1900 || $year > 2100))) throw new Exception('Invalid appraisal year.', 422);
    $cycles = aiAnalyticsCycles($conn, $companyId);
    $cycle = null;
    foreach ($cycles as $c) {
        if ($year === 0 ? true : $c['year'] === $year) { $cycle = $c; break; }
    }
    if (!$cycle) return ['error' => $year ? "There is no {$year} appraisal cycle." : 'There are no appraisal cycles yet.'];
    $result = aiAnalyticsRankings($conn, $user, $companyId, (int)$cycle['id'], $groupBy);
    $out = ['cycle' => $cycle['title'], 'year' => $cycle['year'], 'group_by' => $groupBy];
    if ($groupBy === 'band') {
        $out['distribution'] = $result['distribution'];
        $out['note'] = 'Shares are compared with the framework guideline ranges.';
    } else {
        $out['groups'] = array_map(static fn($r) => [
            'rank' => $r['rank'], 'name' => $r['label'], 'average_score' => $r['average'],
            'appraisals' => $r['appraisals'], 'people' => $r['people'],
            'outstanding_percent' => $r['outstanding_share'], 'awaiting_acknowledgement' => $r['pending'],
            'too_small_to_rank' => $r['small_group'],
        ], $result['rows']);
        $out['note'] = 'Groups with fewer than three people have no average and are not ranked. Do not guess their scores.';
    }
    return $out;
}

/** Only offer operations permitted for the current identity. */
function aiReadToolDefinitions(array $user, bool $withSummary = false): array
{
    $scopes = array_values(array_filter(['mine', 'team', 'visible'], static fn($s) => aiReadScopeAllowed($user, $s)));
    $tools = [];
    if ($withSummary && aiReadScopeAllowed($user, 'visible')) {
        $tools[] = [
            'type' => 'function', 'name' => 'summarize_appraisals',
            'description' => 'Totals, averages and rankings for one appraisal cycle year, grouped by department, supervisor, level, or rating band. Use for questions like which department scored highest, averages by department, or how many were rated Outstanding.',
            'strict' => true,
            'parameters' => [
                'type' => 'object', 'additionalProperties' => false,
                'properties' => [
                    'group_by' => ['type' => 'string', 'enum' => ['department', 'supervisor', 'level', 'band']],
                    'year' => ['type' => 'integer', 'description' => 'Cycle year, or 0 for the most recent cycle.'],
                ], 'required' => ['group_by', 'year'],
            ],
        ];
    }
    return array_merge($tools, [
        [
            'type' => 'function', 'name' => 'list_appraisals',
            'description' => 'Read existing submitted appraisals by employee/department tokens (any name order) and inclusive appraisal-cycle year range. Use 0 for all years and cycles unless time is requested. Not an employee directory.',
            'strict' => true,
            'parameters' => [
                'type' => 'object', 'additionalProperties' => false,
                'properties' => [
                    'scope' => ['type' => 'string', 'enum' => $scopes],
                    'cycle_id' => ['type' => 'integer', 'description' => 'Use 0 for all cycles, especially for multi-year requests.'],
                    'year_from' => ['type' => 'integer', 'description' => 'First inclusive cycle year, or 0 for all years.'],
                    'year_to' => ['type' => 'integer', 'description' => 'Last inclusive cycle year, or 0 for all years.'],
                    'search' => ['type' => 'string', 'description' => 'Optional employee name or department to filter (empty string for all).'],
                ], 'required' => ['scope', 'cycle_id', 'year_from', 'year_to', 'search'],
            ],
        ],
        [
            'type' => 'function', 'name' => 'get_appraisal',
            'description' => 'Read the overall and section scores for one existing appraisal by numeric ID. Only server-authorized records can be read.',
            'strict' => true,
            'parameters' => [
                'type' => 'object', 'additionalProperties' => false,
                'properties' => ['appraisal_id' => ['type' => 'integer']],
                'required' => ['appraisal_id'],
            ],
        ],
    ]);
}

/**
 * Words to match from a name search: lower-case, no possessives ("Nzekwue's"), no punctuation,
 * no titles or filler words ("score", "appraisal", "Mr"), at most six words.
 */
function aiReadNameTokens(string $search): array
{
    $text = function_exists('mb_strtolower') ? mb_strtolower($search, 'UTF-8') : strtolower($search);
    $text = str_replace(["\u{2019}", "\u{2018}", '`'], "'", $text);
    $text = preg_replace("/'s\\b|s'(?=\\s|$)/u", ' ', $text) ?? $text;          // possessives
    $text = preg_replace("/[^\\p{L}\\p{N}'\\-]+/u", ' ', $text) ?? $text;      // punctuation
    $stop = ['mr', 'mrs', 'ms', 'miss', 'dr', 'engr', 'eng', 'prof', 'sir', 'madam', 'the', 'and', 'for', 'of',
        'score', 'scores', 'appraisal', 'appraisals', 'rating', 'ratings', 'result', 'results', 'staff', 'employee', 'record', 'records'];
    $tokens = [];
    foreach (preg_split('/\\s+/u', trim($text)) ?: [] as $token) {
        $token = trim($token, "'-");
        $length = function_exists('mb_strlen') ? mb_strlen($token) : strlen($token);
        if ($length < 2 || in_array($token, $stop, true)) continue;
        $tokens[$token] = true;
    }
    return array_slice(array_keys($tokens), 0, 6);
}

/** Does a search word match a name part, allowing small spelling differences? */
function aiReadTokenNear(string $token, string $part): bool
{
    if ($part === $token || str_starts_with($part, $token) || str_starts_with($token, $part) && strlen($part) >= 4) return true;
    $limit = strlen($token) >= 8 ? 2 : (strlen($token) >= 4 ? 1 : 0);
    return $limit > 0 && abs(strlen($part) - strlen($token)) <= $limit && levenshtein($token, $part) <= $limit;
}

/**
 * Closest-spelling fallback, limited to appraisals this user may already see ($where).
 * Every search word must be near some part of the name. Returns [[staff ids], [display names]].
 */
function aiReadClosestPeople(mysqli $conn, string $where, array $tokens): array
{
    $stmt = $conn->prepare("SELECT ap.staff_user_id, MAX(ap.staff_fullname) AS name FROM appraisals ap
        INNER JOIN appraisal_cycles ac ON ac.id = ap.cycle_id WHERE {$where} GROUP BY ap.staff_user_id LIMIT 3000");
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $ids = [];
    $names = [];
    foreach ($rows as $row) {
        $name = function_exists('mb_strtolower') ? mb_strtolower((string)$row['name'], 'UTF-8') : strtolower((string)$row['name']);
        $parts = array_values(array_filter(preg_split("/[^\\p{L}\\p{N}]+/u", $name) ?: []));
        $all = true;
        foreach ($tokens as $token) {
            $hit = false;
            foreach ($parts as $part) if (aiReadTokenNear($token, $part)) { $hit = true; break; }
            if (!$hit) { $all = false; break; }
        }
        if ($all) {
            $ids[] = (int)$row['staff_user_id'];
            $names[] = (string)$row['name'];
        }
        if (count($ids) >= 5) break;
    }
    return [$ids, $names];
}

