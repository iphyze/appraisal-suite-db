<?php
require_once __DIR__ . '/../../includes/ContentTranslationPeople.php';

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/CompanySupervisorRead.php';
require_once 'includes/CompanyReadQueries.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Bad Request: Only GET method is allowed", 400);
    }

    $userData     = authenticateUser();
    $actingContext = authRoleKey($userData['role'] ?? '') === 'admin' ? resolveActingCompanyContext($userData) : null;
    $companyScope = $actingContext !== null ? $actingContext['company_id'] : resolveCompanyScope($userData);
    $clause       = buildCompanyWhereClause($companyScope, 'u');

    $search     = isset($_GET['search'])     ? trim($_GET['search'])    : '';
    $department = isset($_GET['department']) ? trim($_GET['department']) : null;
    $cycleId    = isset($_GET['cycle_id'])   ? (int) $_GET['cycle_id'] : 0;
    $limit      = isset($_GET['limit'])      ? (int) $_GET['limit'] : 500;
    $limit      = max(50, min($limit, 1000));

    $staffScopeFilter = null;
    if (($userData['role'] ?? '') === 'admin') {
        $adminScope = $userData['staff_scope'] ?? 'All';
        if ($adminScope !== 'All') $staffScopeFilter = $adminScope;
    }

    $conditions = [appraiserRoleWhere('r', 'u'), "u.is_active = 1"];
    $params     = [];
    $types      = "";

    if ($clause['value'] !== null) {
        $conditions[] = "u.company_id = ?";
        $params[]     = $clause['value'];
        $types       .= "i";
    }
    if ($department) { $conditions[] = "u.department = ?"; $params[] = $department; $types .= "s"; }
    if ($search !== '') {
        $conditions[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.department LIKE ? OR u.job_title LIKE ?)";
        $like = "%" . $search . "%";
        $params = array_merge($params, [$like, $like, $like, $like, $like]);
        $types .= "sssss";
    }

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

    if ($cycleId > 0 && $companyScope !== null) {
        $cycleCheck = $conn->prepare('SELECT id FROM appraisal_cycles WHERE id = ? AND company_id = ? LIMIT 1');
        if (!$cycleCheck) throw new Exception('Unable to validate appraisal cycle.', 500);
        $cycleCheck->bind_param('ii', $cycleId, $companyScope);
        $cycleCheck->execute();
        $validCycle = $cycleCheck->get_result()->fetch_assoc();
        $cycleCheck->close();
        if (!$validCycle) throw new Exception('Selected appraisal cycle is outside your company scope.', 404);
    }

    $cycleAssignWhere = $cycleId > 0 ? "WHERE sa.cycle_id = {$cycleId}" : "WHERE 1 = 1";
    $cycleAppWhere = $cycleId > 0 ? "WHERE a.cycle_id = {$cycleId}" : "WHERE 1 = 1";

    $sql = "
        SELECT
            u.id,
            r.name AS role_name,
            u.is_supervisor,
            u.first_name,
            u.last_name,
            u.fullname,
            u.email,
            u.department,
            u.job_title,
            u.staff_type,
            c.id   AS company_id,
            c.code AS company_code,
            c.name AS company_name,
            COALESCE(ass.assigned_count, 0) AS assigned_count,
            COALESCE(ass.assigned_count, 0) AS total_subordinates,
            COALESCE(app.appraised_count, 0) AS appraised_count,
            GREATEST(COALESCE(ass.assigned_count, 0) - COALESCE(app.appraised_count, 0), 0) AS pending_count,
            CASE WHEN COALESCE(ass.assigned_count, 0) = 0 THEN 0 ELSE ROUND((COALESCE(app.appraised_count, 0) / COALESCE(ass.assigned_count, 0)) * 100, 1) END AS progress_percent
        FROM users u
        INNER JOIN roles r     ON r.id = u.role_id
        INNER JOIN companies c ON c.id = u.company_id
        LEFT JOIN (
            SELECT supervisor_id, COUNT(*) AS assigned_count
            FROM supervisor_assignments sa
            {$aggregateAssignmentJoin}
            {$cycleAssignWhere}
            {$aggregateAssignmentWhere}
            GROUP BY supervisor_id
        ) ass ON ass.supervisor_id = u.id
        LEFT JOIN (
            SELECT supervisor_id, COUNT(DISTINCT staff_user_id) AS appraised_count
            FROM appraisals a
            {$aggregateAppraisalJoin}
            {$cycleAppWhere}
            {$aggregateAppraisalWhere}
            GROUP BY supervisor_id
        ) app ON app.supervisor_id = u.id
        WHERE " . implode(" AND ", $conditions) . "
        ORDER BY u.first_name ASC, u.last_name ASC
        LIMIT {$limit}
    ";

    // Appraisee filters occur before the directory search parameters.
    $data = companyReadFetchAll($conn, $sql, $aggregateTypes . $types,
        array_merge($aggregateParams, $params));

    http_response_code(200);
    if (is_array($data)) contentTranslationAddJobTitleDisplays($conn, $data, $userData);
    echo json_encode([
        "status"  => "Success",
        "message" => "Supervisors fetched successfully",
        "data"    => $data,
        "meta"    => ["count" => count($data), "search" => $search ?: null, "department" => $department, "cycle_id" => $cycleId]
    ]);
} catch (Exception $e) {
    error_log("SearchSupervisors Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
