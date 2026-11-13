<?php
/**
 * Person-level sign-in data. A users row is a company membership, not a login.
 *
 * Batch 2 supplies the helper but DOES NOT replace the existing login/session,
 * password, locale or user-management routes. Those routes must be migrated in
 * Batches 3-6 before this schema goes live.
 *
 * Public mutators own their DB transaction. Do not call them from an existing
 * transaction: use userIdentity*InTransaction() functions instead so a caller
 * can include per-company audit records in the same transaction.
 */

declare(strict_types=1);

function userIdentityNormalizeEmail(string $email): string
{
    $email = trim($email);
    return function_exists('mb_strtolower')
        ? mb_strtolower($email, 'UTF-8')
        : strtolower($email); // Existing login validation permits ASCII email.
}

function userIdentityPrepare(mysqli $db, string $sql): mysqli_stmt
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare identity database query.');
    }
    return $stmt;
}

function userIdentityExecute(mysqli_stmt $stmt): void
{
    if (!$stmt->execute()) {
        $message = $stmt->error;
        throw new RuntimeException('Identity database operation failed: ' . $message);
    }
}

/** Internal lookup: includes the hash; NEVER send this row to a browser. */
function userIdentityFindByEmail(mysqli $db, string $email): ?array
{
    $email = userIdentityNormalizeEmail($email);
    if ($email === '') return null;

    $stmt = userIdentityPrepare($db, 'SELECT id, email, password_hash, must_change_password,
        password_changed_at, token_version, last_login_at, preferred_locale,
        is_active, created_at, updated_at
        FROM user_identities WHERE email = ? LIMIT 1');
    try {
        $stmt->bind_param('s', $email);
        userIdentityExecute($stmt);
        $row = $stmt->get_result()->fetch_assoc();
        return $row ?: null;
    } finally {
        $stmt->close();
    }
}

/** Internal lookup: includes the hash; NEVER send this row to a browser. */
function userIdentityFindById(mysqli $db, int $identityId, bool $forUpdate = false): ?array
{
    if ($identityId <= 0) return null;

    $sql = 'SELECT id, email, password_hash, must_change_password,
        password_changed_at, token_version, last_login_at, preferred_locale,
        is_active, created_at, updated_at
        FROM user_identities WHERE id = ? LIMIT 1';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    $stmt = userIdentityPrepare($db, $sql);
    try {
        $stmt->bind_param('i', $identityId);
        userIdentityExecute($stmt);
        $row = $stmt->get_result()->fetch_assoc();
        return $row ?: null;
    } finally {
        $stmt->close();
    }
}

/**
 * Resolve the person's own memberships. Active-only results exclude inactive
 * memberships AND inactive companies AND inactive identities; Company Access
 * grants are deliberately not included. No other person's memberships leak.
 */
function userIdentityMemberships(mysqli $db, int $identityId, bool $activeOnly = true, bool $forUpdate = false): array
{
    if ($identityId <= 0) return [];
    $sql = 'SELECT u.id AS user_id, u.identity_id, u.company_id, u.role_id,
            u.first_name, u.last_name, u.email, u.username,
            u.staff_scope, u.is_supervisor, u.department, u.job_title,
            u.staff_type, u.is_active, u.preferred_locale,
            r.name AS role, c.code AS company_code, c.name AS company_name,
            c.is_active AS company_is_active, c.default_locale
        FROM users AS u
        INNER JOIN user_identities AS i ON i.id = u.identity_id
        INNER JOIN roles AS r ON r.id = u.role_id
        INNER JOIN companies AS c ON c.id = u.company_id
        WHERE u.identity_id = ?';
    if ($activeOnly) {
        $sql .= ' AND i.is_active = 1 AND u.is_active = 1 AND c.is_active = 1';
    }
    $sql .= ' ORDER BY c.name ASC, u.id ASC';
    // Inside a workspace-switch transaction, lock the active membership and
    // company rows against concurrent deactivation before issuing a new JWT.
    if ($forUpdate) $sql .= ' FOR UPDATE';

    $stmt = userIdentityPrepare($db, $sql);
    try {
        $stmt->bind_param('i', $identityId);
        userIdentityExecute($stmt);
        $memberships = [];
        $results = $stmt->get_result();
        while ($row = $results->fetch_assoc()) {
            $memberships[] = $row;
        }
        return $memberships;
    } finally {
        $stmt->close();
    }
}

/** Execute the whole identity-and-membership write atomically. */
function userIdentityTransaction(mysqli $db, callable $callback)
{
    if (!$db->begin_transaction()) {
        throw new RuntimeException('Could not begin identity transaction.');
    }
    try {
        $result = $callback($db);
        if (!$db->commit()) {
            throw new RuntimeException('Could not commit identity transaction.');
        }
        return $result;
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

/**
 * CALL ONLY while the identity row is locked in a caller-owned transaction.
 * This is Phase 1's mandatory dual-write for legacy users columns. It must
 * remain in use until the separate final legacy-column removal phase.
 */
function userIdentitySyncMembersInTransaction(mysqli $db, int $identityId, ?int $updatedBy = null): int
{
    $stmt = userIdentityPrepare($db, 'UPDATE users AS u
        INNER JOIN user_identities AS i ON i.id = u.identity_id
        SET u.password_hash = i.password_hash,
            u.must_change_password = i.must_change_password,
            u.password_changed_at = i.password_changed_at,
            u.token_version = i.token_version,
            u.last_login_at = i.last_login_at,
            u.preferred_locale = i.preferred_locale,
            u.updated_by = COALESCE(?, u.updated_by)
        WHERE i.id = ?');
    try {
        $stmt->bind_param('ii', $updatedBy, $identityId);
        userIdentityExecute($stmt);
        return $stmt->affected_rows;
    } finally {
        $stmt->close();
    }
}

/**
 * Change/reset the SHARED password. The hash must already have been generated
 * with password_hash(). Clearing must-change stamps password_changed_at;
 * resetting to a temporary password sets it to NULL. Revoke all old sessions.
 * Caller must own an open transaction for atomic company audits.
 */
function userIdentityUpdatePasswordInTransaction(
    mysqli $db,
    int $identityId,
    string $newPasswordHash,
    bool $mustChangePassword,
    ?int $updatedBy = null
): array {
    if (strlen($newPasswordHash) > 255
        || (password_get_info($newPasswordHash)['algoName'] ?? 'unknown') === 'unknown') {
        throw new InvalidArgumentException('Expected a valid hashed password.');
    }
    $existing = userIdentityFindById($db, $identityId, true);
    if (!$existing) {
        throw new RuntimeException('Login identity not found.');
    }

    $mustChange = $mustChangePassword ? 1 : 0;
    $stmt = userIdentityPrepare($db, 'UPDATE user_identities
        SET password_hash = ?, must_change_password = ?,
            password_changed_at = CASE WHEN ? = 1 THEN NULL ELSE NOW() END,
            token_version = token_version + 1
        WHERE id = ?');
    try {
        $stmt->bind_param('siii', $newPasswordHash, $mustChange, $mustChange, $identityId);
        userIdentityExecute($stmt);
    } finally {
        $stmt->close();
    }
    userIdentitySyncMembersInTransaction($db, $identityId, $updatedBy);
    return userIdentityFindById($db, $identityId) ?? [];
}

function userIdentityUpdatePassword(
    mysqli $db,
    int $identityId,
    string $newPasswordHash,
    bool $mustChangePassword,
    ?int $updatedBy = null
): array {
    return userIdentityTransaction($db, static function (mysqli $connection) use (
        $identityId, $newPasswordHash, $mustChangePassword, $updatedBy
    ): array {
        return userIdentityUpdatePasswordInTransaction(
            $connection, $identityId, $newPasswordHash, $mustChangePassword, $updatedBy
        );
    });
}

/** Revoke all sessions for this identity, without changing its password. */
function userIdentityRevokeInTransaction(mysqli $db, int $identityId): int
{
    if (!userIdentityFindById($db, $identityId, true)) {
        throw new RuntimeException('Login identity not found.');
    }
    $stmt = userIdentityPrepare($db,
        'UPDATE user_identities SET token_version = token_version + 1 WHERE id = ?');
    try {
        $stmt->bind_param('i', $identityId);
        userIdentityExecute($stmt);
    } finally {
        $stmt->close();
    }
    userIdentitySyncMembersInTransaction($db, $identityId);
    $identity = userIdentityFindById($db, $identityId);
    return (int)($identity['token_version'] ?? 0);
}

function userIdentityRevokeSessions(mysqli $db, int $identityId): int
{
    return userIdentityTransaction($db, static function (mysqli $connection) use ($identityId): int {
        return userIdentityRevokeInTransaction($connection, $identityId);
    });
}

/** NULL means fall back to each membership company's default language. */
function userIdentitySaveLocaleInTransaction(mysqli $db, int $identityId, ?string $locale): void
{
    if ($locale !== null && !in_array($locale, ['en', 'fr'], true)) {
        throw new InvalidArgumentException('Unsupported identity language preference.');
    }
    if (!userIdentityFindById($db, $identityId, true)) {
        throw new RuntimeException('Login identity not found.');
    }
    $stmt = userIdentityPrepare($db,
        'UPDATE user_identities SET preferred_locale = ? WHERE id = ?');
    try {
        $stmt->bind_param('si', $locale, $identityId);
        userIdentityExecute($stmt);
    } finally {
        $stmt->close();
    }
    userIdentitySyncMembersInTransaction($db, $identityId);
}

function userIdentitySaveLocale(mysqli $db, int $identityId, ?string $locale): void
{
    userIdentityTransaction($db, static function (mysqli $connection) use ($identityId, $locale): void {
        userIdentitySaveLocaleInTransaction($connection, $identityId, $locale);
    });
}

/** After a successful login, update the person's last sign-in across memberships. */
function userIdentityRecordLogin(mysqli $db, int $identityId): void
{
    userIdentityTransaction($db, static function (mysqli $connection) use ($identityId): void {
        if (!userIdentityFindById($connection, $identityId, true)) {
            throw new RuntimeException('Login identity not found.');
        }
        $stmt = userIdentityPrepare($connection,
            'UPDATE user_identities SET last_login_at = NOW() WHERE id = ?');
        try {
            $stmt->bind_param('i', $identityId);
            userIdentityExecute($stmt);
        } finally {
            $stmt->close();
        }
        userIdentitySyncMembersInTransaction($connection, $identityId);
    });
}
