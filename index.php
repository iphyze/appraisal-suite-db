<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/ApiException.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

/*
|--------------------------------------------------------------------------
| Central API response/error helpers
|--------------------------------------------------------------------------
| Router-level authentication failures must preserve their actual HTTP code.
| Never expose fatal file paths or exception messages in production.
*/
function apiIsProduction(): bool
{
    return strtolower((string)($_ENV['APP_ENV'] ?? 'development')) === 'production';
}

function apiExceptionStatus(Throwable $exception): int
{
    $code = (int)$exception->getCode();
    return ($code >= 400 && $code <= 599) ? $code : 500;
}

function apiSafeErrorMessage(Throwable $exception, int $status): string
{
    if ($status >= 500 && apiIsProduction()) {
        return 'Internal server error.';
    }

    return $exception->getMessage() ?: 'Request failed.';
}

set_exception_handler(function (Throwable $exception): void {
    $status = apiExceptionStatus($exception);

    error_log(sprintf(
        '[Lambert API] %s in %s:%d',
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');

    echo json_encode(apiErrorPayload($exception, apiSafeErrorMessage($exception, $status) !== $exception->getMessage()
        ? apiSafeErrorMessage($exception, $status) : null));

    exit;
});

register_shutdown_function(function (): void {
    $error = error_get_last();

    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    error_log(sprintf(
        '[Lambert API Fatal] %s in %s:%d',
        $error['message'],
        $error['file'],
        $error['line']
    ));

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');

    echo json_encode([
        'status'  => 'Failed',
        'message' => apiIsProduction()
            ? 'Internal server error.'
            : 'Fatal error: ' . $error['message'],
    ]);
});

ob_start();

/*
|--------------------------------------------------------------------------
| CORS configuration
|--------------------------------------------------------------------------
| Credentialed browser requests must receive an explicit origin; never "*".
*/
$allowedOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string)($_ENV['FRONTEND_ORIGIN'] ?? 'http://localhost:5173'))
)));

$requestOrigin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));

if ($requestOrigin !== '' && in_array($requestOrigin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$requestOrigin}");
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token, X-Idempotency-Key, X-Requested-With, X-App-Locale');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    ob_end_flush();
    exit;
}

require_once __DIR__ . '/includes/connection.php';
require_once __DIR__ . '/includes/AuthSecurity.php';
require_once __DIR__ . '/includes/Locale.php';
// Validate the header globally, including on unauthenticated requests.
appRequestLocale();

/*
|--------------------------------------------------------------------------
| Router
|--------------------------------------------------------------------------
*/
$requestUri = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$basePath = '/appraisalsuite-server/api';
$relativePath = strpos($requestUri, $basePath) === 0
    ? substr($requestUri, strlen($basePath))
    : $requestUri;

if ($relativePath === '') {
    $relativePath = '/';
}

$routes = [
    '/' => function () {
        echo json_encode(["message" => "Welcome to the Appraisal System API 🎯"]);
    },

    // Auth
    '/auth/login'           => 'routes/auth/login.php',
    '/auth/session'         => 'routes/auth/Session.php',
    '/auth/logout'          => 'routes/auth/Logout.php',
    '/auth/logout-all'      => 'routes/auth/LogoutAll.php',
    '/auth/workspaces'      => 'routes/auth/Workspaces.php',
    '/auth/switch-workspace' => 'routes/auth/SwitchWorkspace.php',
    '/auth/change-password' => 'routes/auth/ChangePassword.php',
    '/auth/forgot-password' => 'routes/auth/RequestPasswordReset.php',
    '/auth/reset-forgotten-password' => 'routes/auth/ResetForgottenPassword.php',

    // Users
    '/users/list'           => 'routes/users/GetUsers.php',
    '/users/search'           => 'routes/users/SearchUsers.php',
    '/users/single'         => 'routes/users/getSingleUser.php',
    '/users/create'         => 'routes/users/CreateUsers.php',
    '/users/identity-lookup' => 'routes/users/IdentityLookup.php',
    '/users/identity-status' => 'routes/users/IdentityStatus.php',
    '/users/update'         => 'routes/users/UpdateUser.php',
    '/users/bulk-update'    => 'routes/users/BulkUpdateStaff.php',
    '/users/update-profile' => 'routes/users/UpdateProfile.php',
    '/users/my-profile'     => 'routes/users/GetMyProfile.php',
    '/users/preferred-locale' => 'routes/users/UpdatePreferredLocale.php',
    '/users/reset-password' => 'routes/users/ResetPassword.php',
    '/users/password-workspaces' => 'routes/users/PasswordWorkspaces.php',
    '/users/delete'         => 'routes/users/deleteUsers.php',
    '/users/admin-appraisal-visibility' => 'routes/users/AdminAppraisalVisibility.php',
    '/users/company-access' => 'routes/users/AdminCompanyAccess.php',
    '/users/company-access/eligible' => 'routes/users/EligibleCompanyAdmins.php',
    '/users/my-accessible-companies' => 'routes/users/MyAccessibleCompanies.php',

    // Companies (super_admin only)
    '/companies/list'   => 'routes/companies/GetCompanies.php',
    '/companies/search'   => 'routes/companies/SearchCompanies.php',
    '/companies/single'   => 'routes/companies/GetSingleCompany.php',
    '/companies/create' => 'routes/companies/CreateCompany.php',
    '/companies/setup-copy' => 'routes/companies/CopyCompanySetup.php',
    '/companies/update' => 'routes/companies/UpdateCompany.php',
    '/companies/delete' => 'routes/companies/DeleteCompanies.php',

    // Appraisal Cycles
    '/cycles/list'   => 'routes/cycles/GetCycles.php',
    '/cycles/single' => 'routes/cycles/GetSingleCycle.php',
    '/cycles/search' => 'routes/cycles/SearchCycles.php',
    '/cycles/create' => 'routes/cycles/CreateCycle.php',
    '/cycles/update' => 'routes/cycles/UpdateCycle.php',
    '/cycles/delete' => 'routes/cycles/DeleteCycles.php',
    '/cycles/copy'   => 'routes/cycles/CopyCycle.php',
    '/cycles/questions-copy' => 'routes/cycles/CopyQuestions.php',
    '/cycles/scoring-config' => 'routes/cycles/ScoringConfig.php',

    // Super Admin global translation memory (central CSRF gate on writes)
    '/translations/manage' => 'routes/translations/ManageTranslations.php',
    '/translations/bulk' => 'routes/translations/BulkTranslations.php',

    // Departments
    '/departments/list'   => 'routes/departments/GetDepartments.php',
    '/departments/search' => 'routes/departments/SearchDepartments.php',
    '/departments/single' => 'routes/departments/GetSingleDepartment.php',
    '/departments/create' => 'routes/departments/CreateDepartment.php',
    '/departments/update' => 'routes/departments/UpdateDepartment.php',
    '/departments/delete' => 'routes/departments/DeleteDepartments.php',


    // Bulk question routes
    '/general-questions/bulk-create' => 'routes/general-questions/BulkCreateGeneralQuestions.php',
    '/general-questions/bulk-update' => 'routes/general-questions/BulkUpdateGeneralQuestions.php',
    '/kpi-questions/bulk-create'     => 'routes/kpi-questions/BulkCreateKpiQuestions.php',
    '/kpi-questions/bulk-update'     => 'routes/kpi-questions/BulkUpdateKpiQuestions.php',
    '/kpi-questions/set-weights'      => 'routes/kpi-questions/SetKpiQuestionWeights.php',


    // Sections
    '/sections/list'   => 'routes/sections/GetSections.php',
    '/sections/search' => 'routes/sections/SearchSections.php',
    '/sections/single' => 'routes/sections/GetSingleSection.php',
    '/sections/create' => 'routes/sections/CreateSection.php',
    '/sections/update' => 'routes/sections/UpdateSection.php',
    '/sections/delete' => 'routes/sections/DeleteSections.php',

    // KPI Questions
    '/kpi-questions/groups' => 'routes/kpi-questions/GetKpiQuestionGroups.php',
    '/kpi-questions/group'  => 'routes/kpi-questions/GetKpiQuestionGroup.php',
    '/kpi-questions/list'   => 'routes/kpi-questions/GetKpiQuestions.php',
    '/kpi-questions/search' => 'routes/kpi-questions/SearchKpiQuestions.php',
    '/kpi-questions/single' => 'routes/kpi-questions/GetSingleKpiQuestion.php',
    '/kpi-questions/create' => 'routes/kpi-questions/CreateKpiQuestion.php',
    '/kpi-questions/update' => 'routes/kpi-questions/UpdateKpiQuestion.php',
    '/kpi-questions/delete' => 'routes/kpi-questions/DeleteKpiQuestions.php',


    // KPI assignments
    '/kpi-assignments/get' => 'routes/kpi-assignments/GetKpiAssignments.php',
    '/kpi-assignments/set' => 'routes/kpi-assignments/SetKpiAssignments.php',
    '/objective-plans/get' => 'routes/objective-plans/GetObjectivePlan.php',
    '/objective-plans/save' => 'routes/objective-plans/SaveObjectivePlan.php',
    '/objective-plans/unlock' => 'routes/objective-plans/UnlockObjectivePlan.php',


    // General Questions
    '/general-questions/groups' => 'routes/general-questions/GetGeneralQuestionGroups.php',
    '/general-questions/group'  => 'routes/general-questions/GetGeneralQuestionGroup.php',
    '/general-questions/list'   => 'routes/general-questions/GetGeneralQuestions.php',
    '/general-questions/search' => 'routes/general-questions/SearchGeneralQuestions.php',
    '/general-questions/single' => 'routes/general-questions/GetSingleGeneralQuestion.php',
    '/general-questions/create' => 'routes/general-questions/CreateGeneralQuestion.php',
    '/general-questions/update' => 'routes/general-questions/UpdateGeneralQuestion.php',
    '/general-questions/delete' => 'routes/general-questions/DeleteGeneralQuestions.php',

    // Supervisor Management
    '/supervisors/list'         => 'routes/supervisors/GetSupervisors.php',
    '/supervisors/single'       => 'routes/supervisors/GetSingleSupervisor.php',
    '/supervisors/search'       => 'routes/supervisors/SearchSupervisors.php',
    '/supervisors/onboard'      => 'routes/supervisors/Onboard.php',
    '/supervisors/reset-onboarding' => 'routes/supervisors/ResetOnboarding.php',
    '/supervisors/subordinates' => 'routes/supervisors/GetSubordinates.php',
    '/supervisors/assign'       => 'routes/supervisors/AssignSubordinates.php',
    '/supervisors/unassign'     => 'routes/supervisors/UnassignSubordinates.php',
    '/supervisors/assignment-staff' => 'routes/supervisors/GetAssignmentStaff.php',

    // Appraisals
    '/appraisals/list'            => 'routes/appraisals/GetAppraisals.php',
    '/appraisals/single'          => 'routes/appraisals/GetSingleAppraisal.php',
    '/appraisals/candidates'      => 'routes/appraisals/GetAppraisalCandidates.php',
    '/appraisals/form-data'       => 'routes/appraisals/GetAppraisalFormData.php',
    '/appraisals/staff-profile'   => 'routes/appraisals/SetStaffScoringProfile.php',
    '/appraisals/staff-profile/bulk' => 'routes/appraisals/BulkSetStaffScoringProfile.php',
    '/appraisals/create'          => 'routes/appraisals/CreateAppraisal.php',
    '/appraisals/update'          => 'routes/appraisals/UpdateAppraisal.php',
    '/appraisals/restore-updates' => 'routes/appraisals/RestoreAppraisalUpdates.php',
    '/appraisals/submit-feedback' => 'routes/appraisals/SubmitFeedback.php',
    '/appraisals/management-review' => 'routes/appraisals/ManagementReview.php',
    '/appraisals/verify-breach' => 'routes/appraisals/VerifyBreach.php',
    '/appraisals/management-follow-up-response' => 'routes/appraisals/ManagementFollowUpResponse.php',

    // Exports
    '/exports/appraisals/excel' => 'routes/exports/AppraisalExcelExport.php',
    '/exports/management/appraisals/excel' => 'routes/exports/ManagementAppraisalExcelExport.php',
    '/exports/users/excel'      => 'routes/exports/UsersExcelExport.php',
    '/exports/supervisors/excel' => 'routes/exports/SupervisorsExcelExport.php',

    // Manual Email
    '/mail/recipients' => 'routes/mail/GetMailRecipients.php',
    '/mail/send' => 'routes/mail/SendManualEmail.php',
    '/mail/delivery-settings' => 'routes/mail/DeliverySettings.php',
    '/mail/delivery-settings/test' => 'routes/mail/DeliveryTest.php',

    // Legacy endpoint - optional compatibility route
    '/mail/pending-acknowledgements' => 'routes/mail/GetPendingAcknowledgementStaff.php',

    // Audit
    '/audit-log/list' => 'routes/audit/GetAuditLog.php',

    // Notifications
    '/notifications/list' => 'routes/notifications/ListNotifications.php',
    '/notifications/open' => 'routes/notifications/GetNotificationTarget.php',
    '/notifications/pulse' => 'routes/notifications/NotificationPulse.php',
    '/notifications/read' => 'routes/notifications/MarkNotificationsRead.php',

    // Search
    '/search/global' => 'routes/search/GlobalSearch.php',
    '/search/interpret' => 'routes/search/InterpretSearch.php',

    // AI (authenticated; Super Admin alone manages feature switches)
    '/ai/status' => 'routes/ai/Status.php',
    '/ai/navigation' => 'routes/ai/NavigationStatus.php',
    '/ai/ask'    => 'routes/ai/Ask.php',
    '/ai/conversations' => 'routes/ai/Conversations.php',
    '/ai/conversations/messages' => 'routes/ai/ConversationMessages.php',
    '/ai/admin/features' => 'routes/ai/AdminFeatureSettings.php',
    '/ai/admin/budget' => 'routes/ai/AdminBudget.php',
    '/ai/analytics' => 'routes/ai/Analytics.php',
    '/ai/analytics/excel' => 'routes/ai/ExportAnalytics.php',
    '/ai/fairness-check' => 'routes/ai/FairnessCheck.php',
    '/ai/briefing' => 'routes/ai/CycleBriefing.php',
    '/ai/feedback-summary' => 'routes/ai/FeedbackSummary.php',
    '/ai/explain' => 'routes/ai/ExplainAppraisal.php',
    '/ai/reminder-draft' => 'routes/ai/ReminderDraft.php',
    '/ai/insights' => 'routes/ai/ManagementInsights.php',
    '/ai/insights/excel' => 'routes/ai/ExportManagementInsights.php',
    '/ai/writing' => 'routes/ai/WritingAssistant.php',
    '/ai/translate-free-text' => 'routes/ai/TranslateFreeText.php',
    '/ai/development-plan' => 'routes/ai/DevelopmentPlan.php',
    '/ai/setup-guide' => 'routes/ai/SetupGuide.php',
    '/ai/kpi-suggestions' => 'routes/ai/KpiSuggestions.php',

    // Dashboard
    '/dashboard' => 'routes/dashboard/GetDashboard.php',
];

if (!array_key_exists($relativePath, $routes)) {
    http_response_code(404);
    echo json_encode([
        'status'  => 'Failed',
        'message' => 'Route not found.',
        'message_key' => 'core.routeMissing',
        'message_params' => new stdClass(),
    ]);
    ob_end_flush();
    exit;
}

/*
|--------------------------------------------------------------------------
| Central security gates
|--------------------------------------------------------------------------
| /auth/session manages its own optional authentication check so that an
| unauthenticated login-page session probe returns 401 rather than becoming a
| router-level failure. /auth/change-password must remain reachable by a
| logged-in staff/supervisor whose password change is required.
*/
$publicRoutes = [
    '/',
    '/auth/login',
    '/auth/forgot-password',
    '/auth/reset-forgotten-password',
    '/companies/list',
];

$routeManagedAuth = [
    '/auth/session',
    '/auth/logout',
    '/auth/logout-all',
    '/auth/change-password',
];

$passwordGateExempt = [
    '/',
    '/auth/login',
    '/auth/session',
    '/auth/logout',
    '/auth/logout-all',
    '/auth/change-password',
    // Allow a first-login user to choose their UI language before the password form.
    // This route still requires authentication and CSRF on PUT.
    '/users/preferred-locale',
    // A first-login user may read their own password's company list.
    // The endpoint independently rejects other-user lookups while forced.
    '/users/password-workspaces',
    '/companies/list',
];

$csrfExemptRoutes = [
    '/',
    '/auth/login',
    '/auth/forgot-password',
    '/auth/reset-forgotten-password',
    '/auth/session',
    '/companies/list',
];

/*
 * Apply CSRF protection centrally to every non-exempt mutation.
 * GET requests pass through the helper unchanged.
 */
if (!in_array($relativePath, $csrfExemptRoutes, true)) {
    requireCsrfForMutation();
}

if (
    !in_array($relativePath, $publicRoutes, true)
    && !in_array($relativePath, $routeManagedAuth, true)
) {
    require_once __DIR__ . '/includes/authMiddleware.php';

    $guardUser = authenticateUser();
    $guardRole = authRoleKey($guardUser['role'] ?? '');

    if (
        in_array($guardRole, ['admin', 'supervisor', 'staff'], true)
        && (int)($guardUser['must_change_password'] ?? 0) === 1
        && !in_array($relativePath, $passwordGateExempt, true)
    ) {
        http_response_code(428);
        echo json_encode([
            'status'  => 'Failed',
            'code'    => 'PASSWORD_CHANGE_REQUIRED',
            'message' => 'Password update required before continuing.',
            'message_key' => 'auth.mustChangePassword',
            'message_params' => new stdClass(),
        ]);
        ob_end_flush();
        exit;
    }
}

// Require a durable execution receipt for non-repeatable writes. Authentication
// and CSRF validation have already succeeded before a receipt is claimed.
$idempotencyReceiptId = null;
$idempotencyProtectedRoutes = [
    '/appraisals/create',
    '/appraisals/update',
    '/appraisals/restore-updates',
    '/appraisals/submit-feedback',
    '/appraisals/management-review',
    '/appraisals/verify-breach',
    '/appraisals/management-follow-up-response',
    '/mail/send',
];
if (
    in_array($relativePath, $idempotencyProtectedRoutes, true)
    && in_array(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['POST', 'PUT'], true)
) {
    require_once __DIR__ . '/includes/Idempotency.php';
    $submission = idempotencyBegin($conn, (int)$guardUser['id'], $relativePath, (string)file_get_contents('php://input'));
    if (!empty($submission['replay'])) {
        http_response_code($submission['status']);
        echo $submission['body'];
        ob_end_flush();
        exit;
    }
    $idempotencyReceiptId = $submission['id'];
}

$route = $routes[$relativePath];

if (is_callable($route)) {
    $route();
} else {
    require __DIR__ . '/' . $route;
}

if ($idempotencyReceiptId !== null) {
    idempotencyFinish($conn, $idempotencyReceiptId, (int)http_response_code(), (string)ob_get_contents());
}

ob_end_flush();
exit;
