<?php
/** List only the signed-in identity's active membership workspaces. */
require_once __DIR__ . '/../../includes/ApiException.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/UserIdentity.php';
require_once __DIR__ . '/../../includes/IdentityWorkspaces.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        throw new Exception('Only GET is allowed.', 405);
    }
    $actor = authenticateUser();
    if ((int)($actor['must_change_password'] ?? 0) === 1) {
        throw new Exception('Password update required before continuing.', 428);
    }
    $identityId = (int)($actor['identity_id'] ?? 0);
    $memberships = $identityId > 0 ? userIdentityMemberships($conn, $identityId, true) : [];
    echo json_encode([
        'status' => 'Success',
        'data' => ['workspaces' => identityWorkspacePublicList($actor, $memberships)],
    ], JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    $status = (int)$error->getCode();
    $status = $status >= 400 && $status <= 599 ? $status : 500;
    http_response_code($status);
    echo json_encode(apiErrorPayload($error,
        $status >= 500 && function_exists('apiIsProduction') && apiIsProduction()
            ? 'Internal server error.' : null));
}
