<?php
/**
 * Batch 5B: Company-aware administrative supervisor/assignment/profile writes.
 * Personal supervisor actions must be resolved using the record's own company,
 * never by the selected View Context company.
 */
require_once __DIR__ . '/CompanyUserWrite.php';
require_once __DIR__ . '/AdminAppraisalVisibility.php';

function companySupervisorWriteContext(array $actor): array
{
    $context = resolveActingCompanyContext($actor);
    requireCompanyWriteAccess($context);
    return $context;
}

/** Super Admin retains existing permissions across companies. */
function companySupervisorWriteAssertCompany(array $actor, array $context, int $recordCompanyId): void
{
    if (companyAccessRoleKey($actor['role_key'] ?? $actor['role'] ?? '') === 'super_admin') return;
    if ($recordCompanyId <= 0 || $recordCompanyId !== (int)($context['company_id'] ?? 0)) {
        throw new Exception('This record is outside your selected company.', 403);
    }
}

/** Scope applies to appraisees of ALL roles, including supervisor/admin. */
function companySupervisorWriteAssertSubject(array $actor, array $context, array $subject): void
{
    companySupervisorWriteAssertCompany($actor, $context, (int)($subject['company_id'] ?? 0));
    companyUserWriteAssertStaffType($context, $subject['staff_type'] ?? null);
}

/** Preserve the existing admin-as-supervisor personal action exception at home. */
function companySupervisorWriteIsOwnTeamAction(array $actor, int $recordCompanyId, int $supervisorId): bool
{
    return companyAccessRoleKey($actor['role_key'] ?? $actor['role'] ?? '') === 'admin'
        && $recordCompanyId === (int)($actor['company_id'] ?? 0)
        && $supervisorId === (int)($actor['id'] ?? 0)
        && function_exists('userCanConductAppraisals')
        && userCanConductAppraisals($actor);
}

/** Admin peer privacy for granted-company appraisal setup/assignments. */
function companySupervisorWriteAssertPeer(
    mysqli $db, array $actor, array $context, int $subjectId, int $supervisorId
): void {
    if (companyAccessRoleKey($actor['role_key'] ?? $actor['role'] ?? '') !== 'admin') return;
    $viewerId = (int)($actor['id'] ?? 0);
    $companyId = (int)($context['company_id'] ?? 0);
    // A supervisor-assignment exemption must never bypass a foreign-company policy.
    $exceptionId = companyUserWriteIsGranted($context) ? 0 : $supervisorId;
    if (!adminCanViewAppraisalSubject($db, $viewerId, $companyId, $subjectId, $exceptionId)) {
        throw new Exception('This employee is outside your appraisal visibility permissions.', 403);
    }
}

/**
 * Home-company appraisal profile staff scope.
 * An admin's Local/Expatriate scope limits what they manage as an admin; it never blocks
 * their OWN appraisees: when this cycle's assigned supervisor is the admin, it is allowed
 * (same rule as includes/AdminStaffScope.php). Granted companies stay strict (returns above).
 */
function companySupervisorWriteAssertHomeProfileScope(array $actor, array $context, $staffType, int $assignedSupervisorId = 0): void
{
    if (companyAccessRoleKey($actor['role_key'] ?? $actor['role'] ?? '') !== 'admin'
        || companyUserWriteIsGranted($context)) return;
    if ($assignedSupervisorId > 0 && $assignedSupervisorId === (int)($actor['id'] ?? 0)) return;
    $scope = trim((string)($actor['staff_scope'] ?? 'All'));
    if (in_array($scope, ['Local', 'Expatriate'], true) && $staffType !== $scope) {
        throw new Exception('This employee is outside your configured staff scope.', 403);
    }
}

/** All granted-company mutations must record an audit within the same transaction. */
function companySupervisorWriteAudit(
    mysqli $db, array $actor, int $companyId, string $action,
    string $targetTable, int $targetId, string $details
): void {
    $actorId = (int)($actor['id'] ?? 0);
    $homeId = (int)($actor['company_id'] ?? 0);
    $homeName = trim((string)($actor['company_name'] ?? ''));
    if ($homeName === '') $homeName = 'company #' . $homeId;
    $description = trim($details) . '; actor home company: ' . $homeName . ' (#' . $homeId . ')';
    $stmt = $db->prepare('INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description) VALUES (?, ?, ?, ?, ?, ?)');
    if (!$stmt) throw new Exception('Unable to prepare write audit.', 500);
    $stmt->bind_param('iissis', $companyId, $actorId, $action, $targetTable, $targetId, $description);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) throw new Exception('Unable to record write audit.', 500);
}

/**
 * Insert an onboarding notification without executing CREATE TABLE inside a
 * grant-write transaction. The caller must invoke ensureNotificationsTable()
 * before beginning the transaction. A failure aborts the grant mutation.
 */
function companySupervisorWriteNotification(
    mysqli $db, int $companyId, int $recipientId,
    string $type, string $title, string $message, string $link,
    ?string $messageKey = null, array $messageParams = []
): void {
    require_once __DIR__ . '/NotificationLocalization.php';
    $jsonParams = appNotificationParams($messageKey, $messageParams);
    // No schema-changing DDL in a granted-company transaction.
    $stmt = $db->prepare($messageKey === null
        ? 'INSERT INTO notifications (company_id, user_id, type, title, message, link_url) VALUES (?, ?, ?, ?, ?, ?)'
        : 'INSERT INTO notifications (company_id, user_id, type, title, message, link_url, message_key, message_params) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) throw new Exception('Unable to prepare notification.', 500);
    if ($messageKey === null) {
        $stmt->bind_param('iissss', $companyId, $recipientId, $type, $title, $message, $link);
    } else {
        $stmt->bind_param('iissssss', $companyId, $recipientId, $type, $title, $message,
            $link, $messageKey, $jsonParams);
    }
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) throw new Exception('Unable to create notification.', 500);
}
