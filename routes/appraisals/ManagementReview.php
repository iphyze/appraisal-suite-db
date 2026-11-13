<?php
require_once __DIR__ . '/../../includes/ApiException.php';

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/AdminAppraisalVisibility.php';
require_once 'includes/CompanyAccess.php';
require_once 'includes/CompanyAppraisalWrite.php';
require_once 'includes/AdminStaffScope.php';
require_once __DIR__ . '/../utils/Notifications.php';
require_once __DIR__ . '/AppraisalHelpers.php';

header('Content-Type: application/json');

function mrAppraisalForUser(mysqli $conn, array $userData, int $appraisalId, bool $grantReadOnly = false): array {
    $row = apFetchOne($conn, "
        SELECT ap.*, ac.year AS cycle_year, ac.title AS cycle_title,
               COALESCE(acs.management_review_enabled, 0) AS management_review_enabled
        FROM appraisals ap
        INNER JOIN appraisal_cycles ac ON ac.id = ap.cycle_id
        LEFT JOIN appraisal_cycle_settings acs ON acs.cycle_id = ap.cycle_id AND acs.company_id = ap.company_id
        WHERE ap.id = {$appraisalId}
        LIMIT 1
    ");
    if (!$row) throw new Exception('Appraisal not found.', 404);

    $roleKey = authRoleKey($userData['role'] ?? '');
    $userId = (int)($userData['id'] ?? 0);
    $companyId = (int)($userData['company_id'] ?? 0);

    if ($roleKey === 'super_admin') {
        $scope = resolveCompanyScope($userData);
        if ($scope !== null && (int)$row['company_id'] !== (int)$scope) {
            throw new Exception('Unauthorized: This appraisal is outside the selected company context.', 403);
        }
    } elseif ($roleKey === 'admin') {
        $grantContext = null;
        if ($grantReadOnly && (int)$row['company_id'] !== $companyId) {
            $grantContext = resolveActingCompanyContext($userData);
            if ((int)($grantContext['company_id'] ?? 0) !== (int)$row['company_id']) {
                throw companyAccessForbidden();
            }
        } elseif ((int)$row['company_id'] !== $companyId) {
            // Management-review POST/PUT remain HOME-only until Batch 5.
            throw new Exception('Unauthorized: This appraisal does not belong to your company.', 403);
        }
        if ((int)$row['staff_user_id'] === $userId) throw new Exception('You cannot perform management review on your own appraisal.', 403);
        $privacyCompany = $grantContext === null ? $companyId : (int)$grantContext['company_id'];
        $privacySupervisor = $grantContext === null ? (int)$row['supervisor_id'] : 0;
        if (!adminCanViewAppraisalSubject($conn, $userId, $privacyCompany, (int)$row['staff_user_id'], $privacySupervisor)) {
            throw new Exception('Unauthorized: You are not permitted to review this appraisal.', 403);
        }
        if ($grantContext !== null) {
            // Read grants require saved staff_type for every appraisee role.
            $scope = (string)$grantContext['staff_scope'];
            if ($scope !== 'All' && (string)($row['staff_type'] ?? '') !== $scope) {
                throw new Exception('Unauthorized: This appraisal is outside your staff scope.', 403);
            }
        } else {
            $subject = apFetchOne($conn, "SELECT u.id, u.company_id, u.staff_type, r.name AS role_name FROM users u INNER JOIN roles r ON r.id=u.role_id WHERE u.id=" . (int)$row['staff_user_id'] . " LIMIT 1");
            if ($subject) $subject['staff_type'] = $subject['staff_type'] ?? ($row['staff_type'] ?? null);
            if ($subject && !staffScopeAllowsSubject($conn, $userData, null, $subject,
                (int)$row['cycle_id'], (int)$row['supervisor_id'])) {
                throw new Exception('Unauthorized: This appraisal is outside your staff scope.', 403);
            }
        }
    } else {
        throw new Exception('Unauthorized: Management review is restricted to administrators.', 403);
    }

    if ((int)($row['management_review_enabled'] ?? 0) !== 1) {
        throw new Exception('Management review is not enabled for this appraisal cycle.', 409);
    }

    return $row;
}

try {
    $userData = requireRoles(['super_admin', 'admin']);
    $loggedInUserId = (int)$userData['id'];
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $grantWriteTransactionOpen = false;

    if ($method === 'GET') {
        $appraisalId = isset($_GET['appraisal_id']) ? (int)$_GET['appraisal_id'] : 0;
        if ($appraisalId <= 0) throw new Exception("Missing required parameter: 'appraisal_id'.", 400);
        mrAppraisalForUser($conn, $userData, $appraisalId, true);

        $revisions = apRevisionHistory($conn, $appraisalId);
        $currentRevisionNo = !empty($revisions) ? (int)$revisions[0]['revision_no'] : 1;
        $reviewData = apManagementReviewHistory($conn, $appraisalId, $currentRevisionNo);

        http_response_code(200);
        echo json_encode([
            'status' => 'Success',
            'message' => 'Management review history fetched successfully.',
            'data' => [
                'status' => $reviewData['status'],
                'current' => $reviewData['current'],
                'history' => $reviewData['history'],
                'open_follow_up' => $reviewData['open_follow_up'] ?? null,
                'current_revision_no' => $currentRevisionNo,
            ],
        ]);
        exit;
    }

    if ($method !== 'POST' && $method !== 'PUT') {
        throw new Exception('Bad Request: Only GET, POST or PUT methods are allowed.', 405);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) throw new Exception('Invalid request format. Expected JSON object.', 400);

    $appraisalId = isset($data['appraisal_id']) ? (int)$data['appraisal_id'] : 0;
    if ($appraisalId <= 0) throw new Exception("Field 'appraisal_id' is required.", 400);

    $writeContext = companyAppraisalWriteContext($userData);
    $appraisal = mrAppraisalForUser($conn, $userData, $appraisalId, true);
    companyAppraisalWriteAssertRecord($conn, $userData, $writeContext, $appraisal);

    $resolveFollowUpId = (int)($data['resolve_follow_up_id'] ?? 0);

    $reviewStatus = strtolower(trim((string)($data['review_status'] ?? '')));
    if (!in_array($reviewStatus, ['reviewed', 'follow_up'], true)) {
        throw new Exception("Field 'review_status' must be 'reviewed' or 'follow_up'.", 400);
    }

    if ($reviewStatus === 'follow_up') {
        $existingOpenFollowUp = apFetchOne($conn, "SELECT id FROM appraisal_management_reviews WHERE appraisal_id = {$appraisalId} AND review_status = 'follow_up' AND COALESCE(follow_up_state, 'open') <> 'resolved' ORDER BY id DESC LIMIT 1");
        if ($existingOpenFollowUp) {
            throw new Exception('There is already an unresolved management follow-up for this appraisal. Wait for the supervisor/HOD response or resolve the existing follow-up first.', 409);
        }
    }

    $category = strtolower(trim((string)($data['category'] ?? 'general')));
    $allowedCategories = ['general','score','documentation','hse','integrity','development','other'];
    if (!in_array($category, $allowedCategories, true)) {
        throw new Exception('Invalid management review category.', 400);
    }

    $note = trim((string)($data['note'] ?? ''));
    if ($reviewStatus === 'follow_up' && $note === '') {
        throw new Exception('A management note is required when follow-up is requested.', 400);
    }
    $noteLength = function_exists('mb_strlen') ? mb_strlen($note, 'UTF-8') : strlen($note);
    if ($noteLength > 5000) throw new Exception('Management note cannot exceed 5,000 characters.', 400);

    $revision = apFetchOne($conn, "SELECT MAX(revision_no) AS revision_no FROM appraisal_revisions WHERE appraisal_id = {$appraisalId}");
    $revisionNo = max((int)($revision['revision_no'] ?? 0), 1);

    $followUpState = $reviewStatus === 'follow_up' ? 'open' : null;
    if (companyUserWriteIsGranted($writeContext)) {
        $conn->begin_transaction();
        $grantWriteTransactionOpen = true;
    }
    $stmt = $conn->prepare("INSERT INTO appraisal_management_reviews (appraisal_id, company_id, review_status, follow_up_state, appraisal_revision_no, category, note, reviewed_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) throw new Exception('Database error: ' . $conn->error, 500);
    $companyId = (int)$appraisal['company_id'];
    $stmt->bind_param('iississi', $appraisalId, $companyId, $reviewStatus, $followUpState, $revisionNo, $category, $note, $loggedInUserId);
    if (!$stmt->execute()) {
        $error = $stmt->error ?: $conn->error;
        $stmt->close();
        throw new Exception('Unable to save management review: ' . $error, 500);
    }
    $reviewId = (int)$stmt->insert_id;
    $stmt->close();

    if ($reviewStatus === 'reviewed' && $resolveFollowUpId > 0) {
        $followUp = apFetchOne($conn, "SELECT id, follow_up_state FROM appraisal_management_reviews WHERE id = {$resolveFollowUpId} AND appraisal_id = {$appraisalId} AND review_status = 'follow_up' LIMIT 1");
        if (!$followUp) throw new Exception('The follow-up being resolved could not be found.', 404);
        if ((string)($followUp['follow_up_state'] ?? 'open') === 'resolved') throw new Exception('This follow-up has already been resolved.', 409);
        $resolvedAt = date('Y-m-d H:i:s');
        $stmtResolve = $conn->prepare("UPDATE appraisal_management_reviews SET follow_up_state = 'resolved', resolved_by = ?, resolved_at = ? WHERE id = ?");
        if (!$stmtResolve) throw new Exception('Database error: ' . $conn->error, 500);
        $stmtResolve->bind_param('isi', $loggedInUserId, $resolvedAt, $resolveFollowUpId);
        if (!$stmtResolve->execute()) {
            $error = $stmtResolve->error ?: $conn->error;
            $stmtResolve->close();
            throw new Exception('Unable to resolve management follow-up: ' . $error, 500);
        }
        $stmtResolve->close();
    }

    $description = $reviewStatus === 'follow_up'
        ? "Management requested follow-up on {$appraisal['staff_fullname']}'s {$appraisal['cycle_year']} appraisal"
        : ($resolveFollowUpId > 0
            ? "Management resolved follow-up and marked {$appraisal['staff_fullname']}'s {$appraisal['cycle_year']} appraisal as reviewed"
            : "Management marked {$appraisal['staff_fullname']}'s {$appraisal['cycle_year']} appraisal as reviewed");
    companyAppraisalWriteAudit($conn, $userData, $writeContext, $companyId, 'management_review_appraisal', 'appraisals', $appraisalId, $description);

    $supervisorId = (int)($appraisal['supervisor_id'] ?? 0);
    if ($supervisorId > 0 && $supervisorId !== $loggedInUserId) {
        $title = $reviewStatus === 'follow_up'
            ? 'Management follow-up requested'
            : ($resolveFollowUpId > 0 ? 'Management follow-up resolved' : 'Appraisal management review completed');
        $message = $reviewStatus === 'follow_up'
            ? "Management requested follow-up on {$appraisal['staff_fullname']}'s {$appraisal['cycle_year']} appraisal."
            : ($resolveFollowUpId > 0
                ? "Management resolved the follow-up on {$appraisal['staff_fullname']}'s {$appraisal['cycle_year']} appraisal."
                : "Management completed its review of {$appraisal['staff_fullname']}'s {$appraisal['cycle_year']} appraisal.");
        $notificationKey = $reviewStatus === 'follow_up'
            ? 'notification.managementFollowUpRequested'
            : ($resolveFollowUpId > 0 ? 'notification.managementFollowUpResolved' : 'notification.managementReviewed');
        companyAppraisalWriteNotify($conn, $writeContext, $companyId, $supervisorId, 'appraisal_management_review', $title, $message,
            '/appraisals/view/' . $appraisalId, $notificationKey, ['name' => $appraisal['staff_fullname'], 'year' => (string)$appraisal['cycle_year']]);
    }

    $saved = apFetchOne($conn, "
        SELECT mr.id, mr.review_status, mr.follow_up_state, mr.appraisal_revision_no, mr.category, mr.note, mr.reviewed_by, mr.resolved_by, mr.resolved_at, mr.created_at,
               TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS reviewer_name,
               u.email AS reviewer_email
        FROM appraisal_management_reviews mr
        LEFT JOIN users u ON u.id = mr.reviewed_by
        WHERE mr.id = {$reviewId}
        LIMIT 1
    ");

    if ($grantWriteTransactionOpen) {
        $conn->commit();
        $grantWriteTransactionOpen = false;
    }
    http_response_code(200);
    echo json_encode(['status' => 'Success', 'message' => 'Management review saved successfully.',
        'message_key' => 'review.saved',
        'message_params' => new stdClass(), 'data' => $saved]);
} catch (Throwable $e) {
    if (!empty($grantWriteTransactionOpen)) $conn->rollback();
    $code = $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($code);
    echo json_encode(apiErrorPayload($e));
}
