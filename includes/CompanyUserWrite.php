<?php
/**
 * Batch 5A: administrative user writes, separate from personal profile writes.
 * Do not use this for an employee's own profile, appraisal or team actions.
 * Company and grant checks are live and made server-side on every request.
 */
require_once __DIR__ . '/CompanyAccess.php';

function companyUserWriteContext(array $actor): array
{
    $context = resolveActingCompanyContext($actor);
    requireCompanyWriteAccess($context);
    return $context;
}

/** Super Admin retains the original body-selected company creation flow. */
function companyUserWriteCreateCompanyId(array $actor, array $context, array $data): int
{
    if (companyAccessRoleKey($actor['role_key'] ?? $actor['role'] ?? '') === 'super_admin') {
        return isset($data['company_id']) ? (int)$data['company_id'] : (int)($actor['company_id'] ?? 0);
    }
    // Never trust a client-supplied company_id for an ordinary admin.
    return (int)($context['company_id'] ?? 0);
}

function companyUserWriteIsGranted(array $context): bool
{
    return empty($context['is_home']) && in_array($context['access_level'] ?? null, ['view', 'manage'], true);
}

/** The granted scope covers every target role, not only ordinary staff. */
function companyUserWriteAssertStaffType(array $context, $staffType): void
{
    if (!companyUserWriteIsGranted($context)) return;
    $scope = (string)($context['staff_scope'] ?? '');
    if ($scope === 'All') return;
    if (!in_array($scope, ['Local', 'Expatriate'], true)
        || (string)$staffType !== $scope) {
        throw new Exception('Unauthorized: This employee is outside your granted staff scope.', 403);
    }
}

function companyUserWriteAssertTarget(array $actor, array $context, array $target): void
{
    $actorRole = companyAccessRoleKey($actor['role_key'] ?? $actor['role'] ?? '');
    if ($actorRole === 'super_admin') return;

    if ((int)($target['company_id'] ?? 0) !== (int)($context['company_id'] ?? 0)) {
        throw new Exception('Unauthorized: This user does not belong to your selected company.', 403);
    }
    companyUserWriteAssertStaffType($context, $target['staff_type'] ?? null);
}

/** Prevent Local->Expatriate or Expatriate->Local reassignment via an in-scope user. */
function companyUserWriteAssertUpdatedType(array $context, array $data): void
{
    if (array_key_exists('staff_type', $data)) {
        companyUserWriteAssertStaffType($context, $data['staff_type']);
    }
}

/**
 * For granted-company mutations the caller MUST be in a transaction and
 * must roll it back if this audit operation fails.
 */
function companyUserWriteAudit(
    mysqli $db, array $actor, int $dataCompanyId, string $action,
    int $targetId, string $details
): void {
    $actorId = (int)($actor['id'] ?? 0);
    $homeId = (int)($actor['company_id'] ?? 0);
    $homeName = trim((string)($actor['company_name'] ?? ''));
    if ($homeName === '') $homeName = 'company #' . $homeId;
    $email = (string)($actor['email'] ?? 'Account');
    $description = "{$email} {$details}; actor home company: {$homeName} (#{$homeId})";
    $table = 'users';
    $stmt = $db->prepare('INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description) VALUES (?, ?, ?, ?, ?, ?)');
    if (!$stmt) throw new Exception('Unable to prepare user write audit.', 500);
    $stmt->bind_param('iissis', $dataCompanyId, $actorId, $action, $table, $targetId, $description);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) throw new Exception('Unable to record user write audit.', 500);
}
