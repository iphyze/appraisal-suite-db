<?php
require_once __DIR__ . '/../../includes/ContentTranslationPeople.php';

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/CompanySupervisorRead.php';
require_once 'includes/CompanyReadQueries.php';

header('Content-Type: application/json');

function jsonResponse($status, $message, $data = [], $meta = [], $code = 200)
{
    // Routes run at the top level of index.php, so $conn and $userData are globals here.
    if ($code >= 200 && $code < 300 && is_array($data)
        && ($GLOBALS['conn'] ?? null) instanceof mysqli && is_array($GLOBALS['userData'] ?? null)) {
        contentTranslationAddJobTitleDisplays($GLOBALS['conn'], $data, $GLOBALS['userData']);
    }
    http_response_code($code);
    echo json_encode([
        'status'  => $status,
        'message' => $message,
        'data'    => $data,
        'meta'    => $meta,
    ]);
    exit;
}

function esc($conn, $value)
{
    return $conn->real_escape_string(trim((string) $value));
}

function fetchOneRaw($conn, $sql)
{
    $result = $conn->query($sql);
    if (!$result) throw new Exception('Database error: ' . $conn->error, 500);
    return $result->fetch_assoc();
}

function fetchAllRaw($conn, $sql)
{
    $result = $conn->query($sql);
    if (!$result) throw new Exception('Database error: ' . $conn->error, 500);
    $rows = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    return $rows;
}

function roleKeySql($alias)
{
    return "LOWER(REPLACE(TRIM({$alias}.name), ' ', '_'))";
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Bad Request: Only GET method is allowed', 405);
    }

    $userData = requireRoles(['super_admin', 'admin']);

    $loggedRole      = $userData['role'] ?? '';
    $loggedRoleKey   = strtolower(str_replace(' ', '_', trim((string) $loggedRole)));
    $loggedCompanyId = isset($userData['company_id']) ? (int) $userData['company_id'] : 0;

    $cycleId          = isset($_GET['cycle_id']) ? (int) $_GET['cycle_id'] : 0;
    $search           = esc($conn, $_GET['search'] ?? '');
    $department       = esc($conn, $_GET['department'] ?? '');
    $staffType        = esc($conn, $_GET['staff_type'] ?? '');
    $isActive         = isset($_GET['is_active']) && $_GET['is_active'] !== '' ? (int) $_GET['is_active'] : null;
    $onboardingStatus = esc($conn, $_GET['onboarding_status'] ?? '');

    $page  = isset($_GET['page']) ? max((int) $_GET['page'], 1) : 1;
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
    $limit = in_array($limit, [10, 20, 50, 100, 500], true) ? $limit : 10;
    $offset = ($page - 1) * $limit;

    $allowedSort = [
        'first_name' => 'u.first_name',
        'last_name'  => 'u.last_name',
        'department' => 'u.department',
        'staff_type' => 'u.staff_type',
        'job_title'  => 'u.job_title',
        'email'      => 'u.email',
    ];

    $sortBy = $_GET['sortBy'] ?? 'first_name';
    $sortColumn = $allowedSort[$sortBy] ?? 'u.first_name';
    $sortOrder = strtoupper($_GET['sortOrder'] ?? 'ASC');
    $sortOrder = $sortOrder === 'DESC' ? 'DESC' : 'ASC';

    $cycle = null;
    $actingContext = $loggedRoleKey === 'admin' ? resolveActingCompanyContext($userData) : null;
    $companyScope = $actingContext !== null ? $actingContext['company_id'] : resolveCompanyScope($userData);
    $scopeCompanyId = $companyScope === null ? 0 : (int) $companyScope;

    if ($cycleId > 0) {
        $cycleCompanySql = $companyScope !== null ? " AND company_id = " . (int) $companyScope : '';
        $cycle = fetchOneRaw($conn, "
            SELECT id, company_id, title, year, is_active
            FROM appraisal_cycles
            WHERE id = {$cycleId}
            {$cycleCompanySql}
            LIMIT 1
        ");

        if (!$cycle) throw new Exception('Selected appraisal cycle was not found or is outside your company scope.', 404);
        $scopeCompanyId = (int) $cycle['company_id'];
    }

    $where = [appraiserRoleWhere('r', 'u')];

    if ($scopeCompanyId > 0) $where[] = "u.company_id = {$scopeCompanyId}";
    if ($isActive !== null) $where[] = "u.is_active = {$isActive}";

    if ($search !== '') {
        $where[] = "(
            u.first_name LIKE '%{$search}%'
            OR u.last_name LIKE '%{$search}%'
            OR u.email LIKE '%{$search}%'
            OR u.staff_id LIKE '%{$search}%'
            OR u.unique_ref LIKE '%{$search}%'
            OR u.department LIKE '%{$search}%'
            OR u.job_title LIKE '%{$search}%'
        )";
    }

    if ($department !== '') $where[] = "u.department = '{$department}'";
    if ($staffType !== '') $where[] = "u.staff_type = '{$staffType}'";

    $cycleJoinFilter = $cycleId > 0 ? "AND sa.cycle_id = {$cycleId}" : '';
    $appraisalJoinFilter = $cycleId > 0 ? "AND a.cycle_id = {$cycleId}" : '';
    $onboardWhere = $cycleId > 0 ? "WHERE cycle_id = {$cycleId}" : '';
    // Only the aggregates inspect appraisees; supervisor names remain directory data.
    $aggregateAssignmentJoin = '';
    $aggregateAppraisalJoin = '';
    $aggregateAssignmentWhere = '';
    $aggregateAppraisalWhere = '';
    $aggregateTypes = '';
    $aggregateParams = [];
    if ($actingContext !== null && empty($actingContext['is_home'])) {
        $assignedFilter = companySupervisorAppraiseeWhere($actingContext, (int)$userData['id'], 'assigned_staff', 'assigned_role');
        $appraisedFilter = companySupervisorAppraisalWhere($actingContext, (int)$userData['id'], 'a', 'appraised_staff', 'appraised_role');
        $aggregateAssignmentJoin = ' INNER JOIN users assigned_staff ON assigned_staff.id = sa.staff_id'
            . ' INNER JOIN roles assigned_role ON assigned_role.id = assigned_staff.role_id';
        $aggregateAppraisalJoin = ' INNER JOIN users appraised_staff ON appraised_staff.id = a.staff_user_id'
            . ' INNER JOIN roles appraised_role ON appraised_role.id = appraised_staff.role_id';
        $aggregateAssignmentWhere = ' AND assigned_staff.company_id = ' . (int)$companyScope . $assignedFilter['sql'];
        $aggregateAppraisalWhere = ' AND a.company_id = ' . (int)$companyScope . $appraisedFilter['sql'];
        $aggregateTypes = $assignedFilter['types'] . $appraisedFilter['types'];
        $aggregateParams = array_merge($assignedFilter['params'], $appraisedFilter['params']);
    }

    $baseFrom = "
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        LEFT JOIN companies c ON c.id = u.company_id
        LEFT JOIN (
            SELECT
                supervisor_id,
                MAX(id) AS id,
                MAX(onboarded_at) AS onboarded_at
            FROM supervisor_onboarding
            {$onboardWhere}
            GROUP BY supervisor_id
        ) onboard ON onboard.supervisor_id = u.id
        LEFT JOIN (
            SELECT supervisor_id, COUNT(*) AS assigned_count
            FROM supervisor_assignments sa
            {$aggregateAssignmentJoin}
            WHERE 1 = 1 {$cycleJoinFilter} {$aggregateAssignmentWhere}
            GROUP BY supervisor_id
        ) ass ON ass.supervisor_id = u.id
        LEFT JOIN (
            SELECT supervisor_id, COUNT(DISTINCT staff_user_id) AS appraised_count
            FROM appraisals a
            {$aggregateAppraisalJoin}
            WHERE 1 = 1 {$appraisalJoinFilter} {$aggregateAppraisalWhere}
            GROUP BY supervisor_id
        ) app ON app.supervisor_id = u.id
    ";

    if ($onboardingStatus === 'onboarded') {
        $where[] = "onboard.id IS NOT NULL";
    } elseif ($onboardingStatus === 'not_onboarded') {
        $where[] = "onboard.id IS NULL";
    }

    $whereSql = implode(' AND ', $where);

    // Department filter choices are intentionally resolved from the complete
    // scoped supervisor dataset rather than from the current paginated page.
    // This keeps searchable selects complete regardless of the selected row limit.
    $departmentWhere = [appraiserRoleWhere('r', 'u')];
    if ($scopeCompanyId > 0) $departmentWhere[] = "u.company_id = {$scopeCompanyId}";
    if ($isActive !== null) $departmentWhere[] = "u.is_active = {$isActive}";
    $departmentWhere[] = "u.department IS NOT NULL";
    $departmentWhere[] = "TRIM(u.department) <> ''";
    $departmentWhereSql = implode(' AND ', $departmentWhere);

    $departmentRows = fetchAllRaw($conn, "
        SELECT DISTINCT TRIM(u.department) AS department
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        WHERE {$departmentWhereSql}
        ORDER BY department ASC
    ");
    $departmentOptions = array_values(array_filter(array_map(
        static fn($row) => $row['department'] ?? null,
        $departmentRows
    )));

    $countRow = companyReadFetchOne($conn, "
        SELECT COUNT(*) AS total
        {$baseFrom}
        WHERE {$whereSql}
    ", $aggregateTypes, $aggregateParams);

    $total = (int) ($countRow['total'] ?? 0);
    $totalPages = max((int) ceil($total / $limit), 1);
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $limit;
    }

    $rows = companyReadFetchAll($conn, "
        SELECT
            u.id,
            u.company_id,
            c.code AS company_code,
            c.name AS company_name,
            u.role_id,
            r.name AS role_name,
            u.is_supervisor,
            u.staff_id,
            u.first_name,
            u.last_name,
            u.fullname,
            u.username,
            u.email,
            u.staff_scope,
            u.department,
            u.job_title,
            u.staff_type,
            u.location,
            u.date_of_joining,
            u.unique_ref,
            u.is_active,
            u.onboarded_at,
            u.last_login_at,
            COALESCE(ass.assigned_count, 0) AS assigned_count,
            COALESCE(ass.assigned_count, 0) AS total_subordinates,
            COALESCE(app.appraised_count, 0) AS appraised_count,
            GREATEST(COALESCE(ass.assigned_count, 0) - COALESCE(app.appraised_count, 0), 0) AS pending_count,
            CASE
                WHEN COALESCE(ass.assigned_count, 0) = 0 THEN 0
                ELSE ROUND((COALESCE(app.appraised_count, 0) / COALESCE(ass.assigned_count, 0)) * 100, 1)
            END AS progress_percent,
            CASE WHEN onboard.id IS NULL THEN 0 ELSE 1 END AS is_onboarded,
            onboard.onboarded_at AS cycle_onboarded_at
        {$baseFrom}
        WHERE {$whereSql}
        ORDER BY {$sortColumn} {$sortOrder}, u.id ASC
        LIMIT {$limit} OFFSET {$offset}
    ", $aggregateTypes, $aggregateParams);

    $stats = companyReadFetchOne($conn, "
        SELECT
            COUNT(*) AS total_supervisors,
            COALESCE(SUM(CASE WHEN onboard.id IS NULL THEN 0 ELSE 1 END), 0) AS onboarded_count,
            COALESCE(SUM(COALESCE(ass.assigned_count, 0)), 0) AS assigned_count,
            COALESCE(SUM(COALESCE(app.appraised_count, 0)), 0) AS appraised_count
        {$baseFrom}
        WHERE {$whereSql}
    ", $aggregateTypes, $aggregateParams) ?: [];

    $assignedCount = (int) ($stats['assigned_count'] ?? 0);
    $appraisedCount = (int) ($stats['appraised_count'] ?? 0);

    $meta = [
        'total'             => $total,
        'page'              => $page,
        'limit'             => $limit,
        'total_pages'       => $totalPages,
        'total_supervisors' => (int) ($stats['total_supervisors'] ?? 0),
        'onboarded_count'   => (int) ($stats['onboarded_count'] ?? 0),
        'assigned_count'    => $assignedCount,
        'appraised_count'   => $appraisedCount,
        'pending_count'     => max($assignedCount - $appraisedCount, 0),
        'progress_percent'  => $assignedCount > 0 ? round(($appraisedCount / $assignedCount) * 100, 1) : 0,
        'cycle'             => $cycle ? [
            'id' => (int) $cycle['id'],
            'title' => $cycle['title'],
            'year' => $cycle['year'],
            'is_active' => (int) $cycle['is_active'],
        ] : null,
        'filter_options'    => [
            'departments' => $departmentOptions,
        ],
    ];

    jsonResponse('Success', 'Supervisors fetched successfully.', $rows, $meta);
} catch (Exception $e) {
    $code = $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    jsonResponse('Failed', $e->getMessage(), [], [], $code);
}
