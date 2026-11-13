<?php
require_once __DIR__ . '/../../includes/ApiException.php';

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/AuthSecurity.php';
require_once 'includes/Locale.php';
require_once 'includes/UserIdentity.php';
require_once 'includes/IdentitySession.php';

use Respect\Validation\Validator as v;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');

function loginResponse(string $status, string $message, array $data = [], int $httpCode = 200, ?string $code = null): void
{
    http_response_code($httpCode);
    $payload = ['status' => $status, 'message' => $message, 'data' => $data];
    if ($code !== null) $payload['code'] = $code;
    echo json_encode(array_merge($payload, apiMessageMeta($message, $httpCode)));
    exit;
}

function ensureLoginAttemptsTable(mysqli $conn): bool
{
    // Keep the legacy column for backward compatibility. All new attempts are
    // keyed by the normalized email (company_id = 0), even after workspace selection.
    return (bool)$conn->query("CREATE TABLE IF NOT EXISTS auth_login_attempts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL,
        company_id INT NOT NULL DEFAULT 0,
        ip_address VARCHAR(64) NOT NULL,
        attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_login_attempt_window (email, company_id, ip_address, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function loginIp(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 64);
}

function isLoginRateLimited(mysqli $conn, string $email, string $ip): bool
{
    if (!ensureLoginAttemptsTable($conn)) return false;
    $stmt = $conn->prepare('SELECT COUNT(*) AS attempts FROM auth_login_attempts
        WHERE email = ? AND company_id = 0 AND ip_address = ?
          AND attempted_at >= (NOW() - INTERVAL 15 MINUTE)');
    if (!$stmt) return false;
    $stmt->bind_param('ss', $email, $ip);
    $stmt->execute();
    $attempts = (int)($stmt->get_result()->fetch_assoc()['attempts'] ?? 0);
    $stmt->close();
    return $attempts >= 5;
}

function recordFailedLogin(mysqli $conn, string $email, string $ip): void
{
    if (!ensureLoginAttemptsTable($conn)) return;
    $stmt = $conn->prepare('INSERT INTO auth_login_attempts (email, company_id, ip_address)
        VALUES (?, 0, ?)');
    if (!$stmt) return;
    $stmt->bind_param('ss', $email, $ip);
    $stmt->execute();
    $stmt->close();
}

function clearFailedLogin(mysqli $conn, string $email, string $ip): void
{
    if (!ensureLoginAttemptsTable($conn)) return;
    $stmt = $conn->prepare('DELETE FROM auth_login_attempts
        WHERE email = ? AND company_id = 0 AND ip_address = ?');
    if (!$stmt) return;
    $stmt->bind_param('ss', $email, $ip);
    $stmt->execute();
    $stmt->close();
}

/**
 * Transitional-only: Batch 2 deliberately deferred two shared-email groups
 * pending personnel approval. Their memberships have no identity and must
 * retain their EXISTING individual credentials until reviewed. Never use
 * this path if the email already belongs to an identity.
 */
function loginUnlinkedMembers(mysqli $conn, string $email, string $password): array
{
    $stmt = $conn->prepare('SELECT u.id, u.identity_id, u.first_name, u.last_name,
        u.email, u.password_hash, u.must_change_password, u.password_changed_at,
        COALESCE(u.token_version, 0) AS token_version,
        u.last_login_at, u.username, u.staff_scope, u.is_supervisor,
        u.department, u.job_title, u.staff_type, u.is_active, u.company_id,
        u.preferred_locale, r.name AS role,
        c.code AS company_code, c.name AS company_name, c.default_locale
        FROM users AS u
        INNER JOIN roles AS r ON r.id = u.role_id
        INNER JOIN companies AS c ON c.id = u.company_id
        WHERE LOWER(TRIM(u.email)) = ? AND u.identity_id IS NULL
          AND u.is_active = 1 AND c.is_active = 1
        ORDER BY c.name ASC, u.id ASC');
    if (!$stmt) throw new Exception('Database error.', 500);
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $valid = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if (password_verify($password, (string)$row['password_hash'])) {
            $row['identity_id'] = 0;
            $row['identity_token_version'] = 0;
            $valid[] = $row;
        }
    }
    $stmt->close();
    return $valid;
}

/** Membership-shaped response; database authentication data never goes to JS. */
function loginIdentityMembers(mysqli $conn, array $identity): array
{
    $members = userIdentityMemberships($conn, (int)$identity['id'], true);
    $accounts = [];
    foreach ($members as $member) {
        $member['id'] = (int)$member['user_id'];
        unset($member['user_id']);
        $member['identity_id'] = (int)$identity['id'];
        $member['identity_token_version'] = (int)$identity['token_version'];
        $member['token_version'] = (int)$identity['token_version'];
        $member['must_change_password'] = (int)$identity['must_change_password'];
        $member['password_changed_at'] = $identity['password_changed_at'];
        $member['last_login_at'] = $identity['last_login_at'];
        $member['preferred_locale'] = $identity['preferred_locale'];
        $member['email'] = $identity['email'];
        $accounts[] = $member;
    }
    return $accounts;
}

/** Return the most recently used membership ONLY if it belongs to this identity. */
function loginLastUsedCompany(mysqli $conn, int $identityId, array $accounts): int
{
    if ($identityId <= 0) return 0;
    $stmt = $conn->prepare("SELECT a.user_id FROM audit_log AS a
        INNER JOIN users AS u ON u.id = a.user_id
        WHERE u.identity_id = ? AND a.action IN ('login', 'switch_workspace')
        ORDER BY a.id DESC LIMIT 1");
    if (!$stmt) return 0;
    $stmt->bind_param('i', $identityId);
    $stmt->execute();
    $lastUserId = (int)($stmt->get_result()->fetch_assoc()['user_id'] ?? 0);
    $stmt->close();
    $allowed = array_map(static fn(array $account): array => [
        'user_id' => (int)$account['id']
    ], $accounts);
    $preferredUserId = identitySessionSuggestedMembership($allowed, $lastUserId);
    foreach ($accounts as $account) {
        if ((int)$account['id'] === $preferredUserId && $preferredUserId > 0) {
            return (int)$account['company_id'];
        }
    }
    return 0;
}

try {
    securityLoadEnv();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Bad Request: Only POST method is allowed.', 405);
    }
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) throw new Exception('Invalid request format. Expected JSON object.', 400);

    $email = userIdentityNormalizeEmail((string)($data['email'] ?? ''));
    $password = (string)($data['password'] ?? '');
    $companyId = isset($data['company_id']) ? (int)$data['company_id'] : 0;
    if (!v::email()->notEmpty()->validate($email)) {
        throw new Exception('Enter a valid email address.', 400);
    }
    if ($password === '') throw new Exception('Password is required.', 400);
    if ($companyId < 0) throw new Exception('Invalid workspace selected.', 400);

    $ip = loginIp();
    if (isLoginRateLimited($conn, $email, $ip)) {
        throw new Exception('Too many failed sign-in attempts. Please wait 15 minutes and try again.', 429);
    }

    $identity = userIdentityFindByEmail($conn, $email);
    if ($identity !== null) {
        if ((int)$identity['is_active'] !== 1
            || !password_verify($password, (string)$identity['password_hash'])) {
            recordFailedLogin($conn, $email, $ip);
            throw new Exception('Invalid email or password.', 401);
        }
        // Exactly one password verification regardless of number of workspaces.
        $validAccounts = loginIdentityMembers($conn, $identity);
    } else {
        // Deliberately unlinked historical membership: no automatic merge.
        $validAccounts = loginUnlinkedMembers($conn, $email, $password);
    }

    if (!$validAccounts) {
        recordFailedLogin($conn, $email, $ip);
        throw new Exception('Invalid email or password.', 401);
    }

    if ($companyId === 0 && count($validAccounts) > 1) {
        $workspaces = array_map(static fn(array $account): array => [
            'id' => (int)$account['company_id'],
            'code' => (string)($account['company_code'] ?? ''),
            'name' => (string)($account['company_name'] ?? ''),
        ], $validAccounts);
        loginResponse('SelectionRequired',
            'Your email is linked to more than one organisation. Select a workspace to continue.',
            ['companies' => $workspaces,
             'preferred_company_id' => loginLastUsedCompany($conn, (int)($identity['id'] ?? 0), $validAccounts)],
            200, 'COMPANY_SELECTION_REQUIRED');
    }

    // The company is selected ONLY from the list of authenticated, active OWN
    // memberships. Never query another user just because its company id matches.
    $user = null;
    foreach ($validAccounts as $account) {
        if ($companyId === 0 || (int)$account['company_id'] === $companyId) {
            $user = $account;
            break;
        }
    }
    if ($user === null) {
        recordFailedLogin($conn, $email, $ip);
        throw new Exception('Invalid workspace selected.', 403);
    }

    if ($identity !== null) {
        userIdentityRecordLogin($conn, (int)$identity['id']);
    } else {
        $stmt = $conn->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
        if ($stmt) {
            $userId = (int)$user['id'];
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
        }
    }

    clearFailedLogin($conn, $email, $ip);
    $csrfToken = startSecureSession($user);

    $fullname = trim((string)$user['first_name'] . ' ' . (string)$user['last_name']);
    $logStmt = $conn->prepare('INSERT INTO audit_log
        (company_id, user_id, action, target_table, target_id, description)
        VALUES (?, ?, ?, ?, ?, ?)');
    if ($logStmt) {
        $loggedCompanyId = (int)$user['company_id'];
        $loggedUserId = (int)$user['id'];
        $action = 'login';
        $targetTable = 'users';
        $description = "{$fullname} logged in successfully via {$user['company_name']}";
        $logStmt->bind_param('iissis', $loggedCompanyId, $loggedUserId, $action,
            $targetTable, $loggedUserId, $description);
        $logStmt->execute();
        $logStmt->close();
    }

    unset($user['password_hash'], $user['token_version'], $user['identity_token_version'],
          $user['is_active'], $user['identity_is_active'], $user['company_is_active']);
    $user['id'] = (int)$user['id'];
    $user['company_id'] = (int)$user['company_id'];
    $user['identity_id'] = (int)($user['identity_id'] ?? 0);
    $user['must_change_password'] = (int)($user['must_change_password'] ?? 0);
    $user['is_supervisor'] = (int)($user['is_supervisor'] ?? 0);
    $user['csrf_token'] = $csrfToken;
    $user['effective_locale'] = appEffectiveLocale(
        $user['preferred_locale'] ?? null, $user['default_locale'] ?? null);

    loginResponse('Success', 'Login successful.', $user);
} catch (Throwable $e) {
    $code = (int)$e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    $message = $code === 500 && securityIsProduction()
        ? 'An internal server error occurred.' : $e->getMessage();
    loginResponse('Failed', $message, [], $code);
}
