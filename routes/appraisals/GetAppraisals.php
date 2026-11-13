<?php
require_once __DIR__ . '/../../includes/ContentTranslationPeople.php';

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/AdminStaffScope.php';
require_once 'includes/AppraisalUpdateEligibility.php';
require_once __DIR__ . '/AppraisalHelpers.php';
require_once 'includes/AdminAppraisalVisibility.php';
require_once 'includes/CompanyAccess.php';
require_once 'includes/CompanyReadQueries.php';
require_once 'includes/ContentTranslationAppraisal.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Exception('Bad Request: Only GET method is allowed', 405);

    $userData = authenticateUser();
    $loggedInUserId = (int)$userData['id'];
    $loggedInRole = $userData['role'] ?? '';
    $loggedInRoleKey = strtolower(str_replace(' ', '_', trim((string)$loggedInRole)));
    $loggedInCompanyId = isset($userData['company_id']) ? (int)$userData['company_id'] : 0;

    $cycleId = isset($_GET['cycle_id']) ? (int)$_GET['cycle_id'] : 0;
    $supervisorId = isset($_GET['supervisor_id']) ? (int)$_GET['supervisor_id'] : 0;
    $staffUserId = isset($_GET['staff_user_id']) ? (int)$_GET['staff_user_id'] : 0;
    $department = apEsc($conn, $_GET['department'] ?? '');
    $staffType = apEsc($conn, $_GET['staff_type'] ?? '');
    $status = apEsc($conn, $_GET['status'] ?? '');
    $search = apEsc($conn, $_GET['search'] ?? '');
    $scope = strtolower(trim((string)($_GET['scope'] ?? '')));

    $page = isset($_GET['page']) ? max((int)$_GET['page'], 1) : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $limit = in_array($limit, [10,20,50,100], true) ? $limit : 10;
    $offset = ($page - 1) * $limit;

    $allowedSort = ['staff_fullname' => 'ap.staff_fullname', 'appraisal_summary' => 'ap.appraisal_summary', 'kpi_rating' => 'ap.kpi_rating', 'created_at' => 'ap.created_at', 'status' => 'ap.status'];
    $sortBy = $_GET['sortBy'] ?? 'created_at';
    $sortColumn = $allowedSort[$sortBy] ?? 'ap.created_at';
    $sortOrder = strtoupper($_GET['sortOrder'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

    $where = ['1=1'];
    $isOwnScope = in_array($scope, ['my', 'own', 'self'], true);
    // My Appraisals is a personal record operation, never a company view.
    $companyScope = $loggedInRoleKey === 'super_admin' ? resolveCompanyScope($userData) : null;
    $actingContext = ($loggedInRoleKey === 'admin' && !$isOwnScope)
        ? resolveActingCompanyContext($userData) : null;
    $grantBindTypes = '';
    $grantBindParams = [];

    if ($loggedInRoleKey === 'super_admin' && $isOwnScope) {
        throw new Exception('Super administrators do not have personal appraisals.', 403);
    }

    if ($loggedInRoleKey === 'super_admin') {
        // Super administrators manage reporting only and are not appraisal subjects.
        if ($companyScope !== null) $where[] = "ap.company_id = " . (int) $companyScope;
    } elseif ($isOwnScope) {
        // Staff, supervisors and administrators can all be appraisal subjects.
        $where[] = "ap.staff_user_id = {$loggedInUserId}";
    } elseif ($loggedInRoleKey === 'staff') {
        $where[] = "ap.staff_user_id = {$loggedInUserId}";
    } elseif ($loggedInRoleKey === 'supervisor') {
        $where[] = "ap.supervisor_id = {$loggedInUserId}";
    } elseif ($loggedInRoleKey === 'admin') {
        $actingCompanyId = (int) $actingContext['company_id'];
        $where[] = "ap.company_id = {$actingCompanyId}";
        // Policies are company-specific; no policy in the granted company means 'none'.
        $where[] = adminAppraisalVisibilityWhereSql($loggedInUserId, $actingCompanyId, 'ap', !empty($actingContext['is_home']));
        if (empty($actingContext['is_home'])) {
            // Every appraisee, including admins and supervisors, is in the grant's strict scope.
            $grantFilter = companyAccessAppraiseeScopeWhere($actingContext, 'ap');
            if ($grantFilter['sql'] !== '') {
                $where[] = trim(substr($grantFilter['sql'], 5)); // strip leading ' AND '
                $grantBindTypes = $grantFilter['type'];
                $grantBindParams[] = $grantFilter['value'];
            }
        } else {
            $where[] = staffScopeHomeAppraisalSql($userData, 'ap');
        }
    }

    if ($cycleId > 0) $where[] = "ap.cycle_id = {$cycleId}";
    if ($supervisorId > 0 && in_array($loggedInRoleKey, ['super_admin','admin'], true)) $where[] = "ap.supervisor_id = {$supervisorId}";
    if ($staffUserId > 0 && in_array($loggedInRoleKey, ['super_admin','admin','supervisor'], true)) $where[] = "ap.staff_user_id = {$staffUserId}";
    if ($department !== '') $where[] = "ap.staff_department = '{$department}'";
    if ($staffType !== '') $where[] = "ap.staff_type = '{$staffType}'";
    if (in_array($status, ['Pending','Acknowledged'], true)) $where[] = "ap.status = '{$status}'";
    if ($search !== '') $where[] = "(ap.staff_fullname LIKE '%{$search}%' OR ap.staff_email LIKE '%{$search}%' OR ap.staff_department LIKE '%{$search}%' OR ap.staff_job_title LIKE '%{$search}%' OR ac.title LIKE '%{$search}%' OR ac.year LIKE '%{$search}%' OR sup.first_name LIKE '%{$search}%' OR sup.last_name LIKE '%{$search}%')";

    $whereSql = implode(' AND ', $where);
    $baseFrom = "
        FROM appraisals ap
        INNER JOIN appraisal_cycles ac ON ac.id = ap.cycle_id
        INNER JOIN companies c ON c.id = ap.company_id
        LEFT JOIN users sup ON sup.id = ap.supervisor_id
        WHERE {$whereSql}
    ";

    $count = companyReadFetchOne($conn, "SELECT COUNT(*) AS total {$baseFrom}", $grantBindTypes, $grantBindParams);
    $total = (int)($count['total'] ?? 0);
    $totalPages = max((int)ceil($total / $limit), 1);
    if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $limit; }

    $rows = companyReadFetchAll($conn, "
        SELECT
            ap.id, ap.company_id, ap.cycle_id, ap.staff_user_id, ap.supervisor_id,
            ap.staff_fullname, ap.staff_department, ap.staff_location, ap.staff_job_title,
            ap.staff_type, ap.staff_email, ap.kpi_rating, ap.appraisal_summary,
            ap.evaluation_statement, ap.status, ap.feedback, ap.edited_count, ap.update_count,
            ap.extra_update_allowance, ap.objectives_changed_at,
            ap.created_at, ap.updated_at,
            CASE
                WHEN ap.rating_config_snapshot IS NOT NULL AND JSON_VALID(ap.rating_config_snapshot)
                THEN COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(ap.rating_config_snapshot, '$.rating_max')) AS DECIMAL(8,2)), 5.00)
                ELSE 5.00
            END AS rating_max,
            ac.year AS cycle_year, ac.title AS cycle_title,
            c.name AS company_name, c.code AS company_code,
            TRIM(CONCAT(COALESCE(sup.first_name,''), ' ', COALESCE(sup.last_name,''))) AS supervisor_name,
            sup.email AS supervisor_email
        {$baseFrom}
        ORDER BY {$sortColumn} {$sortOrder}, ap.id DESC
        LIMIT {$limit} OFFSET {$offset}
    ", $grantBindTypes, $grantBindParams);

    foreach ($rows as &$appraisalRow) {
        $permission = apCanSupervisorUpdateAppraisal($conn, $userData, null, $appraisalRow);
        $appraisalRow['can_update_appraisal'] = $permission['allowed'] ? 1 : 0;
        $appraisalRow['can_adjust_objectives'] = $permission['allowed'] ? 1 : 0;
        $appraisalRow['updates_left'] = $permission['updates_left'];
        $appraisalRow['reason_key'] = $permission['reason_key'];
        $appraisalRow['objectives_changed_since_submission'] = !empty($appraisalRow['objectives_changed_at']) ? 1 : 0;
    }
    unset($appraisalRow);

    $stats = companyReadFetchOne($conn, "
        SELECT
            COUNT(*) AS total_appraisals,
            COALESCE(SUM(CASE WHEN ap.status = 'Pending' THEN 1 ELSE 0 END), 0) AS pending_count,
            COALESCE(SUM(CASE WHEN ap.status = 'Acknowledged' THEN 1 ELSE 0 END), 0) AS acknowledged_count,
            ROUND(AVG(ap.appraisal_summary), 2) AS avg_score
        {$baseFrom}
    ", $grantBindTypes, $grantBindParams) ?: [];

    // Translate only the already-authorised page; never the count/stats SQL.
    $localizedPage = ['rows' => $rows];
    contentTranslationApplyPaths($conn, $localizedPage, $userData, contentTranslationAppraisalListPaths());
    contentTranslationAddJobTitleDisplays($conn, $localizedPage, $userData);
    $rows = $localizedPage['rows'];

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Appraisals fetched successfully',
        'data' => $rows,
        'meta' => [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => $totalPages,
            'stats' => $stats,
            'sortBy' => $sortBy,
            'sortOrder' => $sortOrder,
        ],
    ]);

} catch (Exception $e) {
    $code = $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($code);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
