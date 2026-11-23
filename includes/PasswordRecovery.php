<?php
/** Self-service recovery for a person-level identity or one unlinked legacy membership. */
declare(strict_types=1);

require_once __DIR__ . '/UserIdentity.php';
require_once __DIR__ . '/IdentityPasswords.php';
require_once __DIR__ . '/AuthSecurity.php';
require_once __DIR__ . '/Locale.php';

const PASSWORD_RECOVERY_TOKEN_MINUTES = 30;
const PASSWORD_RECOVERY_EMAIL_LIMIT = 3;
const PASSWORD_RECOVERY_IP_LIMIT = 15;

function passwordRecoveryTokenIsValidFormat(string $token): bool
{
    return (bool)preg_match('/\A[a-f0-9]{64}\z/D', $token);
}

function passwordRecoveryTokenHash(string $token): string
{
    return hash('sha256', $token);
}

function passwordRecoveryDigest(string $value): string
{
    return hash_hmac('sha256', $value, securityJwtSecret());
}

/** Never construct an email link from untrusted Host/Origin request headers. */
function passwordRecoveryPublicUrl(): string
{
    securityLoadEnv();
    $raw = trim((string)securityEnv('PASSWORD_RESET_PUBLIC_URL', ''));
    // For development only, reuse the *single* configured browser origin.
    if ($raw === '' && !securityIsProduction()) {
        $origins = array_values(array_filter(array_map('trim', explode(',', (string)securityEnv('FRONTEND_ORIGIN', 'http://localhost:5173')))));
        if (count($origins) === 1) $raw = $origins[0];
    }
    $url = parse_url($raw);
    if ($raw === '' || !is_array($url) || !isset($url['scheme'], $url['host'])
        || !in_array(strtolower($url['scheme']), ['https', 'http'], true)
        || (securityIsProduction() && strtolower($url['scheme']) !== 'https')
        || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
        throw new RuntimeException('Password recovery public URL has not been configured safely.');
    }
    return rtrim($raw, '/');
}

function passwordRecoveryLink(string $base, string $token): string
{
    if (!passwordRecoveryTokenIsValidFormat($token)) throw new InvalidArgumentException('Invalid token.');
    return $base . '/reset-password?token=' . rawurlencode($token);
}

/** Fail closed if the address is shared by two unlinked historical users. */
function passwordRecoveryEligibleAccount(mysqli $db, string $email): ?array
{
    $identity = userIdentityFindByEmail($db, $email);
    if ($identity !== null) {
        if ((int)$identity['is_active'] !== 1) return null;
        $members = userIdentityMemberships($db, (int)$identity['id'], true);
        if (!$members) return null;
        return ['identity_id' => (int)$identity['id'], 'user_id' => null,
            'recipient_user_id' => (int)$members[0]['user_id'], 'email' => $identity['email'],
            'issued_token_version' => (int)$identity['token_version']];
    }

    $stmt = userIdentityPrepare($db, 'SELECT u.id, u.email, COALESCE(u.token_version, 0) AS token_version FROM users u
        INNER JOIN companies c ON c.id = u.company_id
        WHERE LOWER(TRIM(u.email)) = ? AND u.identity_id IS NULL
        AND u.is_active = 1 AND c.is_active = 1 ORDER BY u.id LIMIT 2');
    try {
        $stmt->bind_param('s', $email);
        userIdentityExecute($stmt);
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } finally {
        $stmt->close();
    }
    if (count($rows) !== 1) return null; // Ambiguous legacy email: no silent linking.
    return ['identity_id' => null, 'user_id' => (int)$rows[0]['id'],
        'recipient_user_id' => (int)$rows[0]['id'], 'email' => $rows[0]['email'],
        'issued_token_version' => (int)$rows[0]['token_version']];
}

/** A request receipt is recorded even for nonexistent accounts and throttled callers. */
function passwordRecoveryIssue(mysqli $db, string $email, string $ip): ?array
{
    $email = userIdentityNormalizeEmail($email);
    $emailDigest = passwordRecoveryDigest('email:' . $email);
    $ipDigest = passwordRecoveryDigest('ip:' . $ip);
    $db->begin_transaction();
    try {
        $count = userIdentityPrepare($db, 'SELECT
            (SELECT COUNT(*) FROM auth_password_reset_requests WHERE email_digest = ?
                AND requested_at >= NOW() - INTERVAL 1 HOUR) AS email_count,
            (SELECT COUNT(*) FROM auth_password_reset_requests WHERE ip_digest = ?
                AND requested_at >= NOW() - INTERVAL 1 HOUR) AS ip_count');
        $count->bind_param('ss', $emailDigest, $ipDigest);
        userIdentityExecute($count);
        $limits = $count->get_result()->fetch_assoc();
        $count->close();
        $limited = (int)$limits['email_count'] >= PASSWORD_RECOVERY_EMAIL_LIMIT
            || (int)$limits['ip_count'] >= PASSWORD_RECOVERY_IP_LIMIT;

        $account = $limited ? null : passwordRecoveryEligibleAccount($db, $email);
        if ($account !== null) {
            // Serialize concurrent resets with password changes and identity deletion.
            if ($account['identity_id'] !== null) {
                $locked = userIdentityFindById($db, $account['identity_id'], true);
                if (!$locked || (int)$locked['is_active'] !== 1) $account = null;
                else $account['issued_token_version'] = (int)$locked['token_version'];
            } else {
                $lock = userIdentityPrepare($db, 'SELECT id, COALESCE(token_version, 0) AS token_version FROM users WHERE id = ? AND identity_id IS NULL AND is_active = 1 FOR UPDATE');
                $lock->bind_param('i', $account['user_id']);
                userIdentityExecute($lock);
                 $locked = $lock->get_result()->fetch_assoc();
                if (!$locked) $account = null;
                else $account['issued_token_version'] = (int)$locked['token_version'];
                $lock->close();
            }
        }
        $token = null;
        if ($account !== null) {
            // At most one live token per account; older emailed links stop working.
            $sql = $account['identity_id'] !== null
                ? 'UPDATE auth_password_reset_requests SET token_hash = NULL, expires_at = NULL
                   WHERE identity_id = ? AND consumed_at IS NULL'
                : 'UPDATE auth_password_reset_requests SET token_hash = NULL, expires_at = NULL
                   WHERE user_id = ? AND consumed_at IS NULL';
            $invalidate = userIdentityPrepare($db, $sql);
            $targetId = (int)($account['identity_id'] ?? $account['user_id']);
            $invalidate->bind_param('i', $targetId);
            userIdentityExecute($invalidate);
            $invalidate->close();
            $token = bin2hex(random_bytes(32));
        }
        $tokenHash = $token !== null ? passwordRecoveryTokenHash($token) : null;
        $identityId = $account['identity_id'] ?? null;
        $userId = $account['user_id'] ?? null;
        $issuedVersion = (int)($account['issued_token_version'] ?? 0);
        $ttl = PASSWORD_RECOVERY_TOKEN_MINUTES;
        $insert = userIdentityPrepare($db, 'INSERT INTO auth_password_reset_requests
            (email_digest, ip_digest, identity_id, user_id, issued_token_version, token_hash, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, CASE WHEN ? IS NULL THEN NULL ELSE DATE_ADD(NOW(), INTERVAL ' . $ttl . ' MINUTE) END)');
        $insert->bind_param('ssiiiss', $emailDigest, $ipDigest, $identityId, $userId, $issuedVersion, $tokenHash, $tokenHash);
        userIdentityExecute($insert);
        $requestId = $db->insert_id;
        $insert->close();
        $db->commit();
        if ($token === null) return null;
        return ['id' => $requestId, 'token' => $token, 'recipient_user_id' => $account['recipient_user_id'], 'email' => $account['email']];
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
}

function passwordRecoveryDeliveryResult(mysqli $db, int $id, bool $sent): void
{
    $sql = $sent
        ? 'UPDATE auth_password_reset_requests SET delivered_at = NOW() WHERE id = ?'
        : 'UPDATE auth_password_reset_requests SET token_hash = NULL, expires_at = NULL WHERE id = ?';
    $stmt = userIdentityPrepare($db, $sql);
    try {
        $stmt->bind_param('i', $id);
        userIdentityExecute($stmt);
    } finally { $stmt->close(); }
}

/** Called only after a valid, unexpired token is locked in the same transaction. */
function passwordRecoveryConsume(mysqli $db, string $token, string $password): void
{
    if (!passwordRecoveryTokenIsValidFormat($token)) throw new Exception('Invalid or expired password reset link.', 400);
    if (strlen($password) < 8 || strlen($password) > 128) {
        throw new Exception('Password must contain 8 to 128 characters.', 422);
    }
    $hash = passwordRecoveryTokenHash($token);
    $db->begin_transaction();
    try {
        // Read the target first, then lock the account BEFORE the request row.
        // Issuance uses the same order: this avoids a reset/issue lock inversion.
        $lookup = userIdentityPrepare($db, 'SELECT identity_id, user_id FROM auth_password_reset_requests
            WHERE token_hash = ? AND consumed_at IS NULL AND expires_at > NOW() LIMIT 1');
        $lookup->bind_param('s', $hash);
        userIdentityExecute($lookup);
        $subject = $lookup->get_result()->fetch_assoc();
        $lookup->close();
        if (!$subject) throw new Exception('Invalid or expired password reset link.', 400);
        $identityId = (int)($subject['identity_id'] ?? 0);
        $userId = (int)($subject['user_id'] ?? 0);
        $identity = null;
        if ($identityId > 0) {
            $identity = userIdentityFindById($db, $identityId, true);
            if (!$identity || (int)$identity['is_active'] !== 1) {
                throw new Exception('Invalid or expired password reset link.', 400);
            }
        } elseif ($userId <= 0) {
            throw new Exception('Invalid or expired password reset link.', 400);
        } else {
            $legacy = userIdentityPrepare($db, 'SELECT u.email, u.password_hash, COALESCE(u.token_version, 0) AS token_version FROM users u
                INNER JOIN companies c ON c.id = u.company_id AND c.is_active = 1
                WHERE u.id = ? AND u.identity_id IS NULL AND u.is_active = 1 LIMIT 1 FOR UPDATE');
            $legacy->bind_param('i', $userId);
            userIdentityExecute($legacy);
            $unlinked = $legacy->get_result()->fetch_assoc();
            $legacy->close();
            if (!$unlinked) throw new Exception('Invalid or expired password reset link.', 400);
        }
        $stmt = userIdentityPrepare($db, 'SELECT id, identity_id, user_id, issued_token_version, email_digest
            FROM auth_password_reset_requests WHERE token_hash = ?
            AND consumed_at IS NULL AND expires_at > NOW() LIMIT 1 FOR UPDATE');
        $stmt->bind_param('s', $hash);
        userIdentityExecute($stmt);
        $request = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$request || (int)($request['identity_id'] ?? 0) !== $identityId
            || (int)($request['user_id'] ?? 0) !== $userId) {
            throw new Exception('Invalid or expired password reset link.', 400);
        }
        if ($identityId > 0) {
            if ((int)$request['issued_token_version'] !== (int)$identity['token_version']
                || !hash_equals((string)$request['email_digest'], passwordRecoveryDigest('email:' . userIdentityNormalizeEmail((string)$identity['email'])))
                || !userIdentityMemberships($db, $identityId, true)) {
                throw new Exception('Invalid or expired password reset link.', 400);
            }
            if (password_verify($password, $identity['password_hash'])) {
                throw new Exception('Choose a password different from your current password.', 422);
            }
            $members = userIdentityMemberships($db, $identityId, false);
            userIdentityUpdatePasswordInTransaction($db, $identityId, password_hash($password, PASSWORD_DEFAULT), false);
            $auditUserId = (int)$members[0]['user_id'];
            $audit = array_map(static fn($m) => ['id' => $m['user_id'], 'company_id' => $m['company_id']], $members);
            $accountEmail = (string)$identity['email'];
        } else {
            if ($userId <= 0) throw new Exception('Invalid or expired password reset link.', 400);
            $user = $unlinked;
            if ((int)$user['token_version'] !== (int)$request['issued_token_version']
                || !hash_equals((string)$request['email_digest'], passwordRecoveryDigest('email:' . userIdentityNormalizeEmail((string)$user['email']))))
                throw new Exception('Invalid or expired password reset link.', 400);
            if (password_verify($password, $user['password_hash'])) {
                throw new Exception('Choose a password different from your current password.', 422);
            }
            $members = identityPasswordMembershipRecords($db, $userId);
            identityPasswordUnlinkedUpdateInTransaction($db, $userId, password_hash($password, PASSWORD_DEFAULT), false, $userId);
            $auditUserId = $userId;
            $audit = $members;
            $accountEmail = (string)$user['email'];
        }
        identityPasswordAuditAllInTransaction($db, $audit, $auditUserId,
            'self_service_password_reset', 'Account holder reset their password using a verified email link.');
        $update = userIdentityPrepare($db, 'UPDATE auth_password_reset_requests
            SET consumed_at = NOW(), token_hash = NULL WHERE id = ?');
        $requestId = (int)$request['id'];
        $update->bind_param('i', $requestId);
        userIdentityExecute($update);
        $update->close();
        // Invalidate ALL outstanding reset links for the same account.
        if ($identityId > 0) {
            $other = userIdentityPrepare($db, 'UPDATE auth_password_reset_requests SET token_hash = NULL, expires_at = NULL
                WHERE identity_id = ? AND consumed_at IS NULL');
            $other->bind_param('i', $identityId);
        } else {
            $other = userIdentityPrepare($db, 'UPDATE auth_password_reset_requests SET token_hash = NULL, expires_at = NULL
                WHERE user_id = ? AND consumed_at IS NULL');
            $other->bind_param('i', $userId);
        }
        userIdentityExecute($other);
        $other->close();
        $db->commit();
    } catch (Throwable $error) {
        $db->rollback();
        throw $error;
    }
    // A user recovering from a sign-in lockout must not remain blocked by
    // the old wrong-password attempts. The table is created lazily by login.
    try {
        $email = userIdentityNormalizeEmail($accountEmail);
        $clear = userIdentityPrepare($db, 'DELETE FROM auth_login_attempts WHERE email = ? AND company_id = 0');
        $clear->bind_param('s', $email);
        userIdentityExecute($clear);
        $clear->close();
    } catch (Throwable $error) {
        // Never report password-reset failure after the transaction committed.
        error_log('[PasswordRecovery] Failed to clear previous sign-in attempts.');
    }
}
