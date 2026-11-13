<?php
require_once __DIR__ . '/../../includes/ApiException.php';
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/AuthSecurity.php';
require_once 'includes/IdentityPasswords.php';

use Respect\Validation\Validator as v;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');

try {
    if (!in_array($_SERVER['REQUEST_METHOD'], ['PUT', 'POST'], true)) {
        throw new Exception('Bad Request: Only POST or PUT method is allowed', 405);
    }
    $actor = authenticateUser();
    $userId = (int)$actor['id'];
    $identityId = (int)($actor['identity_id'] ?? 0);
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) throw new Exception('Invalid request format. Expected JSON object.', 400);

    $current = trim((string)($data['current_password'] ?? ''));
    $new = trim((string)($data['new_password'] ?? ''));
    $confirmation = trim((string)($data['confirm_password'] ?? ''));
    if ($current === '') throw new Exception('Current password is required.', 400);
    if ($new === '') throw new Exception('New password is required.', 400);
    if ($confirmation === '') throw new Exception('Please confirm your new password.', 400);
    if ($new !== $confirmation) throw new Exception('New password and confirmation do not match.', 400);
    if (!v::stringType()->length(8, null)->validate($new)) {
        throw new Exception('New password must be at least 8 characters long.', 400);
    }
    if ($current === $new) throw new Exception('New password must be different from your current password.', 400);

    // A single identity lock protects the verify-and-update against concurrent
    // password changes. All membership dual-writes and audits commit together.
    userIdentityTransaction($conn, static function (mysqli $db) use ($userId, $identityId, $current, $new): void {
        $hash = identityPasswordCurrentHash($db, $userId, $identityId);
        if ($hash === null || !password_verify($current, $hash)) {
            throw new Exception('Current password is incorrect.', 400);
        }
        $members = identityPasswordMembershipRecords($db, $userId);
        if (!$members) throw new Exception('Account membership not found.', 404);
        identityPasswordWriteInTransaction($db, $userId, $identityId, password_hash($new, PASSWORD_DEFAULT), false, $userId);
        identityPasswordAuditAllInTransaction(
            $db, $members, $userId, 'force_change_password', 'Member changed their shared sign-in password'
        );
    });

    // This is a NEW session after revoking older JWTs for this identity.
    // Preserve every membership field consumed by the existing frontend.
    $stmt = userIdentityPrepare($conn, 'SELECT u.id, u.identity_id,
        u.staff_id, u.first_name, u.last_name, u.email, u.username,
        u.department, u.job_title, u.staff_type, u.staff_scope, u.is_supervisor, u.location,
        u.unique_ref, u.date_of_joining, u.is_active, u.last_login_at,
        COALESCE(i.must_change_password, u.must_change_password) AS must_change_password,
        COALESCE(i.password_changed_at, u.password_changed_at) AS password_changed_at,
        u.updated_at, COALESCE(u.token_version, 0) AS token_version,
        COALESCE(i.token_version, 0) AS identity_token_version,
        CASE WHEN u.identity_id IS NOT NULL THEN i.preferred_locale ELSE u.preferred_locale END AS preferred_locale,
        r.name AS role, c.id AS company_id, c.code AS company_code, c.name AS company_name,
        c.default_locale
        FROM users AS u INNER JOIN roles AS r ON r.id = u.role_id
        INNER JOIN companies AS c ON c.id = u.company_id
        LEFT JOIN user_identities AS i ON i.id = u.identity_id
        WHERE u.id = ? LIMIT 1');
    try {
        $stmt->bind_param('i', $userId);
        userIdentityExecute($stmt);
        $profile = $stmt->get_result()->fetch_assoc();
    } finally {
        $stmt->close();
    }
    if (!$profile) throw new Exception('Updated account could not be loaded.', 500);
    $profile['identity_id'] = (int)($profile['identity_id'] ?? 0);
    $profile['must_change_password'] = (int)$profile['must_change_password'];
    $profile['is_supervisor'] = (int)($profile['is_supervisor'] ?? 0);
    $profile['effective_locale'] = appEffectiveLocale(
        $profile['preferred_locale'] ?? null, $profile['default_locale'] ?? null
    );
    $profile['csrf_token'] = startSecureSession($profile);
    unset($profile['token_version'], $profile['identity_token_version']);

    echo json_encode([
        'status' => 'Success',
        'message' => 'Password updated successfully. You can now continue.',
        'message_key' => 'auth.passwordSuccess',
        'message_params' => new stdClass(),
        'data' => $profile,
    ]);
} catch (Throwable $e) {
    $code = (int)$e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($code);
    echo json_encode(apiErrorPayload($e));
}
