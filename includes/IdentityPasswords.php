<?php
/**
 * Batch 4: password changes operate on a person-level identity; each users.id
 * remains a separate company membership. Company Access is NOT a membership.
 * The caller owns the transaction for password writes and all company audits.
 */
declare(strict_types=1);
require_once __DIR__ . '/UserIdentity.php';
require_once __DIR__ . '/CompanyUserWrite.php';

class IdentityPasswordAccessException extends Exception
{
    public string $reasonCode;
    public ?string $companyName;

    public function __construct(string $message, string $reasonCode, ?string $companyName = null, int $status = 403)
    {
        parent::__construct($message, $status);
        $this->reasonCode = $reasonCode;
        $this->companyName = $companyName;
    }
}

/** Includes inactive memberships for Phase 1 dual-write/audit and the own-company notice. */
function identityPasswordMembershipRecords(mysqli $db, int $userId): array
{
    $lookup = userIdentityPrepare($db, 'SELECT identity_id FROM users WHERE id = ? LIMIT 1');
    try {
        $lookup->bind_param('i', $userId);
        userIdentityExecute($lookup);
        $owner = $lookup->get_result()->fetch_assoc();
    } finally {
        $lookup->close();
    }
    if (!$owner) return [];
    $identityId = (int)($owner['identity_id'] ?? 0);
    $sql = 'SELECT u.id, u.identity_id, u.company_id, u.is_active,
        u.staff_type, r.name AS role, c.name AS company_name,
        c.is_active AS company_is_active
        FROM users AS u
        INNER JOIN roles AS r ON r.id = u.role_id
        INNER JOIN companies AS c ON c.id = u.company_id
        WHERE ' . ($identityId > 0 ? 'u.identity_id = ?' : 'u.id = ? AND u.identity_id IS NULL') . '
        ORDER BY c.name, u.id';
    $stmt = userIdentityPrepare($db, $sql);
    try {
        $lookupId = $identityId > 0 ? $identityId : $userId;
        $stmt->bind_param('i', $lookupId);
        userIdentityExecute($stmt);
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } finally {
        $stmt->close();
    }
}

function identityPasswordCompanyNames(array $memberships): array
{
    $names = [];
    foreach ($memberships as $member) {
        $companyId = (int)($member['company_id'] ?? 0);
        if ($companyId > 0) $names[$companyId] = (string)$member['company_name'];
    }
    return array_values($names);
}

/**
 * Pure permission rule used for each active membership. Admins cannot reset
 * another admin or Super Admin, including via a cross-company manage grant.
 * The home-company staff-scope rule mirrors UpdateUser.php.
 */
function identityPasswordAdminCanManageMember(array $actor, array $member, ?array $grant): bool
{
    $role = authRoleKey($member['role'] ?? '');
    if (in_array($role, ['admin', 'super_admin'], true)) return false;
    $homeId = (int)($actor['company_id'] ?? 0);
    if ((int)$member['company_id'] === $homeId) {
        $scope = (string)($actor['staff_scope'] ?? 'All');
        return $scope === 'All' || $role !== 'staff'
            || ($member['staff_type'] !== null && $member['staff_type'] === $scope);
    }
    if (!$grant || ($grant['access_level'] ?? '') !== 'manage') return false;
    $scope = (string)($grant['staff_scope'] ?? '');
    return $scope === 'All' || (in_array($scope, ['Local', 'Expatriate'], true)
        && ($member['staff_type'] ?? null) === $scope);
}

/**
 * Do NOT use the front end's company name/roles to authorize anything.
 * Re-evaluate live grants for every active membership; never silently reset
 * another company's account through a single-company admin session.
 */
function identityPasswordAssertAdminCanReset(mysqli $db, array $actor, array $memberships, int $targetUserId): void
{
    $actorRole = authRoleKey($actor['role_key'] ?? $actor['role'] ?? '');
    if ($actorRole === 'super_admin') return;
    if ($actorRole !== 'admin') throw new Exception('You cannot reset passwords.', 403);

    $context = companyUserWriteContext($actor);
    $target = null;
    foreach ($memberships as $member) {
        if ((int)$member['id'] === $targetUserId) { $target = $member; break; }
    }
    if (!$target) throw new Exception('User not found.', 404);
    // For the selected target use the existing View Context write guard.
    companyUserWriteAssertTarget($actor, $context, $target);
    if (in_array(authRoleKey($target['role']), ['admin', 'super_admin'], true)) {
        throw new IdentityPasswordAccessException('You cannot reset another administrator account.', 'IDENTITY_RESET_ADMIN_PROTECTED');
    }

    foreach ($memberships as $member) {
        // Access must cover every ACTIVE membership in an ACTIVE company.
        if ((int)$member['is_active'] !== 1 || (int)$member['company_is_active'] !== 1) continue;
        $companyId = (int)$member['company_id'];
        $grant = $companyId === (int)$actor['company_id']
            ? null : companyAccessFetchActiveGrant($db, (int)$actor['id'], $companyId);
        if (!identityPasswordAdminCanManageMember($actor, $member, $grant)) {
            $companyName = (string)$member['company_name'];
            // The error explains why a shared password cannot be changed from
            // one membership. Do not disclose the affected person's credentials.
            throw new IdentityPasswordAccessException(
                "Password reset denied: this account is also used in {$companyName}. You must be authorized to manage every active membership.",
                'IDENTITY_RESET_DENIED', $companyName
            );
        }
    }
}

/** Records exactly one audit item per affected company, all in the same transaction. */
function identityPasswordAuditAllInTransaction(
    mysqli $db, array $memberships, int $actorId, string $action, string $description
): void {
    $seen = [];
    $stmt = userIdentityPrepare($db, 'INSERT INTO audit_log
        (company_id, user_id, action, target_table, target_id, description)
        VALUES (?, ?, ?, ?, ?, ?)');
    try {
        $targetTable = 'users';
        foreach ($memberships as $member) {
            $companyId = (int)$member['company_id'];
            if (isset($seen[$companyId])) continue;
            $seen[$companyId] = true;
            $targetId = (int)$member['id'];
            $stmt->bind_param('iissis', $companyId, $actorId, $action, $targetTable, $targetId, $description);
            userIdentityExecute($stmt);
        }
    } finally {
        $stmt->close();
    }
}

/** Transitional memberships awaiting personnel review still have per-row passwords. */
function identityPasswordUnlinkedUpdateInTransaction(
    mysqli $db, int $userId, string $hash, bool $mustChange, int $actorId
): void {
    if (strlen($hash) > 255 || (password_get_info($hash)['algoName'] ?? 'unknown') === 'unknown') {
        throw new InvalidArgumentException('Expected a hashed password.');
    }
    $stmt = userIdentityPrepare($db, 'UPDATE users SET password_hash = ?, must_change_password = ?,
        password_changed_at = CASE WHEN ? = 1 THEN NULL ELSE NOW() END,
        token_version = COALESCE(token_version, 0) + 1, updated_by = ?
        WHERE id = ? AND identity_id IS NULL');
    try {
        $flag = $mustChange ? 1 : 0;
        $stmt->bind_param('siiii', $hash, $flag, $flag, $actorId, $userId);
        userIdentityExecute($stmt);
        if ($stmt->affected_rows !== 1) throw new RuntimeException('Unlinked membership password update was not applied.');
    } finally {
        $stmt->close();
    }
}

function identityPasswordCurrentHash(mysqli $db, int $userId, int $identityId): ?string
{
    if ($identityId > 0) {
        $identity = userIdentityFindById($db, $identityId, true);
        return $identity === null ? null : (string)$identity['password_hash'];
    }
    $stmt = userIdentityPrepare($db, 'SELECT password_hash FROM users WHERE id = ? AND identity_id IS NULL AND is_active = 1 LIMIT 1 FOR UPDATE');
    try {
        $stmt->bind_param('i', $userId);
        userIdentityExecute($stmt);
        $row = $stmt->get_result()->fetch_assoc();
        return $row ? (string)$row['password_hash'] : null;
    } finally {
        $stmt->close();
    }
}

function identityPasswordWriteInTransaction(mysqli $db, int $userId, int $identityId, string $hash, bool $mustChange, int $actorId): void
{
    if ($identityId > 0) {
        userIdentityUpdatePasswordInTransaction($db, $identityId, $hash, $mustChange, $actorId);
    } else {
        identityPasswordUnlinkedUpdateInTransaction($db, $userId, $hash, $mustChange, $actorId);
    }
}
