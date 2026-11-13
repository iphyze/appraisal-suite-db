<?php
/** Revoke every session for the current person, then clear this browser. */
require_once __DIR__ . '/../../includes/ApiException.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once 'includes/connection.php';
require_once __DIR__ . '/../../includes/UserIdentity.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Bad Request: Only POST method is allowed.', 405);
    }
    // Repeat the central check for deployments that expose route files directly.
    requireCsrfForMutation();
    $user = authenticateUser();
    $identityId = (int)($user['identity_id'] ?? 0);
    if ($identityId <= 0) {
        // Deferred, unlinked memberships still use their own token version.
        $stmt = $conn->prepare('UPDATE users SET token_version = COALESCE(token_version, 0) + 1 WHERE id = ?');
        if (!$stmt) throw new Exception('Unable to revoke sessions.', 500);
        $userId = (int)$user['id'];
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) throw new Exception('Unable to revoke sessions.', 500);
        $stmt->close();
    } else {
        userIdentityRevokeSessions($conn, $identityId);
    }
    clearAuthCookies();
    echo json_encode(['status' => 'Success', 'message' => 'All sessions have been signed out.']);
} catch (Throwable $e) {
    $code = (int)$e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($code);
    echo json_encode(apiErrorPayload($e));
}
