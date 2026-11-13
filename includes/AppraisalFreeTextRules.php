<?php
/** Pure, auditable allow-list and per-field read-visibility rules. */
/** Field identifiers are fixed SQL definitions, never interpolated from input. */
function appraisalFreeTextFieldSql(string $field): ?array
{
    $fields = [
        'feedback' => ['table' => 'appraisals', 'column' => 'feedback', 'key' => 'appraisal'],
        'breach_reason' => ['table' => 'appraisals', 'column' => 'serious_breach_reason', 'key' => 'appraisal'],
        'breach_review_note' => ['table' => 'appraisals', 'column' => 'serious_breach_review_note', 'key' => 'appraisal'],
        'supervisor_status' => ['table' => 'appraisals', 'column' => 'status_upgrade', 'key' => 'appraisal'],
        'supervisor_salary' => ['table' => 'appraisals', 'column' => 'salary_upgrade', 'key' => 'appraisal'],
        'supervisor_development' => ['table' => 'appraisals', 'column' => 'development', 'key' => 'appraisal'],
        'section_comment' => ['table' => 'appraisal_section_scores', 'column' => 'overall_rating_comment', 'key' => 'section_id'],
        'general_comment' => ['table' => 'appraisal_section_responses', 'column' => 'rating_comment', 'key' => 'id'],
        'kpi_comment' => ['table' => 'appraisal_kpi_responses', 'column' => 'rating_comment', 'key' => 'id'],
        'management_note' => ['table' => 'appraisal_management_reviews', 'column' => 'note', 'key' => 'id'],
        'management_response' => ['table' => 'appraisal_management_follow_up_responses', 'column' => 'response', 'key' => 'id'],
        'breach_verification_note' => ['table' => 'appraisal_breach_verifications', 'column' => 'note', 'key' => 'id'],
    ];
    return $fields[$field] ?? null;
}

/** Guard including per-field visibility; ordinary staff never see oversight notes. */
function appraisalFreeTextCanRead(array $appraisal, array $user, string $field): bool
{
    $role = strtolower(str_replace(' ', '_', trim((string)($user['role'] ?? ''))));
    $actor = (int)$user['id'];
    $own = (int)$appraisal['staff_user_id'] === $actor;
    $canManage = (int)($appraisal['can_manage_appraisal'] ?? 0) === 1;
    $conducting = !$own && $canManage && in_array($role, ['admin', 'supervisor'], true);
    $isManagement = in_array($role, ['super_admin', 'admin'], true) && !$own;

    if ($field === 'feedback') {
        return $own || $canManage || in_array($role, ['super_admin', 'admin'], true);
    }
    if (in_array($field, ['breach_review_note', 'breach_verification_note'], true)) {
        return $isManagement || ((int)$appraisal['supervisor_id'] === $actor && !$own);
    }
    if (in_array($field, ['management_note', 'management_response'], true)) {
        return (int)($appraisal['management_review_enabled'] ?? 0) === 1
            && ($isManagement || $conducting);
    }
    return true; // Remaining entries are already visible in the appraisal detail.
}

