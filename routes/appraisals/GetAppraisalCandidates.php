<?php
require_once __DIR__ . '/../../includes/ContentTranslationPeople.php';

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/AdminAppraisalVisibility.php';
require_once 'includes/CompanyCandidateRead.php';
require_once 'includes/CompanyReadQueries.php';
require_once 'includes/ContentTranslationAppraisal.php';

header('Content-Type: application/json');

function jsonResponse($status, $message, $data = [], $meta = [], $code = 200) {
    http_response_code($code);
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data, 'meta' => $meta]);
    exit;
}

function esc($conn, $value) { return $conn->real_escape_string(trim((string)$value)); }
function fetchOneRaw($conn, $sql) { $r = $conn->query($sql); if (!$r) throw new Exception('Database error: '.$conn->error, 500); return $r->fetch_assoc(); }
function fetchAllRaw($conn, $sql) { $r = $conn->query($sql); if (!$r) throw new Exception('Database error: '.$conn->error, 500); $rows=[]; while($row=$r->fetch_assoc()) $rows[]=$row; return $rows; }
function roleKeySql($alias) { return "LOWER(REPLACE(TRIM({$alias}.name), ' ', '_'))"; }

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Exception('Bad Request: Only GET method is allowed', 405);

    $userData = requireRoles(['super_admin', 'admin', 'supervisor']);
    $loggedInUserId = (int)$userData['id'];
    $loggedInRole = $userData['role'] ?? '';
    $loggedInRoleKey = strtolower(str_replace(' ', '_', trim((string)$loggedInRole)));
    $loggedInCompanyId = isset($userData['company_id']) ? (int)$userData['company_id'] : 0;
    $loggedInCanAppraise = userCanConductAppraisals($userData);

    $cycleId = isset($_GET['cycle_id']) ? (int)$_GET['cycle_id'] : 0;
    $supervisorId = isset($_GET['supervisor_id']) ? (int)$_GET['supervisor_id'] : 0;
    $scope = trim((string) ($_GET['scope'] ?? ''));
    if (!in_array($scope, ['', 'my_team'], true)) throw new Exception('Invalid appraisal scope.', 400);
    if ($scope === 'my_team') {
        if (!userCanConductAppraisals($userData)) throw new Exception('Supervisor access is required for this view.', 403);
        $supervisorId = $loggedInUserId;
    }
    if ($loggedInRoleKey === 'supervisor') $supervisorId = $loggedInUserId;

    // My Team is a personal supervisor read. Administrative candidate lists use
    // live acting-company grants and a distinct appraisee visibility predicate.
    $candidateContext = companyCandidateActingContext($userData, $scope);
    $candidateCanManage = companyCandidateAdminCanManage($candidateContext);
    $companyScope = $candidateContext !== null
        ? $candidateContext['company_id'] : resolveCompanyScope($userData);

    $focusedStaffId = isset($_GET['staff_user_id']) ? filter_var($_GET['staff_user_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
    if (isset($_GET['staff_user_id']) && $focusedStaffId === false) {
        throw new Exception('Invalid staff ID filter.', 400);
    }

    $search = esc($conn, $_GET['search'] ?? '');
    $department = esc($conn, $_GET['department'] ?? '');
    $staffType = esc($conn, $_GET['staff_type'] ?? '');
    $status = esc($conn, $_GET['status'] ?? 'all');
    $managementReviewStatus = strtolower(esc($conn, $_GET['management_review_status'] ?? 'all'));
    if (!in_array($managementReviewStatus, ['all','unreviewed','reviewed','follow_up'], true)) {
        throw new Exception('Invalid management review status filter.', 400);
    }

    $page = isset($_GET['page']) ? max((int)$_GET['page'], 1) : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $limit = in_array($limit, [10,20,50,100], true) ? $limit : 10;
    $offset = ($page - 1) * $limit;

    $emptyMeta = [
        'total' => 0, 'page' => $page, 'limit' => $limit, 'total_pages' => 1,
        'assigned_count' => 0, 'appraised_count' => 0, 'pending_count' => 0,
        'progress_percent' => 0, 'review_unreviewed_count' => 0, 'reviewed_count' => 0, 'follow_up_count' => 0, 'management_review_enabled' => 0, 'cycle' => null, 'supervisor' => null,
    ];

    if ($cycleId <= 0) {
        jsonResponse('Success', 'Select a cycle to load appraisal candidates.', [], $emptyMeta);
    }
    $allSupervisors = $supervisorId <= 0 && in_array($loggedInRoleKey, ['super_admin', 'admin'], true);

    $cycleCompanySql = $companyScope !== null ? " AND company_id = " . (int) $companyScope : '';
    $cycle = fetchOneRaw($conn, "
        SELECT id, company_id, year, title, is_active
        FROM appraisal_cycles
        WHERE id = {$cycleId} {$cycleCompanySql}
        LIMIT 1
    ");
    if (!$cycle) throw new Exception('Selected appraisal cycle was not found or is outside your company scope.', 404);
    $companyId = (int)$cycle['company_id'];
    $cycleScoringSettings = fetchOneRaw($conn, "SELECT require_explicit_profile, management_review_enabled FROM appraisal_cycle_settings WHERE company_id={$companyId} AND cycle_id={$cycleId} LIMIT 1");
    $managementReviewEnabled = (int)($cycleScoringSettings['management_review_enabled'] ?? 0);

    $supervisor = null;
    if (!$allSupervisors) {
        $supervisor = fetchOneRaw($conn, "
            SELECT u.id, u.first_name, u.last_name, u.email, u.department, u.job_title, u.staff_type, r.name AS role_name
            FROM users u
            INNER JOIN roles r ON r.id = u.role_id
            WHERE u.id = {$supervisorId}
              AND " . appraiserRoleWhere('r', 'u') . "
              AND u.company_id = {$companyId}
              AND u.is_active = 1
            LIMIT 1
        " );
        if (!$supervisor) throw new Exception('Selected supervisor was not found for this appraisal cycle.', 404);
    }

    $where = ["sa.cycle_id = {$cycleId}", "s.company_id = {$companyId}", "s.is_active = 1", appraiseeRoleWhere('sr')];
    $candidateVisibility = companyCandidateAppraiseeWhere($userData, $candidateContext, $cycleId);
    if ($candidateVisibility['sql'] !== '') {
        $where[] = $candidateVisibility['sql'];
    }
    if (!$allSupervisors) $where[] = "sa.supervisor_id = {$supervisorId}";
    // Filter after all assignment, company and appraisee visibility predicates.
    if ($focusedStaffId !== null) $where[] = 's.id = ' . (int)$focusedStaffId;
    if ($search !== '') {
        $where[] = "(s.first_name LIKE '%{$search}%' OR s.last_name LIKE '%{$search}%' OR s.fullname LIKE '%{$search}%' OR s.email LIKE '%{$search}%' OR s.staff_id LIKE '%{$search}%' OR s.unique_ref LIKE '%{$search}%' OR s.department LIKE '%{$search}%' OR s.job_title LIKE '%{$search}%')";
    }
    if ($department !== '') $where[] = "s.department = '{$department}'";
    if ($staffType !== '') $where[] = "s.staff_type = '{$staffType}'";
    if ($status === 'pending') $where[] = "ap.id IS NULL";
    if ($status === 'appraised') $where[] = "ap.id IS NOT NULL";
    if (in_array($status, ['Pending','Acknowledged'], true)) $where[] = "ap.status = '{$status}'";
    if ($managementReviewEnabled === 1 && in_array($loggedInRoleKey, ['super_admin','admin'], true) && $managementReviewStatus !== 'all') {
        $currentRevisionSql = "(COALESCE(ap.update_count,0) + 1)";
        if ($managementReviewStatus === 'unreviewed') {
            $where[] = "ap.id IS NOT NULL AND (mr.id IS NULL OR mr.appraisal_revision_no <> {$currentRevisionSql})";
        } else {
            $where[] = "ap.id IS NOT NULL AND mr.appraisal_revision_no = {$currentRevisionSql} AND mr.review_status = '{$managementReviewStatus}'";
        }
    }

    $whereSql = implode(' AND ', $where);

    $baseFrom = "
        FROM supervisor_assignments sa
        INNER JOIN users s ON s.id = sa.staff_id
        INNER JOIN roles sr ON sr.id = s.role_id
        LEFT JOIN users sup ON sup.id = sa.supervisor_id
        LEFT JOIN appraisals ap ON ap.staff_user_id = s.id AND ap.cycle_id = sa.cycle_id
        LEFT JOIN appraisal_management_reviews mr ON mr.id = (
            SELECT MAX(mr2.id) FROM appraisal_management_reviews mr2 WHERE mr2.appraisal_id = ap.id
        )
        LEFT JOIN users mru ON mru.id = mr.reviewed_by
        LEFT JOIN (
            SELECT os2.cycle_id, os2.company_id,
                   CAST(SUBSTRING_INDEX(GROUP_CONCAT(os2.id ORDER BY os2.sort_order ASC, os2.id ASC), ',', 1) AS UNSIGNED) AS objective_section_id
            FROM appraisal_sections os2
            INNER JOIN appraisal_section_objective_settings osc2 ON osc2.section_id=os2.id AND osc2.enabled=1
            WHERE os2.type='kpi' AND os2.is_active=1
            GROUP BY os2.cycle_id, os2.company_id
        ) obj_cfg ON obj_cfg.cycle_id=sa.cycle_id AND obj_cfg.company_id=s.company_id
        LEFT JOIN appraisal_sections os ON os.id=obj_cfg.objective_section_id
        LEFT JOIN appraisal_section_objective_settings osc ON osc.section_id=os.id AND osc.enabled=1
        LEFT JOIN staff_objective_plans sop ON sop.section_id=os.id AND sop.staff_user_id=s.id
        LEFT JOIN appraisal_staff_scoring_profiles asp ON asp.cycle_id=sa.cycle_id AND asp.staff_user_id=s.id
        LEFT JOIN appraisal_scoring_profiles sp ON sp.id=asp.profile_id AND sp.cycle_id=sa.cycle_id AND sp.company_id=s.company_id AND sp.is_active=1
        WHERE {$whereSql}
    ";

    $count = companyReadFetchOne($conn, "SELECT COUNT(*) AS total {$baseFrom}",
        $candidateVisibility['types'], $candidateVisibility['params']);
    $total = (int)($count['total'] ?? 0);
    $totalPages = max((int)ceil($total / $limit), 1);
    if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $limit; }

    $rows = companyReadFetchAll($conn, "
        SELECT
            s.id AS staff_user_id,
            s.first_name, s.last_name, s.fullname, s.email, s.staff_id, s.unique_ref,
            s.department, s.job_title, s.staff_type, s.location, s.date_of_joining,
            sa.supervisor_id,
            ap.id AS appraisal_id,
            ap.status AS appraisal_status,
            ap.kpi_rating,
            ap.appraisal_summary,
            ap.evaluation_statement,
            ap.feedback,
            ap.edited_count,
            ap.update_count,
            ap.created_at AS appraised_at,
            ap.updated_at AS appraisal_updated_at,
            CASE
                WHEN {$managementReviewEnabled} = 0 OR ap.id IS NULL THEN NULL
                WHEN mr.id IS NULL OR mr.appraisal_revision_no <> (COALESCE(ap.update_count,0) + 1) THEN 'unreviewed'
                ELSE mr.review_status
            END AS management_review_status,
            CASE WHEN mr.appraisal_revision_no = (COALESCE(ap.update_count,0) + 1) THEN mr.category ELSE NULL END AS management_review_category,
            CASE WHEN mr.appraisal_revision_no = (COALESCE(ap.update_count,0) + 1) THEN mr.note ELSE NULL END AS management_review_note,
            CASE WHEN mr.appraisal_revision_no = (COALESCE(ap.update_count,0) + 1) THEN mr.created_at ELSE NULL END AS management_reviewed_at,
            CASE WHEN mr.appraisal_revision_no = (COALESCE(ap.update_count,0) + 1) THEN TRIM(CONCAT(COALESCE(mru.first_name,''), ' ', COALESCE(mru.last_name,''))) ELSE NULL END AS management_reviewer_name,
            os.id AS objective_section_id,
            CASE WHEN osc.section_id IS NULL THEN 0 ELSE 1 END AS objective_setup_required,
            COALESCE(sop.status, 'not_started') AS objective_plan_status,
            sop.updated_at AS objective_plan_updated_at,
            sp.id AS scoring_profile_id,
            sp.code AS scoring_profile_code,
            sp.label AS scoring_profile_label,
            TRIM(CONCAT(COALESCE(sup.first_name, ''), ' ', COALESCE(sup.last_name, ''))) AS supervisor_name,
            CASE
                WHEN ap.id IS NULL AND (
                    " . (in_array($loggedInRoleKey, ['super_admin','admin'], true) && $candidateCanManage ? "1 = 1" : "0 = 1") . "
                    OR (
                        sa.supervisor_id = {$loggedInUserId}
                        AND " . ($loggedInCanAppraise && ($candidateContext === null || !empty($candidateContext['is_home'])) ? "1 = 1" : "1 = 0") . "
                    )
                ) THEN 1
                ELSE 0
            END AS can_manage_profile,
            CASE
                WHEN ap.id IS NULL AND (
                    " . (in_array($loggedInRoleKey, ['super_admin','admin'], true) && $candidateCanManage ? "1 = 1" : "0 = 1") . "
                    OR (
                        sa.supervisor_id = {$loggedInUserId}
                        AND " . ($loggedInCanAppraise && ($candidateContext === null || !empty($candidateContext['is_home'])) ? "1 = 1" : "1 = 0") . "
                        AND EXISTS (
                            SELECT 1
                            FROM supervisor_onboarding so_obj
                            WHERE so_obj.cycle_id = sa.cycle_id
                              AND so_obj.supervisor_id = sa.supervisor_id
                            LIMIT 1
                        )
                    )
                ) THEN 1
                ELSE 0
            END AS can_manage_objectives,
            CASE
                WHEN sa.supervisor_id = {$loggedInUserId}
                 AND " . ($loggedInCanAppraise && ($candidateContext === null || !empty($candidateContext['is_home'])) ? "1 = 1" : "1 = 0") . "
                 AND EXISTS (
                    SELECT 1
                    FROM supervisor_onboarding so
                    WHERE so.cycle_id = sa.cycle_id
                      AND so.supervisor_id = sa.supervisor_id
                    LIMIT 1
                 )
                THEN 1
                ELSE 0
            END AS can_manage_appraisal
        {$baseFrom}
        ORDER BY s.first_name ASC, s.last_name ASC, s.id ASC
        LIMIT {$limit} OFFSET {$offset}
    ", $candidateVisibility['types'], $candidateVisibility['params']);

    $stats = companyReadFetchOne($conn, "
        SELECT
            COUNT(sa.id) AS assigned_count,
            COALESCE(SUM(CASE WHEN ap.id IS NOT NULL THEN 1 ELSE 0 END), 0) AS appraised_count,
            COALESCE(SUM(CASE WHEN {$managementReviewEnabled} = 1 AND ap.id IS NOT NULL AND (mr.id IS NULL OR mr.appraisal_revision_no <> (COALESCE(ap.update_count,0) + 1)) THEN 1 ELSE 0 END), 0) AS review_unreviewed_count,
            COALESCE(SUM(CASE WHEN {$managementReviewEnabled} = 1 AND ap.id IS NOT NULL AND mr.appraisal_revision_no = (COALESCE(ap.update_count,0) + 1) AND mr.review_status='reviewed' THEN 1 ELSE 0 END), 0) AS reviewed_count,
            COALESCE(SUM(CASE WHEN {$managementReviewEnabled} = 1 AND ap.id IS NOT NULL AND mr.appraisal_revision_no = (COALESCE(ap.update_count,0) + 1) AND mr.review_status='follow_up' THEN 1 ELSE 0 END), 0) AS follow_up_count
        FROM supervisor_assignments sa
        INNER JOIN users s ON s.id = sa.staff_id
        INNER JOIN roles sr ON sr.id = s.role_id
        LEFT JOIN users sup ON sup.id = sa.supervisor_id
        LEFT JOIN appraisals ap ON ap.staff_user_id = s.id AND ap.cycle_id = sa.cycle_id
        LEFT JOIN appraisal_management_reviews mr ON mr.id = (
            SELECT MAX(mr2.id) FROM appraisal_management_reviews mr2 WHERE mr2.appraisal_id = ap.id
        )
        WHERE sa.cycle_id = {$cycleId}
          AND ( {$supervisorId} <= 0 OR sa.supervisor_id = {$supervisorId} )
          AND s.company_id = {$companyId}
          AND s.is_active = 1
          " . ($focusedStaffId !== null ? ' AND s.id = ' . (int)$focusedStaffId : '') . "
          AND " . appraiseeRoleWhere('sr') . "
          " . ($candidateVisibility['sql'] !== '' ? ' AND ' . $candidateVisibility['sql'] : '') . "
    ", $candidateVisibility['types'], $candidateVisibility['params']);

    $assignedCount = (int)($stats['assigned_count'] ?? 0);
    $appraisedCount = (int)($stats['appraised_count'] ?? 0);
    $pendingCount = max($assignedCount - $appraisedCount, 0);
    $progressPercent = $assignedCount > 0 ? round(($appraisedCount / $assignedCount) * 100, 1) : 0;
    $reviewUnreviewedCount = (int)($stats['review_unreviewed_count'] ?? 0);
    $reviewedCount = (int)($stats['reviewed_count'] ?? 0);
    $followUpCount = (int)($stats['follow_up_count'] ?? 0);

    $scoringProfiles = fetchAllRaw($conn, "SELECT id, code, label, description, is_default, sort_order FROM appraisal_scoring_profiles WHERE company_id={$companyId} AND cycle_id={$cycleId} AND is_active=1 ORDER BY sort_order ASC, id ASC");
    foreach ($scoringProfiles as &$profileRow) {
        $profileRow['id'] = (int)$profileRow['id'];
        $profileRow['is_default'] = (int)($profileRow['is_default'] ?? 0);
        $profileRow['sort_order'] = (int)($profileRow['sort_order'] ?? 0);
    }
    unset($profileRow);

    $onboard = $allSupervisors ? null : fetchOneRaw($conn, "SELECT id FROM supervisor_onboarding WHERE cycle_id = {$cycleId} AND supervisor_id = {$supervisorId} LIMIT 1");

    $supervisorMeta = $allSupervisors ? ['id' => 0, 'full_name' => 'All Supervisors', 'is_onboarded' => 1] : $supervisor;
    if (!$allSupervisors) {
        $supervisorMeta['full_name'] = trim(($supervisor['first_name'] ?? '') . ' ' . ($supervisor['last_name'] ?? ''));
        $supervisorMeta['is_onboarded'] = $onboard ? 1 : 0;
    }

    // One translation batch for accessible candidates, their profile choices
    // and the active cycle label. IDs/permission flags remain untouched.
    $localizedCandidates = [
        'rows' => $rows,
        'cycle' => ['title' => $cycle['title']],
        'profiles' => $scoringProfiles,
    ];
    contentTranslationApplyPaths($conn, $localizedCandidates, $userData, contentTranslationAppraisalCandidatePaths());
    contentTranslationAddJobTitleDisplays($conn, $localizedCandidates, $userData);
    $rows = $localizedCandidates['rows'];
    $scoringProfiles = $localizedCandidates['profiles'];
    $cycle['title'] = $localizedCandidates['cycle']['title'];
    $cycleTitleSource = $localizedCandidates['cycle']['title_source'] ?? $cycle['title'];

    jsonResponse('Success', 'Appraisal candidates fetched successfully.', $rows, [
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'total_pages' => $totalPages,
        'assigned_count' => $assignedCount,
        'appraised_count' => $appraisedCount,
        'pending_count' => $pendingCount,
        'progress_percent' => $progressPercent,
        'review_unreviewed_count' => $reviewUnreviewedCount,
        'reviewed_count' => $reviewedCount,
        'follow_up_count' => $followUpCount,
        'cycle' => [
            'id' => (int)$cycle['id'],
            'title' => $cycle['title'],
            'title_source' => $cycleTitleSource,
            'year' => $cycle['year'],
            'is_active' => (int)$cycle['is_active'],
        ],
        'require_explicit_profile' => (int)($cycleScoringSettings['require_explicit_profile'] ?? 0),
        'management_review_enabled' => $managementReviewEnabled,
        'scoring_profiles' => $scoringProfiles,
        'supervisor' => $supervisorMeta,
    ]);

} catch (Exception $e) {
    $code = $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    jsonResponse('Failed', $e->getMessage(), [], [], $code);
}
