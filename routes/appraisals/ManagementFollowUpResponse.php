<?php
require_once __DIR__ . '/../../includes/ApiException.php';

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once __DIR__ . '/../utils/Notifications.php';
require_once __DIR__ . '/AppraisalHelpers.php';

header('Content-Type: application/json');

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new Exception('Bad Request: Only POST method is allowed.', 405);
    }

    $userData = authenticateUser();
    $loggedInUserId = (int)($userData['id'] ?? 0);
    if ($loggedInUserId <= 0) throw new Exception('Unauthorized.', 401);

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) throw new Exception('Invalid request format. Expected JSON object.', 400);

    $appraisalId = (int)($data['appraisal_id'] ?? 0);
    $managementReviewId = (int)($data['management_review_id'] ?? 0);
    $responseText = trim((string)($data['response'] ?? ''));

    if ($appraisalId <= 0) throw new Exception("Field 'appraisal_id' is required.", 400);
    if ($managementReviewId <= 0) throw new Exception("Field 'management_review_id' is required.", 400);
    if ($responseText === '') throw new Exception('Please enter a response to the management follow-up.', 400);
    $length = function_exists('mb_strlen') ? mb_strlen($responseText, 'UTF-8') : strlen($responseText);
    if ($length > 5000) throw new Exception('Follow-up response cannot exceed 5,000 characters.', 400);

    $appraisal = apFetchOne($conn, "
        SELECT ap.*, ac.year AS cycle_year,
               COALESCE(acs.management_review_enabled, 0) AS management_review_enabled
        FROM appraisals ap
        INNER JOIN appraisal_cycles ac ON ac.id = ap.cycle_id
        LEFT JOIN appraisal_cycle_settings acs ON acs.cycle_id = ap.cycle_id AND acs.company_id = ap.company_id
        WHERE ap.id = {$appraisalId}
        LIMIT 1
    ");
    if (!$appraisal) throw new Exception('Appraisal not found.', 404);
    if ((int)($appraisal['management_review_enabled'] ?? 0) !== 1) {
        throw new Exception('Management review is not enabled for this appraisal cycle.', 409);
    }
    if ((int)$appraisal['supervisor_id'] !== $loggedInUserId) {
        throw new Exception('Only the supervisor/HOD who conducted this appraisal can respond to this follow-up.', 403);
    }
    if (!userCanConductAppraisals($userData)) {
        throw new Exception('Your account is not currently eligible to conduct appraisals.', 403);
    }

    $review = apFetchOne($conn, "
        SELECT *
        FROM appraisal_management_reviews
        WHERE id = {$managementReviewId}
          AND appraisal_id = {$appraisalId}
          AND review_status = 'follow_up'
        LIMIT 1
    ");
    if (!$review) throw new Exception('Management follow-up not found.', 404);
    if ((string)($review['follow_up_state'] ?? 'open') === 'resolved') {
        throw new Exception('This management follow-up has already been resolved.', 409);
    }

    $revision = apFetchOne($conn, "SELECT MAX(revision_no) AS revision_no FROM appraisal_revisions WHERE appraisal_id = {$appraisalId}");
    $revisionNo = max((int)($revision['revision_no'] ?? 0), 1);
    $companyId = (int)$appraisal['company_id'];

    $stmt = $conn->prepare("INSERT INTO appraisal_management_follow_up_responses (management_review_id, appraisal_id, company_id, appraisal_revision_no, response, responded_by) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmt) throw new Exception('Database error: ' . $conn->error, 500);
    $stmt->bind_param('iiiisi', $managementReviewId, $appraisalId, $companyId, $revisionNo, $responseText, $loggedInUserId);
    if (!$stmt->execute()) {
        $error = $stmt->error ?: $conn->error;
        $stmt->close();
        throw new Exception('Unable to save follow-up response: ' . $error, 500);
    }
    $responseId = (int)$stmt->insert_id;
    $stmt->close();

    if (!$conn->query("UPDATE appraisal_management_reviews SET follow_up_state = 'responded' WHERE id = {$managementReviewId}")) {
        throw new Exception('Unable to update follow-up status: ' . $conn->error, 500);
    }

    apAudit(
        $conn,
        $companyId,
        $loggedInUserId,
        'respond_management_follow_up',
        'appraisals',
        $appraisalId,
        "Supervisor/HOD responded to management follow-up on {$appraisal['staff_fullname']}'s {$appraisal['cycle_year']} appraisal"
    );

    $reviewerId = (int)($review['reviewed_by'] ?? 0);
    if ($reviewerId > 0 && $reviewerId !== $loggedInUserId) {
        createNotification(
            $conn,
            $companyId,
            $reviewerId,
            'appraisal_management_follow_up_response',
            'Supervisor responded to management follow-up',
            "The supervisor/HOD responded to your follow-up on {$appraisal['staff_fullname']}'s {$appraisal['cycle_year']} appraisal.",
            '/appraisals/view/' . $appraisalId, 0, 0,
            'notification.followUpResponseReviewer', ['name' => $appraisal['staff_fullname'], 'year' => (string)$appraisal['cycle_year']]
        );
    }

    $grantNotificationRecipients = tryCreateGrantNotificationsForSubject(
        $conn, $companyId, (int)$appraisal['staff_user_id'], $appraisalId,
        'appraisal_management_follow_up_response_admin',
        'Management follow-up response received',
        "A supervisor responded to the management follow-up for {$appraisal['staff_fullname']}.",
        '/appraisals/view/' . $appraisalId,
        [$reviewerId, $loggedInUserId],
        'notification.followUpResponseAdmin', ['name' => $appraisal['staff_fullname']]
    );
    emailGrantNotificationRecipients($conn, $companyId, (int)$appraisal['staff_user_id'], $appraisalId,
        $grantNotificationRecipients, 'Management follow-up response received',
        "A supervisor responded to the management follow-up for {$appraisal['staff_fullname']}.",
        '/appraisals/view/' . $appraisalId,
        ['name' => $appraisal['staff_fullname']]);

    $saved = apFetchOne($conn, "
        SELECT r.id, r.management_review_id, r.appraisal_revision_no, r.response, r.responded_by, r.created_at,
               TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS responder_name,
               u.email AS responder_email
        FROM appraisal_management_follow_up_responses r
        LEFT JOIN users u ON u.id = r.responded_by
        WHERE r.id = {$responseId}
        LIMIT 1
    ");

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Your response has been sent to management.',
        'message_key' => 'review.responseSent',
        'message_params' => new stdClass(),
        'data' => $saved,
    ]);
} catch (Exception $e) {
    $code = $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($code);
    echo json_encode(apiErrorPayload($e));
}
