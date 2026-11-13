<?php
/** POST /auth/switch-workspace { user_id } — switch only OWN active memberships. */
require_once __DIR__ . '/../../includes/ApiException.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/UserIdentity.php';
require_once __DIR__ . '/../../includes/IdentityWorkspaces.php';
require_once __DIR__ . '/../../includes/Locale.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Only POST is allowed.', 405);
    }
    // The router also validates CSRF; check again for direct route entry.
    requireCsrfForMutation();
    $actor = authenticateUser();
    if ((int)($actor['must_change_password'] ?? 0) === 1) {
        throw new Exception('Password update required before continuing.', 428);
    }
    $payload = json_decode(file_get_contents('php://input'), true);
    $rawUserId = is_array($payload) ? ($payload['user_id'] ?? null) : null;
    if ((!is_int($rawUserId) && !is_string($rawUserId))
        || !preg_match('/^[1-9][0-9]*$/D', (string)$rawUserId)) {
        throw new Exception('Invalid workspace membership.', 422);
    }
    $targetUserId = (int)$rawUserId;
    $identityId = (int)($actor['identity_id'] ?? 0);
    if ($identityId <= 0) {
        throw new Exception('Workspace switching is not available for this account.', 403);
    }

    // Lock identity to serialize with shared-password changes and global revocations.
    // Re-read BOTH source and destination memberships inside this transaction.
    $target = userIdentityTransaction($conn, static function (mysqli $db) use ($actor, $identityId, $targetUserId): array {
        $identity = userIdentityFindById($db, $identityId, true);
        if (!$identity || (int)$identity['is_active'] !== 1) {
            throw new Exception('Workspace is unavailable.', 403);
        }
        $members = userIdentityMemberships($db, $identityId, true, true);
        $destination = identityWorkspaceFindTarget($actor, $members, $targetUserId);
        if ($destination === null) {
            // Never reveal whether a foreign person's membership ID exists.
            throw new Exception('Workspace is unavailable or you do not have access.', 403);
        }
        $source = null;
        foreach ($members as $member) {
            if ((int)$member['user_id'] === (int)$actor['id']) {
                $source = $member;
                break;
            }
        }
        if ($source === null) throw new Exception('Current workspace is unavailable.', 403);
        identityWorkspaceAuditSwitch($db, $source, $destination);

        // Fresh role/department/company details from the DESTINATION membership.
        // Fresh identity version retains revocation semantics across workspaces.
        $destination['id'] = (int)$destination['user_id'];
        $destination['identity_id'] = $identityId;
        $destination['token_version'] = (int)$identity['token_version'];
        $destination['identity_token_version'] = (int)$identity['token_version'];
        $destination['must_change_password'] = (int)$identity['must_change_password'];
        $destination['password_changed_at'] = $identity['password_changed_at'];
        $destination['last_login_at'] = $identity['last_login_at'];
        $destination['preferred_locale'] = $identity['preferred_locale'];
        $destination['email'] = $identity['email'];
        return $destination;
    });

    // Rotate the browser's signed session AND CSRF. No client-supplied role,
    // identity, company ID or security claim is trusted.
    $csrfToken = startSecureSession($target);
    unset($target['password_hash'], $target['token_version'], $target['identity_token_version'],
          $target['is_active'], $target['company_is_active'], $target['user_id'], $target['role_id']);
    $target['id'] = (int)$target['id'];
    $target['company_id'] = (int)$target['company_id'];
    $target['is_supervisor'] = (int)($target['is_supervisor'] ?? 0);
    $target['effective_locale'] = appEffectiveLocale(
        $target['preferred_locale'] ?? null, $target['default_locale'] ?? null);
    $target['csrf_token'] = $csrfToken;

    echo json_encode(['status' => 'Success', 'message' => 'Workspace switched.', 'data' => $target],
        JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    $status = (int)$error->getCode();
    $status = $status >= 400 && $status <= 599 ? $status : 500;
    http_response_code($status);
    echo json_encode(apiErrorPayload($error,
        $status >= 500 && function_exists('apiIsProduction') && apiIsProduction()
            ? 'Internal server error.' : null));
}
