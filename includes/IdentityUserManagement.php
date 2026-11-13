<?php
/**
 * Batch 6: person-level user management.  Company Access remains separate.
 * All mutating functions are called INSIDE a caller-owned transaction.
 */
declare(strict_types=1);
require_once __DIR__ . '/UserIdentity.php';
require_once __DIR__ . '/IdentityPasswords.php';
require_once __DIR__ . '/CompanyDirectoryRead.php';

final class IdentityUserManagementException extends Exception
{
    public string $reasonCode;
    public ?string $companyName;
    public function __construct(string $reason, string $message, int $http = 409, ?string $companyName = null)
    {
        parent::__construct($message, $http);
        $this->reasonCode = $reason;
        $this->companyName = $companyName;
    }
}

/** No inferred links for either email still on the migration manual-review queue. */
function identityUserManualReviewEmail(string $email): bool
{
    return in_array(userIdentityNormalizeEmail($email), [
        'h.abinassif@lambertelectromec.com',
        'h.eldine@lambertelectromec.com',
    ], true);
}

function identityUserCanSeeMembership(array $actor, array $member, ?array $grant): bool
{
    if (companyAccessRoleKey($actor['role_key'] ?? $actor['role'] ?? '') === 'super_admin') return true;
    if (companyAccessRoleKey($actor['role_key'] ?? $actor['role'] ?? '') !== 'admin') return false;
    $company = (int)($member['company_id'] ?? 0);
    $home = (int)($actor['company_id'] ?? 0);
    if ($home !== $company && (!$grant || !in_array($grant['access_level'] ?? '', ['view', 'manage'], true))) return false;
    $scope = $home === $company ? ($actor['staff_scope'] ?? 'All') : ($grant['staff_scope'] ?? '');
    if (!in_array($scope, ['All', 'Local', 'Expatriate'], true)) return false;
    return $scope === 'All' || companyAccessRoleKey($member['role'] ?? '') !== 'staff'
        || ($member['staff_type'] ?? null) === $scope;
}

/**
 * Only expose companies which this viewer may access, including staff scope.
 * Use one batch fetch for a paginated directory, not one query per user.
 */
function identityUserAlsoIn(mysqli $db, array $actor, array $users): array
{
    $ids = [];
    foreach ($users as $user) {
        if ((int)($user['identity_id'] ?? 0) > 0) $ids[(int)$user['identity_id']] = (int)$user['identity_id'];
    }
    if (!$ids) return [];
    $params = array_values($ids);
    $placeholders = implode(',', array_fill(0, count($params), '?'));
    $stmt = userIdentityPrepare($db, "SELECT u.id, u.identity_id, u.company_id, u.staff_type,
        u.is_active, c.name AS company_name, c.is_active AS company_is_active, r.name AS role
        FROM users u JOIN companies c ON c.id = u.company_id JOIN roles r ON r.id = u.role_id
        WHERE u.identity_id IN ($placeholders)");
    try {
        $types = str_repeat('i', count($params));
        $stmt->bind_param($types, ...$params);
        userIdentityExecute($stmt);
        $members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } finally { $stmt->close(); }
    $grants = []; $result = [];
    foreach ($users as $user) {
        $currentIdentity = (int)($user['identity_id'] ?? 0);
        $currentId = (int)$user['id'];
        $visible = [];
        foreach ($members as $member) {
            if ((int)$member['identity_id'] !== $currentIdentity || (int)$member['id'] === $currentId
                || (int)$member['is_active'] !== 1 || (int)$member['company_is_active'] !== 1) continue;
            $company = (int)$member['company_id'];
            if (!array_key_exists($company, $grants)) {
                $grants[$company] = $company === (int)($actor['company_id'] ?? 0)
                    || companyAccessRoleKey($actor['role'] ?? '') === 'super_admin'
                    ? null : companyAccessFetchActiveGrant($db, (int)$actor['id'], $company);
            }
            if (identityUserCanSeeMembership($actor, $member, $grants[$company])) {
                $visible[$company] = $member['company_name'];
            }
        }
        $result[$currentId] = array_values($visible);
    }
    return $result;
}

/** In a transaction, lock the target and all memberships of its identity. */
function identityUserMembershipsForWrite(mysqli $db, int $userId): array
{
    // Lock identities BEFORE user rows, matching the password-change path.
    // This avoids lock-order inversions with concurrent credential updates.
    $stmt = userIdentityPrepare($db, 'SELECT id, identity_id FROM users WHERE id = ? LIMIT 1');
    try {
        $stmt->bind_param('i', $userId);
        userIdentityExecute($stmt);
        $original = $stmt->get_result()->fetch_assoc();
    } finally { $stmt->close(); }
    if (!$original) throw new Exception('User not found.', 404);
    $identityId = (int)($original['identity_id'] ?? 0);
    if ($identityId > 0 && !userIdentityFindById($db, $identityId, true)) {
        throw new RuntimeException('Identity missing.');
    }
    $stmt = userIdentityPrepare($db, 'SELECT id, identity_id FROM users WHERE id = ? FOR UPDATE');
    try {
        $stmt->bind_param('i', $userId);
        userIdentityExecute($stmt);
        $locked = $stmt->get_result()->fetch_assoc();
    } finally { $stmt->close(); }
    if (!$locked || (int)($locked['identity_id'] ?? 0) !== $identityId) {
        throw new IdentityUserManagementException('IDENTITY_CHANGED',
            'Login membership changed while editing. Reload the user and try again.');
    }
    // Lock ALL linked memberships and companies, so no role/status/company
    // change can race with the manage-every-membership permission check.
    if ($identityId > 0) userIdentityMemberships($db, $identityId, false, true);
    return identityPasswordMembershipRecords($db, $userId);
}

/** Reject an admin who cannot modify every ACTIVE membership, naming the blocked company. */
function identityUserAssertManageAll(mysqli $db, array $actor, array $members, int $targetUserId,
    bool $requireSelectedCompany = true): void
{
    $actorRole = companyAccessRoleKey($actor['role_key'] ?? $actor['role'] ?? '');
    if ($actorRole === 'super_admin') return;
    if ($actorRole !== 'admin') throw new Exception('You cannot manage sign-in identities.', 403);
    $context = companyUserWriteContext($actor);
    $target = null;
    foreach ($members as $member) if ((int)$member['id'] === $targetUserId) { $target = $member; break; }
    if (!$target) throw new Exception('User not found.', 404);
    if ($requireSelectedCompany) companyUserWriteAssertTarget($actor, $context, $target);
    foreach ($members as $member) {
        if ((int)$member['is_active'] !== 1 || (int)$member['company_is_active'] !== 1) continue;
        $companyId = (int)$member['company_id'];
        $grant = $companyId === (int)$actor['company_id'] ? null
            : companyAccessFetchActiveGrant($db, (int)$actor['id'], $companyId);
        if (!identityPasswordAdminCanManageMember($actor, $member, $grant)) {
            $name = (string)$member['company_name'];
            throw new IdentityUserManagementException('IDENTITY_MANAGE_DENIED',
                "Identity update denied: this person also belongs to {$name}. You must be able to manage every active membership.", 403, $name);
        }
    }
}

function identityUserEmailHasUnlinkedMembers(mysqli $db, string $email): bool
{
    $stmt = userIdentityPrepare($db, 'SELECT id FROM users WHERE LOWER(TRIM(email)) = ? AND identity_id IS NULL LIMIT 1');
    try {
        $stmt->bind_param('s', $email);
        userIdentityExecute($stmt);
        return (bool)$stmt->get_result()->fetch_assoc();
    } finally { $stmt->close(); }
}

/** Create a login only when no such identity exists. Prevent joining manual-review rows. */
function identityUserResolveForNewMember(mysqli $db, ?string $email, ?string $password, bool $mustChange): ?array
{
    if ($email === null) return null;
    $email = userIdentityNormalizeEmail($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Invalid email.', 422);
    if (identityUserManualReviewEmail($email) || identityUserEmailHasUnlinkedMembers($db, $email)) {
        throw new IdentityUserManagementException('IDENTITY_MANUAL_REVIEW',
            'This email has existing memberships awaiting personnel review. Contact a Super Admin.');
    }
    $identity = userIdentityFindByEmail($db, $email);
    if ($identity !== null) {
        // Serialize with concurrent password resets so this membership is
        // inserted using the definitive current identity credentials.
        $identity = userIdentityFindById($db, (int)$identity['id'], true);
        if (!$identity) throw new RuntimeException('Identity unavailable.');
        if ($password !== null && $password !== '') throw new IdentityUserManagementException(
            'IDENTITY_EXISTING_PASSWORD', 'This person already has a login. Do not set another password.', 422);
        if ((int)$identity['is_active'] !== 1) throw new Exception('This identity is inactive. Contact a Super Admin.', 403);
        return $identity;
    }
    if ($password === null || strlen($password) < 6) throw new Exception('A password of at least 6 characters is required for a new login.', 422);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $flag = $mustChange ? 1 : 0;
    $stmt = userIdentityPrepare($db, 'INSERT INTO user_identities (email, password_hash, must_change_password, token_version)
        VALUES (?, ?, ?, 0)');
    try {
        $stmt->bind_param('ssi', $email, $hash, $flag);
        userIdentityExecute($stmt);
        $id = (int)$db->insert_id;
    } finally { $stmt->close(); }
    return userIdentityFindById($db, $id);
}

/** Explicit opt-in is required before joining a different existing login. */
function identityUserApplyEmailInTransaction(mysqli $db, array $actor, int $targetUserId,
    ?string $newEmail, bool $confirmLink = false): array
{
    $members = identityUserMembershipsForWrite($db, $targetUserId);
    identityUserAssertManageAll($db, $actor, $members, $targetUserId);
    $sourceId = (int)($members[0]['identity_id'] ?? 0);
    $oldEmail = null;
    if ($sourceId > 0) {
        $source = userIdentityFindById($db, $sourceId, true);
        if (!$source) throw new RuntimeException('Identity missing.');
        $oldEmail = $source['email'];
    }
    $newEmail = $newEmail !== null && trim($newEmail) !== '' ? userIdentityNormalizeEmail($newEmail) : null;
    if ($newEmail !== null && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) throw new Exception('Invalid email.', 422);
    if ($sourceId > 0 && $newEmail === null) throw new IdentityUserManagementException('IDENTITY_EMAIL_REQUIRED',
        'A shared login cannot have a blank email. Deactivate its membership instead.', 422);
    if ($sourceId > 0 && $oldEmail === $newEmail) return ['changed' => false, 'linked' => false];
    if ($newEmail === null) return ['changed' => false, 'linked' => false];
    if ($sourceId === 0) {
        $stmt = userIdentityPrepare($db, 'SELECT email FROM users WHERE id = ? LIMIT 1');
        try { $stmt->bind_param('i', $targetUserId); userIdentityExecute($stmt);
            $previousEmail = (string)($stmt->get_result()->fetch_assoc()['email'] ?? ''); }
        finally { $stmt->close(); }
        if ($previousEmail !== '' && identityUserManualReviewEmail($previousEmail)) {
            throw new IdentityUserManagementException('IDENTITY_MANUAL_REVIEW',
                'This membership awaits personnel review before its login email can be changed.');
        }
    }

    if (identityUserManualReviewEmail($newEmail) || identityUserEmailHasUnlinkedMembers($db, $newEmail)) {
        throw new IdentityUserManagementException('IDENTITY_MANUAL_REVIEW',
            'This email requires personnel review before it can be linked.');
    }
    $destination = userIdentityFindByEmail($db, $newEmail);
    if ($destination && (int)$destination['id'] === $sourceId) return ['changed' => false, 'linked' => false];
    if ($destination) {
        if ((int)$destination['is_active'] !== 1) throw new Exception('Target login is inactive.', 403);
        if (!$confirmLink) throw new IdentityUserManagementException('IDENTITY_LINK_CONFIRM_REQUIRED',
            'This email already belongs to another login. Confirm linking to its existing credentials.');
        $destinationId = (int)$destination['id'];
        if (!userIdentityFindById($db, $destinationId, true)) throw new RuntimeException('Identity missing.');
        $destinationMembers = userIdentityMemberships($db, $destinationId, false, true);
        if (!$destinationMembers) throw new Exception('The target identity has no memberships.', 409);
        $targetRecords = array_map(static function ($m) {
            $m['id'] = $m['user_id'];
            return $m;
        }, $destinationMembers);
        identityUserAssertManageAll($db, $actor, $targetRecords,
            (int)$destinationMembers[0]['user_id'], false);
        $sourceCompanies = array_map('intval', array_column($members, 'company_id'));
        foreach ($destinationMembers as $member) {
            if (in_array((int)$member['company_id'], $sourceCompanies, true)) {
                throw new IdentityUserManagementException('IDENTITY_COMPANY_CONFLICT',
                    'The two logins already have memberships in the same company. Resolve the conflict before linking.');
            }
        }
        if ($sourceId > 0) {
            $stmt = userIdentityPrepare($db, 'UPDATE users SET identity_id = ?, email = ?, updated_by = ? WHERE identity_id = ?');
            $actorId = (int)$actor['id'];
            try {
                $stmt->bind_param('isii', $destinationId, $newEmail, $actorId, $sourceId);
                userIdentityExecute($stmt);
            } finally { $stmt->close(); }
            $stmt = userIdentityPrepare($db, 'DELETE FROM user_identities WHERE id = ?');
            try { $stmt->bind_param('i', $sourceId); userIdentityExecute($stmt); } finally { $stmt->close(); }
        } else {
            $stmt = userIdentityPrepare($db, 'UPDATE users SET identity_id = ?, email = ?, updated_by = ? WHERE id = ? AND identity_id IS NULL');
            $actorId = (int)$actor['id'];
            try { $stmt->bind_param('isii', $destinationId, $newEmail, $actorId, $targetUserId); userIdentityExecute($stmt); }
            finally { $stmt->close(); }
        }
        // Merging two credentials invalidates all pre-existing sessions on both.
        userIdentityRevokeInTransaction($db, $destinationId);
        identityPasswordAuditAllInTransaction($db, array_merge($members, $targetRecords),
            (int)$actor['id'], 'link_user_identity',
            'Administrator explicitly linked memberships to an existing sign-in identity');
        return ['changed' => true, 'linked' => true];
    }

    if ($sourceId > 0) {
        $stmt = userIdentityPrepare($db, 'UPDATE user_identities SET email = ?, token_version = token_version + 1 WHERE id = ?');
        try { $stmt->bind_param('si', $newEmail, $sourceId); userIdentityExecute($stmt); }
        finally { $stmt->close(); }
        $actorId = (int)$actor['id'];
        $stmt = userIdentityPrepare($db, 'UPDATE users SET email = ?, updated_by = ? WHERE identity_id = ?');
        try { $stmt->bind_param('sii', $newEmail, $actorId, $sourceId); userIdentityExecute($stmt); }
        finally { $stmt->close(); }
        userIdentitySyncMembersInTransaction($db, $sourceId, $actorId);
    } else {
        // Previously no email: give an unusable random initial password. An
        // administrator must reset it before it can be used for sign-in.
        $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $old = userIdentityPrepare($db, 'SELECT token_version FROM users WHERE id = ? FOR UPDATE');
        try { $old->bind_param('i', $targetUserId); userIdentityExecute($old);
            $tokenVersion = (int)($old->get_result()->fetch_assoc()['token_version'] ?? 0) + 1; }
        finally { $old->close(); }
        $flag = 1;
        $stmt = userIdentityPrepare($db, 'INSERT INTO user_identities
            (email, password_hash, must_change_password, token_version) VALUES (?, ?, ?, ?)');
        try { $stmt->bind_param('ssii', $newEmail, $hash, $flag, $tokenVersion); userIdentityExecute($stmt);
            $sourceId = (int)$db->insert_id; }
        finally { $stmt->close(); }
        $actorId = (int)$actor['id'];
        $stmt = userIdentityPrepare($db, 'UPDATE users SET identity_id = ?, email = ?, updated_by = ? WHERE id = ? AND identity_id IS NULL');
        try { $stmt->bind_param('isii', $sourceId, $newEmail, $actorId, $targetUserId); userIdentityExecute($stmt); }
        finally { $stmt->close(); }
        userIdentitySyncMembersInTransaction($db, $sourceId, $actorId);
    }
    identityPasswordAuditAllInTransaction($db, $members, (int)$actor['id'], 'update_identity_email',
        'Administrator updated the shared sign-in email; previous sessions revoked');
    return ['changed' => true, 'linked' => false, 'password_reset_required' => count($members) === 1 && $oldEmail === null];
}
