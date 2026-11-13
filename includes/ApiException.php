<?php
/**
 * API message metadata. English remains the canonical server error and log text.
 * Only explicitly registered messages are given a localisation key; the rest
 * continue to return their existing English text.
 */
final class ApiException extends RuntimeException
{
    private string $messageKey;
    private array $messageParams;

    public function __construct(string $messageKey, array $messageParams, int $status, string $englishMessage = 'Request failed.')
    {
        parent::__construct($englishMessage, $status);
        $this->messageKey = $messageKey;
        $this->messageParams = $messageParams;
    }

    public function messageKey(): string { return $this->messageKey; }
    public function messageParams(): array { return $this->messageParams; }
}

function apiMessageMeta(string $englishMessage, int $status = 200): array
{
    if ($status >= 500) return [];
    static $keys = [
        "Choose Microsoft 365 or SMTP." => "mail.providerChoice",
        "Your account has no deliverable email address. Enter an address to send the test to." => "mail.senderMissing",
        "Email delivery settings were not found. Apply migration 19." => "mail.deliverySettingsUnavailable",
        "Unable to update email delivery." => "mail.deliverySaveFailed",
        "You do not have access to that company." => "company.accessRevoked",
        "Enter a valid email address." => "auth.invalidEmail",
        "Password is required." => "auth.passwordRequired",
        "Invalid workspace selected." => "auth.workspaceInvalid",
        "Too many failed sign-in attempts. Please wait 15 minutes and try again." => "auth.rateLimited",
        "Invalid email or password." => "auth.badCredentials",
        "Your email is linked to more than one organisation. Select a workspace to continue." => "auth.selectWorkspace",
        "Login successful." => "auth.loginSuccess",
        "Current password is required." => "auth.currentRequired",
        "New password is required." => "auth.newRequired",
        "Please confirm your new password." => "auth.confirmRequired",
        "New password and confirmation do not match." => "auth.confirmMismatch",
        "New password must be at least 8 characters long." => "auth.passwordTooShort",
        "New password must be different from your current password." => "auth.passwordSame",
        "Current password is incorrect." => "auth.currentIncorrect",
        "Password updated successfully. You can now continue." => "auth.passwordSuccess",
        "Password update required before continuing." => "auth.mustChangePassword",
        "Choose a supported language: en or fr." => "auth.languageInvalid",
        "Language preference updated." => "auth.languageSaved",
        "Invalid security token. Refresh the page and try again." => "auth.securityToken",
        "Route not found." => "core.routeMissing",
        "Invalid request format. Expected JSON object." => "core.jsonInvalid",
        "Invalid request format." => "core.requestInvalid",
        "Invalid request body." => "core.requestBodyInvalid",
        "Expected a JSON object." => "core.requestJsonExpected",
        "Unauthorized." => "core.permissionDenied",
        "Appraisal not found." => "appraisal.notFound",
        "Selected appraisal cycle was not found or is outside your company scope." => "appraisal.cycleNotFound",
        "Staff member was not found for this appraisal cycle." => "appraisal.staffNotFound",
        "Unauthorized: This employee is outside your staff scope." => "appraisal.outOfScope",
        "Unauthorized: Only a user configured as a supervisor can start an appraisal." => "appraisal.onlySupervisor",
        "Assign this employee to a supervisor before starting the appraisal." => "appraisal.assignFirst",
        "This employee is not assigned to you for this appraisal cycle." => "appraisal.notAssigned",
        "Complete supervisor onboarding before starting this appraisal." => "appraisal.onboardingFirst",
        "This staff member already has an appraisal for the selected cycle." => "appraisal.duplicate",
        "Appraisal created successfully" => "appraisal.created",
        "Appraisal updated successfully" => "appraisal.updated",
        "Unauthorized: This appraisal does not belong to your company." => "appraisal.companyDenied",
        "Unauthorized: This appraisal is outside your staff scope." => "appraisal.scopeDenied",
        "Unauthorized: Only the assigned supervisor can update this appraisal." => "appraisal.supervisorOnly",
        "This employee is no longer assigned to you for the appraisal cycle." => "appraisal.assignmentChanged",
        "Complete supervisor onboarding before editing this appraisal." => "appraisal.onboardingEdit",
        "You have reached the maximum number of updates for this appraisal. Please contact an administrator for further changes." => "appraisal.maxUpdates",
        "No update attempts remain. Ask an administrator to restore your update allowance." => "appraisal.noUpdates",
        "Unable to save appraisal due to a server error. Please try again or contact support." => "appraisal.saveFailed",
        "Please acknowledge that you have seen and confirmed this appraisal before submitting feedback." => "appraisal.ackRequired",
        "Field 'feedback' is required." => "appraisal.feedbackRequired",
        "Unauthorized: You can only submit feedback on your own appraisal." => "appraisal.notOwn",
        "This appraisal has already been acknowledged." => "appraisal.ackAlready",
        "Feedback submitted successfully" => "appraisal.feedbackSuccess",
        "Provide an administrative reason between 10 and 1,000 characters." => "appraisal.restoreReason",
        "This appraisal still has update attempts remaining." => "appraisal.restoreUnneeded",
        "Two update attempts restored for the supervisor." => "appraisal.restoreSuccess",
        "Unable to restore update attempts. Please contact support." => "appraisal.restoreFailed",
        "You are not allowed to prepare this employee's objective plan." => "objective.prepareDenied",
        "Only the assigned supervisor/HOD can lock this employee's objective plan." => "objective.lockDenied",
        "Per-staff objective setup is not enabled for this KPI section." => "objective.disabled",
        "Selected appraisal level/profile is not valid for this cycle." => "objective.profileInvalid",
        "Select the employee appraisal level/profile before locking objectives." => "objective.profileRequired",
        "Objectives cannot be changed after the appraisal has been submitted." => "objective.submitted",
        "This objective plan is locked. Unlock it before making changes." => "objective.locked",
        "The same objective cannot be selected more than once." => "objective.duplicate",
        "One selected objective is inactive or not available for this employee." => "objective.unavailable",
        "Individual objectives are disabled for this section." => "objective.individualDisabled",
        "Individual objective text is required." => "objective.individualTextRequired",
        "Objective weight must be numeric." => "objective.weightNumeric",
        "Each objective weight must be greater than 0 and no more than 100%." => "objective.weightRange",
        "A target/measure is required for every selected objective before locking." => "objective.targetRequired",
        "An evidence source is required for every selected objective before locking." => "objective.evidenceRequired",
        "A weight is required for every selected objective before locking." => "objective.weightRequired",
        "Objective draft saved successfully." => "objective.saved",
        "Objectives locked successfully." => "objective.lockSuccess",
        "Only the assigned supervisor/HOD can unlock this employee's objective plan." => "objective.unlockDenied",
        "Objectives cannot be unlocked after the appraisal has been submitted." => "objective.unlockSubmitted",
        "No objective plan exists for this employee." => "objective.planMissing",
        "Objective plan is already editable." => "objective.alreadyEditable",
        "Enter a valid UTF-8 reason for unlocking submitted objectives." => "objective.unlockReasonInvalid",
        "Explain the change in 5 to 500 characters." => "objective.unlockReasonLength",
        "The appraisal level cannot be changed after submission." => "objective.profileFrozen",
        "Unlock the submitted objective plan before making changes." => "objective.unlockFirst",
        "Objectives have changed. Re-rate them before updating the appraisal." => "objective.reratePending",
        "Lock the objective plan before updating the appraisal." => "objective.lockBeforeUpdate",
        "The locked objective plan has no objectives." => "objective.planEmpty",
        "The objective plan changed while this form was open. Reload the appraisal before updating." => "objective.planStale",
        "Submitted objectives must match the locked objective plan." => "objective.planMismatch",
        "The objective-change audit is missing. Recheck the locked plan before updating." => "objective.auditMissing",
        "You cannot perform management review on your own appraisal." => "review.ownAppraisal",
        "Unauthorized: You are not permitted to review this appraisal." => "review.notAllowed",
        "Unauthorized: Management review is restricted to administrators." => "review.adminOnly",
        "Management review is not enabled for this appraisal cycle." => "review.disabled",
        "There is already an unresolved management follow-up for this appraisal. Wait for the supervisor/HOD response or resolve the existing follow-up first." => "review.unresolved",
        "Invalid management review category." => "review.categoryInvalid",
        "A management note is required when follow-up is requested." => "review.noteRequired",
        "Management note cannot exceed 5,000 characters." => "review.noteLong",
        "The follow-up being resolved could not be found." => "review.followupMissing",
        "This follow-up has already been resolved." => "review.followupResolved",
        "Management review saved successfully." => "review.saved",
        "Please enter a response to the management follow-up." => "review.responseRequired",
        "Follow-up response cannot exceed 5,000 characters." => "review.responseLong",
        "Only the supervisor/HOD who conducted this appraisal can respond to this follow-up." => "review.responseDenied",
        "This management follow-up has already been resolved." => "review.responseResolved",
        "Your response has been sent to management." => "review.responseSent",
        "Appraisal and current revision are required." => "breach.revisionRequired",
        "Decision must be Confirmed or Dismissed." => "breach.decisionInvalid",
        "A management decision reason is required." => "breach.reasonRequired",
        "Decision reason must not exceed 5,000 characters." => "breach.reasonLong",
        "A manager cannot verify an appraisal they received or personally conducted." => "breach.managerConflict",
        "This appraisal has no outstanding serious-breach declaration." => "breach.none",
        "This appraisal was revised. Reload it and verify the latest version." => "breach.revised",
        "This decision has already been recorded. Refresh the appraisal to review it." => "breach.recorded",
        "Safety/integrity decision recorded." => "breach.success",
        "Unable to save the safety decision. Contact support." => "breach.saveFailed",
        "Company access granted successfully." => "company.granted",
        "Company access updated successfully." => "company.updated",
        "Company access revoked successfully." => "company.revoked",
        "Invalid grant status filter." => "company.grantFilterInvalid",
        "Maximum page size is 250." => "company.pageLimit",
        "A maximum of 20 unique CC addresses is allowed." => "mail.ccMax",
        "Subject and message are required." => "mail.subjectRequired",
        "Enter at least one valid email address." => "mail.emailRequired",
        "Unsupported recipient type." => "mail.modeInvalid",
        "Select at least one recipient." => "mail.recipientRequired",
        "Some selected recipients are not eligible for this communication or company." => "mail.recipientDenied",
        "No eligible recipients were found for this communication." => "mail.recipientMissing",
        "Enter a valid email address to send the test to." => "mail.emailTestRequired",
        "You have sent 10 test emails in the last hour. Please wait a little before sending another." => "mail.emailTestLimit",
    ];
    if (!isset($keys[$englishMessage])) return [];
    return ['message_key' => $keys[$englishMessage], 'message_params' => new stdClass()];
}

function apiExceptionMeta(Throwable $error, int $status): array
{
    if ($status >= 500) return []; // Never send metadata for unexpected server failures.
    if ($error instanceof ApiException) {
        return [
            'message_key' => $error->messageKey(),
            'message_params' => $error->messageParams() ?: new stdClass(),
        ];
    }
    return apiMessageMeta($error->getMessage(), $status);
}

/** Wrap existing route-specific catches without changing HTTP status or logs. */
function apiErrorPayload(Throwable $error, ?string $englishOverride = null): array
{
    $status = (int)$error->getCode();
    $status = ($status >= 400 && $status <= 599) ? $status : 500;
    $message = $englishOverride ?? $error->getMessage();
    return array_merge(
        ['status' => 'Failed', 'message' => $message],
        $englishOverride === null ? apiExceptionMeta($error, $status) : apiMessageMeta($message, $status)
    );
}

/** Adds a key to known success and non-error responses, if one exists. */
function apiMessagePayload(string $status, string $englishMessage, array $extra = []): array
{
    return array_merge(['status' => $status, 'message' => $englishMessage],
        apiMessageMeta($englishMessage, 200), $extra);
}
