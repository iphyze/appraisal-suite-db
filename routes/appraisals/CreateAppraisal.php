<?php
require_once __DIR__ . '/../../includes/ApiException.php';

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/AdminStaffScope.php';
require_once __DIR__ . '/../utils/Mailer.php';
require_once __DIR__ . '/../utils/EmailTemplates.php';
require_once __DIR__ . '/../utils/Notifications.php';
require_once __DIR__ . '/AppraisalHelpers.php';

use Dotenv\Dotenv;

header('Content-Type: application/json');

try {
    $dotenv = Dotenv::createImmutable('./');
    $dotenv->safeLoad();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Bad Request: Only POST method is allowed', 405);

    $userData = requireRoles(['super_admin', 'admin', 'supervisor']);
    $loggedInUserId = (int)$userData['id'];
    $loggedInRole = $userData['role'] ?? '';
    $loggedInRoleKey = strtolower(str_replace(' ', '_', trim((string)$loggedInRole)));
    $loggedInCompanyId = isset($userData['company_id']) ? (int)$userData['company_id'] : 0;

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) throw new Exception('Invalid request format. Expected JSON object.', 400);

    $staffUserId = isset($data['staff_user_id']) ? (int)$data['staff_user_id'] : 0;
    $cycleId = isset($data['cycle_id']) ? (int)$data['cycle_id'] : 0;
    $sections = apNormalizeSections($data);
    $notifyStaff = isset($data['notify_staff']) ? (bool)$data['notify_staff'] : (isset($data['send_email']) ? (bool)$data['send_email'] : true);

    if ($staffUserId <= 0) throw new Exception("Field 'staff_user_id' is required.", 400);
    if ($cycleId <= 0) throw new Exception("Field 'cycle_id' is required.", 400);

    $companyScope = resolveCompanyScope($userData);
    $cycleScope = $companyScope !== null ? " AND company_id = " . (int) $companyScope : '';
    $cycle = apFetchOne($conn, "SELECT * FROM appraisal_cycles WHERE id = {$cycleId} {$cycleScope} LIMIT 1");
    if (!$cycle) throw new Exception('Selected appraisal cycle was not found or is outside your company scope.', 404);
    $companyId = (int)$cycle['company_id'];

    $staff = apFetchOne($conn, "
        SELECT u.*, r.name AS role_name
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        WHERE u.id = {$staffUserId}
          AND u.company_id = {$companyId}
          AND u.is_active = 1
          AND " . appraiseeRoleWhere('r') . "
        LIMIT 1
    ");
    if (!$staff) throw new Exception('Staff member was not found for this appraisal cycle.', 404);

    // The home admin's supervisor assignment bypasses admin staff scope only
    // for this particular appraisal cycle. Other admin permissions stay intact.
    if ($loggedInRoleKey === 'admin' && !staffScopeAllowsSubject(
        $conn, $userData, null, $staff, $cycleId
    )) {
        throw new Exception('Unauthorized: This employee is outside your staff scope.', 403);
    }

    if (!userCanConductAppraisals($userData)) {
        throw new Exception('Unauthorized: Only a user configured as a supervisor can start an appraisal.', 403);
    }

    $assignment = apFetchOne($conn, "
        SELECT id, supervisor_id
        FROM supervisor_assignments
        WHERE cycle_id = {$cycleId}
          AND staff_id = {$staffUserId}
        ORDER BY id ASC
        LIMIT 1
    ");

    if (!$assignment) {
        throw new Exception('Assign this employee to a supervisor before starting the appraisal.', 400);
    }

    $supervisorId = (int) $assignment['supervisor_id'];
    if ($supervisorId !== $loggedInUserId) {
        throw new Exception('This employee is not assigned to you for this appraisal cycle.', 403);
    }

    $onboard = apFetchOne($conn, "SELECT id FROM supervisor_onboarding WHERE cycle_id = {$cycleId} AND supervisor_id = {$supervisorId} LIMIT 1");
    if (!$onboard) throw new Exception('Complete supervisor onboarding before starting this appraisal.', 403);

    $duplicate = apFetchOne($conn, "SELECT id FROM appraisals WHERE staff_user_id = {$staffUserId} AND cycle_id = {$cycleId} LIMIT 1");
    if ($duplicate) throw new Exception('This staff member already has an appraisal for the selected cycle.', 400);

    $company = apFetchOne($conn, "SELECT name, code FROM companies WHERE id = {$companyId} LIMIT 1");
    $supervisor = apFetchOne($conn, "SELECT first_name, last_name, fullname, email FROM users WHERE id = {$supervisorId} LIMIT 1");

    $staffFullname = apEsc($conn, apFullName($staff));
    $staffDepartment = apEsc($conn, $staff['department'] ?? '');
    $staffLocation = apEsc($conn, $staff['location'] ?? '');
    $staffJobTitle = apEsc($conn, $staff['job_title'] ?? '');
    $staffType = apEsc($conn, $staff['staff_type'] ?? '');
    $staffEmail = apEsc($conn, $staff['email'] ?? '');
    $dateOfJoining = !empty($staff['date_of_joining']) ? "'" . apEsc($conn, $staff['date_of_joining']) . "'" : 'NULL';
    $durationYears = apDurationYears($staff['date_of_joining'] ?? null);
    $durationSql = $durationYears === null ? 'NULL' : (float)$durationYears;
    $requiredRecommendations = apRequireAppraisalRecommendations($data);
    $breachDeclaration = apRequireSeriousBreachDeclaration($data);
    $breachDeclaredSql = (int)$breachDeclaration['serious_breach_declared'];
    $breachReasonSql = $breachDeclaration['serious_breach_reason'] === null ? 'NULL' : "'" . apEsc($conn, $breachDeclaration['serious_breach_reason']) . "'";
    $breachReferenceSql = $breachDeclaration['serious_breach_reference'] === null ? 'NULL' : "'" . apEsc($conn, $breachDeclaration['serious_breach_reference']) . "'";
    $statusUpgrade = apEsc($conn, $requiredRecommendations['status_upgrade']);
    $salaryUpgrade = apEsc($conn, $requiredRecommendations['salary_upgrade']);
    $development = apEsc($conn, $requiredRecommendations['development']);

    // Initialise notification infrastructure BEFORE the database transaction.
    ensureNotificationsTable($conn);
    $grantNotificationRecipients = [];
    $conn->begin_transaction();
    try {
        $conn->query("INSERT INTO appraisals (
            company_id, cycle_id, staff_user_id, supervisor_id,
            staff_fullname, staff_department, staff_location, staff_job_title, staff_type, staff_email,
            date_of_joining, duration_years, status_upgrade, salary_upgrade, development,
            serious_breach_declared, serious_breach_reason, serious_breach_reference,
            status, created_by, updated_by
        ) VALUES (
            {$companyId}, {$cycleId}, {$staffUserId}, {$supervisorId},
            '{$staffFullname}', '{$staffDepartment}', '{$staffLocation}', '{$staffJobTitle}', '{$staffType}', '{$staffEmail}',
            {$dateOfJoining}, {$durationSql}, '{$statusUpgrade}', '{$salaryUpgrade}', '{$development}',
            {$breachDeclaredSql}, {$breachReasonSql}, {$breachReferenceSql},
            'Pending', {$loggedInUserId}, {$loggedInUserId}
        )");

        $appraisalId = (int)$conn->insert_id;
        $scoreData = apSaveResponsesAndScores($conn, $appraisalId, $companyId, $cycleId, $sections, [
            'staff_user_id' => $staffUserId,
            'supervisor_id' => $supervisorId,
            'logged_in_user_id' => $loggedInUserId,
            'staff_department' => $staff['department'] ?? '',
            'enforce_objective_plan' => true,
        ]);

        $breachState = apBreachStateForSubmission($breachDeclaration);
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
            evaluation_statement = '{$statement}',
            scoring_profile_code = {$scoringProfileCodeSql},
            scoring_profile_label = {$scoringProfileLabelSql},
            rating_config_snapshot = '{$ratingConfigSnapshot}'
            WHERE id = {$appraisalId}
        ");

        apSaveRevisionSnapshot($conn, $appraisalId, $companyId, 1, 'submitted', $loggedInUserId, $scoreData, [
            'status_upgrade' => $requiredRecommendations['status_upgrade'],
            'salary_upgrade' => $requiredRecommendations['salary_upgrade'],
            'development' => $requiredRecommendations['development'],
            'breach_declaration' => $breachDeclaration,
        ]);

        apAudit($conn, $companyId, $loggedInUserId, 'create_appraisal', 'appraisals', $appraisalId, "Created appraisal for {$staffFullname} ({$cycle['year']})");
        createNotification($conn, $companyId, $staffUserId, 'appraisal_submitted', 'Your appraisal has been submitted', "Your {$cycle['year']} appraisal has been submitted and is ready for your acknowledgement.", '/appraisals/view/' . $appraisalId, $staffUserId, $appraisalId, 'notification.appraisalSubmittedStaff', ['year' => (string)$cycle['year']]);
        if ($supervisorId && $supervisorId !== $staffUserId) {
            createNotification($conn, $companyId, $supervisorId, 'appraisal_submitted', 'Appraisal submitted', "An appraisal for {$staffFullname} has been submitted for the {$cycle['year']} cycle.", '/appraisals/view/' . $appraisalId, $staffUserId, $appraisalId, 'notification.appraisalSubmittedSupervisor', ['name' => $staffFullname, 'year' => (string)$cycle['year']]);
        }
        createNotificationsForCompanyRoles($conn, $companyId, ['admin', 'super_admin'], 'appraisal_submitted_admin', 'New appraisal submitted', "{$staffFullname}'s {$cycle['year']} appraisal has been submitted.", '/appraisals/view/' . $appraisalId, [$staffUserId, $supervisorId], $staffUserId, $supervisorId, $grantNotificationRecipients, 'notification.appraisalSubmittedAdmin', ['name' => $staffFullname, 'year' => (string)$cycle['year']]);

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    emailGrantNotificationRecipients($conn, $companyId, $staffUserId, $appraisalId,
        $grantNotificationRecipients, 'New appraisal submitted',
        "{$staffFullname}'s {$cycle['year']} appraisal has been submitted.",
        '/appraisals/view/' . $appraisalId,
        ['name' => apFullName($staff), 'year' => (string)$cycle['year']]);

    $emailStatus = null;
    if ($notifyStaff) {
        $emailStatus = apSendStaffEmail('create', [
            'staff_email' => $staff['email'] ?? '',
            'staff_user_id' => $staffUserId,
            'staff_name' => apFullName($staff),
            'cycle_year' => $cycle['year'],
            'evaluation_statement' => $scoreData['evaluation_statement'],
            'appraisal_summary' => $scoreData['appraisal_summary'],
            'unique_ref' => $staff['unique_ref'] ?? '',
            'company_name' => $company['name'] ?? 'Lambert Electromec Ltd',
            'company_color' => '#3da050',
            'app_url' => $_ENV['APP_URL'] ?? 'https://lambertelectromec.com.ng',
            'supervisor_name' => $supervisor ? apFullName($supervisor) : 'Your Supervisor',
            'section_scores' => $scoreData['section_scores'],
        ]);
    }

    http_response_code(201);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Appraisal created successfully',
        'message_key' => 'appraisal.created',
        'message_params' => new stdClass(),
        'data' => ['id' => $appraisalId, 'appraisal_id' => $appraisalId, 'email_status' => $emailStatus],
    ]);

} catch (Throwable $e) {
    $code = (int)$e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    if ($code === 500) error_log('Appraisal submission error: ' . $e->getMessage());
    http_response_code($code);
    echo json_encode(apiErrorPayload($e, $code === 500 ? 'Unable to save appraisal due to a server error. Please try again or contact support.' : null));
}
