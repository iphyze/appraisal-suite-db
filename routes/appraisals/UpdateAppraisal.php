<?php
require_once __DIR__ . '/../../includes/ApiException.php';

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/AdminStaffScope.php';
require_once 'includes/AppraisalUpdateEligibility.php';
require_once __DIR__ . '/../utils/Mailer.php';
require_once __DIR__ . '/../utils/EmailTemplates.php';
require_once __DIR__ . '/../utils/Notifications.php';
require_once __DIR__ . '/AppraisalHelpers.php';

use Dotenv\Dotenv;

header('Content-Type: application/json');

try {
    $dotenv = Dotenv::createImmutable('./');
    $dotenv->safeLoad();

    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') throw new Exception('Bad Request: Only PUT method is allowed', 405);

    $userData = requireRoles(['super_admin', 'admin', 'supervisor']);
    $loggedInUserId = (int)$userData['id'];
    $loggedInRole = $userData['role'] ?? '';
    $loggedInRoleKey = strtolower(str_replace(' ', '_', trim((string)$loggedInRole)));
    $loggedInCompanyId = isset($userData['company_id']) ? (int)$userData['company_id'] : 0;

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) throw new Exception('Invalid request format. Expected JSON object.', 400);

    $appraisalId = isset($data['id']) ? (int)$data['id'] : 0;
    if ($appraisalId <= 0) throw new Exception("Field 'id' is required.", 400);
    $sections = apNormalizeSections($data);
    $notifyStaff = isset($data['notify_staff']) ? (bool)$data['notify_staff'] : (isset($data['send_email']) ? (bool)$data['send_email'] : false);

    $appraisal = apFetchOne($conn, "
        SELECT ap.*, ac.year AS cycle_year, ac.title AS cycle_title, c.name AS company_name, c.code AS company_code
        FROM appraisals ap
        INNER JOIN appraisal_cycles ac ON ac.id = ap.cycle_id
        INNER JOIN companies c ON c.id = ap.company_id
        WHERE ap.id = {$appraisalId}
        LIMIT 1
    ");
    if (!$appraisal) throw new Exception('Appraisal not found.', 404);

    apAssertSupervisorUpdateEligible(apCanSupervisorUpdateAppraisal($conn, $userData, null, $appraisal));

    $staff = apFetchOne($conn, "SELECT * FROM users WHERE id = " . (int)$appraisal['staff_user_id'] . " LIMIT 1");
    $supervisor = apFetchOne($conn, "SELECT first_name, last_name, fullname, email FROM users WHERE id = " . (int)$appraisal['supervisor_id'] . " LIMIT 1");

    // Every material appraisal update must be acknowledged again by the appraised staff member.
    $status = 'Pending';

    $requiredRecommendations = apRequireAppraisalRecommendations($data, $appraisal);
    $breachDeclaration = apRequireSeriousBreachDeclaration($data);
    $breachDeclaredSql = (int)$breachDeclaration['serious_breach_declared'];
    $breachReasonSql = $breachDeclaration['serious_breach_reason'] === null ? 'NULL' : "'" . apEsc($conn, $breachDeclaration['serious_breach_reason']) . "'";
    $breachReferenceSql = $breachDeclaration['serious_breach_reference'] === null ? 'NULL' : "'" . apEsc($conn, $breachDeclaration['serious_breach_reference']) . "'";
    $statusUpgrade = apEsc($conn, $requiredRecommendations['status_upgrade']);
    $salaryUpgrade = apEsc($conn, $requiredRecommendations['salary_upgrade']);
    $development = apEsc($conn, $requiredRecommendations['development']);
    $statusSql = apEsc($conn, $status);

    $ratingConfigOverride = json_decode((string)($appraisal['rating_config_snapshot'] ?? ''), true);
    if (!is_array($ratingConfigOverride) || !isset($ratingConfigOverride['rating_min'], $ratingConfigOverride['rating_max'], $ratingConfigOverride['rating_step'])) {
        $ratingConfigOverride = apCycleRatingConfig($conn, (int)$appraisal['company_id'], (int)$appraisal['cycle_id']);
    }

    $savedSectionWeights = [];
    foreach (apFetchAll($conn, "SELECT section_id, section_weight FROM appraisal_section_scores WHERE appraisal_id = {$appraisalId}") as $savedScore) {
        $savedSectionWeights[(int)$savedScore['section_id']] = (float)$savedScore['section_weight'];
    }
    $scoringProfileOverride = (!empty($appraisal['scoring_profile_code']) || !empty($appraisal['scoring_profile_label'])) ? [
        'id' => null,
        'code' => (string)($appraisal['scoring_profile_code'] ?? ''),
        'label' => (string)($appraisal['scoring_profile_label'] ?? ''),
    ] : null;

    // Initialise notification infrastructure BEFORE the database transaction.
    ensureNotificationsTable($conn);
    $grantNotificationRecipients = [];
    $conn->begin_transaction();
    try {
        // Lock the appraisal row to prevent concurrent saves bypassing the allowance.
        $locked = apFetchOne($conn, "SELECT * FROM appraisals WHERE id = {$appraisalId} FOR UPDATE");
        if (!$locked) throw new Exception('Appraisal not found.', 404);
        apAssertSupervisorUpdateEligible(apCanSupervisorUpdateAppraisal($conn, $userData, null, $locked), true);
        // Appraisal -> plan lock order matches unlock/save. A draft plan cannot
        // be rated, and concurrent objective writes cannot change the plan mid-save.
        apocLockPlansForAppraisal($conn, $appraisalId, (int)$locked['cycle_id'], (int)$locked['staff_user_id']);
        $needsObjectiveRerating = !empty($locked['objectives_changed_at']);
        if ($needsObjectiveRerating) {
            $submittedSignature = (string)($data['objective_plan_signature'] ?? '');
            $currentSignature = apocPlanSignature($conn, $appraisalId, (int)$locked['company_id'], (int)$locked['cycle_id'], (int)$locked['staff_user_id']);
            if ($submittedSignature === '' || !hash_equals($currentSignature, $submittedSignature)) {
                throw new ApiException('objective.planStale', [], 409, 'The objective plan changed while this form was open. Reload the appraisal before updating.');
            }
        }
        // Validate against the locked row BEFORE touching saved responses.
        // A rejected change must not consume an update attempt or erase evidence.
        apValidateBreachUpdateAgainstReview($breachDeclaration, $locked);
        $newUpdateCount = (int)$locked['update_count'] + 1;
        $scoreData = apSaveResponsesAndScores($conn, $appraisalId, (int)$appraisal['company_id'], (int)$appraisal['cycle_id'], $sections, [
            'staff_user_id' => (int)$appraisal['staff_user_id'],
            'supervisor_id' => (int)$appraisal['supervisor_id'],
            'logged_in_user_id' => $loggedInUserId,
            'staff_department' => $appraisal['staff_department'] ?? '',
            'rating_config_override' => $ratingConfigOverride,
            'section_weight_overrides' => $savedSectionWeights ?: null,
            'scoring_profile_override' => $scoringProfileOverride,
            'enforce_objective_plan' => true,
            'is_edit' => true,
            'preserve_response_snapshots' => true,
            're_rate_objectives' => $needsObjectiveRerating,
        ]);

        $breachState = apBreachStateForSubmission($breachDeclaration, $locked);
        $scoreData = apApplyBreachScore($scoreData, $breachState['active']);
        $rawSummarySql = (float)$scoreData['raw_appraisal_summary'];
        $capActiveSql = (int)$scoreData['breach_cap_active'];
        $breachReviewStatusSql = apEsc($conn, $breachState['status']);
        $kpiRatingSql = $scoreData['kpi_rating'] === null ? 'NULL' : (float)$scoreData['kpi_rating'];
        $summarySql = (float)$scoreData['appraisal_summary'];
        $statement = apEsc($conn, $scoreData['evaluation_statement']);
        $snapshot = apEsc($conn, $scoreData['kpi_questions_snapshot']);
        $scoringProfileCode = apEsc($conn, $scoreData['scoring_profile']['code'] ?? '');
        $scoringProfileLabel = apEsc($conn, $scoreData['scoring_profile']['label'] ?? '');
        $scoringProfileCodeSql = $scoringProfileCode !== '' ? "'{$scoringProfileCode}'" : 'NULL';
        $scoringProfileLabelSql = $scoringProfileLabel !== '' ? "'{$scoringProfileLabel}'" : 'NULL';
        $ratingConfigSnapshot = apEsc($conn, json_encode($scoreData['rating_config'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $conn->query("UPDATE appraisals SET
            kpi_questions_snapshot = '{$snapshot}',
            kpi_rating = {$kpiRatingSql},
            appraisal_summary = {$summarySql},
            raw_appraisal_summary = {$rawSummarySql},
            breach_cap_active = {$capActiveSql},
            serious_breach_review_status = '{$breachReviewStatusSql}',
            serious_breach_review_note = IF('{$breachReviewStatusSql}' = 'pending', NULL, serious_breach_review_note),
            serious_breach_reviewed_by = IF('{$breachReviewStatusSql}' = 'pending', NULL, serious_breach_reviewed_by),
            serious_breach_reviewed_at = IF('{$breachReviewStatusSql}' = 'pending', NULL, serious_breach_reviewed_at),
            evaluation_statement = '{$statement}',
            scoring_profile_code = {$scoringProfileCodeSql},
            scoring_profile_label = {$scoringProfileLabelSql},
            rating_config_snapshot = '{$ratingConfigSnapshot}',
            status_upgrade = '{$statusUpgrade}',
            salary_upgrade = '{$salaryUpgrade}',
            development = '{$development}',
            serious_breach_declared = {$breachDeclaredSql},
            serious_breach_reason = {$breachReasonSql},
            serious_breach_reference = {$breachReferenceSql},
            status = '{$statusSql}',
            feedback = NULL,
            acknowledgement = 0,
            acknowledged_at = NULL,
            update_count = {$newUpdateCount},
            objectives_changed_at = NULL,
            updated_by = {$loggedInUserId}
            WHERE id = {$appraisalId}
        ");

        // Appraisal KPI rows have now been replaced; attach full before/after
        // evidence and every pending unlock reason to this single revision.
        $afterObjectiveRows = apocResponseSnapshot($conn, $appraisalId);
        apocConsumeUnlockEvents($conn, $appraisalId, $newUpdateCount + 1, $afterObjectiveRows, $needsObjectiveRerating);
        apSaveRevisionSnapshot($conn, $appraisalId, (int)$appraisal['company_id'], $newUpdateCount + 1, 'updated', $loggedInUserId, $scoreData, [
            'status_upgrade' => $requiredRecommendations['status_upgrade'],
            'salary_upgrade' => $requiredRecommendations['salary_upgrade'],
            'development' => $requiredRecommendations['development'],
            'breach_declaration' => $breachDeclaration,
        ]);

        apAudit($conn, (int)$appraisal['company_id'], $loggedInUserId, 'update_appraisal', 'appraisals', $appraisalId, "Updated appraisal for {$appraisal['staff_fullname']} ({$appraisal['cycle_year']})");
        createNotification($conn, (int)$appraisal['company_id'], (int)$appraisal['staff_user_id'], 'appraisal_updated', 'Your appraisal has been updated', "Your {$appraisal['cycle_year']} appraisal was updated. Please review and acknowledge it.", '/appraisals/view/' . $appraisalId, (int)$appraisal['staff_user_id'], $appraisalId, 'notification.appraisalUpdatedStaff', ['year' => (string)$appraisal['cycle_year']]);
        if ((int)$appraisal['supervisor_id'] !== (int)$appraisal['staff_user_id']) {
            createNotification($conn, (int)$appraisal['company_id'], (int)$appraisal['supervisor_id'], 'appraisal_updated', 'Appraisal updated', "{$appraisal['staff_fullname']}'s {$appraisal['cycle_year']} appraisal was updated.", '/appraisals/view/' . $appraisalId, (int)$appraisal['staff_user_id'], $appraisalId, 'notification.appraisalUpdatedOther', ['name' => $appraisal['staff_fullname'], 'year' => (string)$appraisal['cycle_year']]);
        }
        createNotificationsForCompanyRoles($conn, (int)$appraisal['company_id'], ['admin', 'super_admin'], 'appraisal_updated_admin', 'Appraisal updated', "{$appraisal['staff_fullname']}'s {$appraisal['cycle_year']} appraisal was updated.", '/appraisals/view/' . $appraisalId, [(int)$appraisal['staff_user_id'], (int)$appraisal['supervisor_id']], (int)$appraisal['staff_user_id'], (int)$appraisal['supervisor_id'], $grantNotificationRecipients, 'notification.appraisalUpdatedOther', ['name' => $appraisal['staff_fullname'], 'year' => (string)$appraisal['cycle_year']]);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    emailGrantNotificationRecipients($conn, (int)$appraisal['company_id'], (int)$appraisal['staff_user_id'], $appraisalId,
        $grantNotificationRecipients, 'Appraisal updated',
        "{$appraisal['staff_fullname']}'s {$appraisal['cycle_year']} appraisal was updated.",
        '/appraisals/view/' . $appraisalId,
        ['name' => $appraisal['staff_fullname'], 'year' => (string)$appraisal['cycle_year']]);

    $emailStatus = null;
    if ($notifyStaff) {
        $emailStatus = apSendStaffEmail('update', [
            'staff_email' => $appraisal['staff_email'] ?? ($staff['email'] ?? ''),
            'staff_user_id' => (int)$appraisal['staff_user_id'],
            'staff_name' => $appraisal['staff_fullname'],
            'cycle_year' => $appraisal['cycle_year'],
            'evaluation_statement' => $scoreData['evaluation_statement'],
            'appraisal_summary' => $scoreData['appraisal_summary'],
            'company_name' => $appraisal['company_name'] ?? 'Lambert Electromec Ltd',
            'company_color' => '#3da050',
            'app_url' => $_ENV['APP_URL'] ?? 'https://lambertelectromec.com.ng',
            'supervisor_name' => $supervisor ? apFullName($supervisor) : 'Your Supervisor',
            'section_scores' => $scoreData['section_scores'],
            'update_number' => $newUpdateCount,
        ]);
    }

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Appraisal updated successfully',
        'message_key' => 'appraisal.updated',
        'message_params' => new stdClass(),
        'data' => ['id' => $appraisalId, 'appraisal_id' => $appraisalId, 'email_status' => $emailStatus, 'staff_notified' => $notifyStaff],
    ]);

} catch (Throwable $e) {
    $code = (int)$e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    if ($code === 500) error_log('Appraisal submission error: ' . $e->getMessage());
    http_response_code($code);
    echo json_encode(apiErrorPayload($e, $code === 500 ? 'Unable to save appraisal due to a server error. Please try again or contact support.' : null));
}
