<?php
/**
 * Batch 5C: administrative appraisal writes only. Do not apply this acting
 * company helper to appraisal creation/edits by an assigned supervisor,
 * personal acknowledgements, or supervisor responses to management.
 */
require_once __DIR__ . '/CompanySupervisorWrite.php';
require_once __DIR__ . '/AdminStaffScope.php';

function companyAppraisalWriteContext(array $actor): array
{
    $context = resolveActingCompanyContext($actor);
    requireCompanyWriteAccess($context);
    return $context;
}

/** Company boundary is verified even when the request supplies a valid record ID. */
function companyAppraisalWriteAssertCompany(array $actor, array $context, int $recordCompanyId): void
{
    $role = companyAccessRoleKey($actor['role_key'] ?? $actor['role'] ?? '');
    if ($role === 'super_admin') {
        $selected = $context['company_id'] ?? null;
        if ($selected !== null && (int)$selected !== $recordCompanyId) {
            throw new Exception('This appraisal is outside your selected company.', 403);
        }
        return;
    }
    companySupervisorWriteAssertCompany($actor, $context, $recordCompanyId);
}

/** Scope uses the historical appraisal snapshot, never the current directory type. */
function companyAppraisalWriteAssertSnapshotScope(array $context, array $appraisal): void
{
    companyUserWriteAssertStaffType($context, $appraisal['staff_type'] ?? null);
}

/**
 * Uses the SAVED appraisal staff_type for grants (including admin/supervisor
 * appraisees) and retains current-type/regular-staff restriction at home, except
 * the admin's own assigned appraisees. Peer-admin permission is always from the record company, without a
 * foreign supervisor exception.
 */
function companyAppraisalWriteAssertRecord(
    mysqli $db, array $actor, array $context, array $appraisal
): void {
    $role = companyAccessRoleKey($actor['role_key'] ?? $actor['role'] ?? '');
    $companyId = (int)($appraisal['company_id'] ?? 0);
    companyAppraisalWriteAssertCompany($actor, $context, $companyId);
    if ($role === 'super_admin') return;
    if ($role !== 'admin') throw companyAccessForbidden();

    if (companyUserWriteIsGranted($context)) {
        companyAppraisalWriteAssertSnapshotScope($context, $appraisal);
    } else {
        $subjectId = (int)($appraisal['staff_user_id'] ?? 0);
        $stmt = $db->prepare('SELECT u.id, u.company_id, u.staff_type, r.name AS role_name FROM users u INNER JOIN roles r ON r.id=u.role_id WHERE u.id=? LIMIT 1');
        if (!$stmt) throw new Exception('Unable to check employee staff scope.', 500);
        $stmt->bind_param('i', $subjectId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Unable to check employee staff scope.', 500);
        }
        $subject = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($subject && !staffScopeAllowsSubject($db, $actor, $context, $subject,
            (int)($appraisal['cycle_id'] ?? 0), (int)($appraisal['supervisor_id'] ?? 0))) {
            throw new Exception('This employee is outside your assigned staff scope.', 403);
        }
    }
    companySupervisorWriteAssertPeer($db, $actor, $context,
        (int)($appraisal['staff_user_id'] ?? 0), (int)($appraisal['supervisor_id'] ?? 0));
}

/** Call inside the same transaction as a granted-company mutation. */
function companyAppraisalWriteAudit(
    mysqli $db, array $actor, array $context, int $companyId, string $action,
    string $table, int $recordId, string $details
): void {
    if (companyUserWriteIsGranted($context)) {
        companySupervisorWriteAudit($db, $actor, $companyId, $action, $table, $recordId, $details);
    } else {
        // Preserve existing home and Super Admin audit conventions.
        apAudit($db, $companyId, (int)$actor['id'], $action, $table, $recordId, $details);
    }
}

/** Avoid notification table DDL within a foreign-company write transaction. */
function companyAppraisalWriteNotify(
    mysqli $db, array $context, int $companyId, int $recipientId,
    string $type, string $title, string $message, string $link,
    ?string $messageKey = null, array $messageParams = []
): void {
    if ($recipientId <= 0) return;
    if (companyUserWriteIsGranted($context)) {
        companySupervisorWriteNotification($db, $companyId, $recipientId, $type, $title, $message, $link, $messageKey, $messageParams);
    } else {
        createNotification($db, $companyId, $recipientId, $type, $title, $message, $link, 0, 0, $messageKey, $messageParams);
    }
}
