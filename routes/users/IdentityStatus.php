<?php
/** Super Admin only: disable or enable an entire login without changing membership statuses. */
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/IdentityUserManagement.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'PUT') throw new Exception('Only PUT is allowed.', 405);
    $actor = requireRoles(['super_admin']);
    $payload = json_decode(file_get_contents('php://input'), true);
    $targetId = (int)($payload['user_id'] ?? 0);
    $active = $payload['is_active'] ?? null;
    if ($targetId < 1 || !in_array($active, [0, 1, '0', '1'], true)) {
        throw new Exception('User ID and active status (0 or 1) are required.', 422);
    }
    $result = userIdentityTransaction($conn, static function(mysqli $db) use ($actor, $targetId, $active): array {
        $memberships = identityUserMembershipsForWrite($db, $targetId);
        $identityId = (int)($memberships[0]['identity_id'] ?? 0);
        if ($identityId <= 0) throw new Exception('This membership has no login identity.', 422);
        if ($identityId === (int)($actor['identity_id'] ?? 0)) throw new Exception('You cannot disable your own login.', 403);
        $identity = userIdentityFindById($db, $identityId, true);
        if (!$identity) throw new Exception('Identity not found.', 404);
        $newStatus = (int)$active;
        if ((int)$identity['is_active'] !== $newStatus) {
            $stmt = userIdentityPrepare($db, 'UPDATE user_identities SET is_active = ?, token_version = token_version + 1 WHERE id = ?');
            try { $stmt->bind_param('ii', $newStatus, $identityId); userIdentityExecute($stmt); }
            finally { $stmt->close(); }
            userIdentitySyncMembersInTransaction($db, $identityId, (int)$actor['id']);
            identityPasswordAuditAllInTransaction($db, $memberships, (int)$actor['id'],
                $newStatus === 1 ? 'activate_identity' : 'deactivate_identity',
                'Super Admin changed the person-level sign-in status; company memberships unchanged');
        }
        return ['identity_is_active' => $newStatus];
    });
    echo json_encode(['status' => 'Success', 'data' => $result]);
} catch (Throwable $e) {
    $code = (int)$e->getCode();
    http_response_code($code >= 400 && $code < 600 ? $code : 500);
    echo json_encode(['status' => 'Failed', 'message' => $code >= 500 ? 'Unable to update login status.' : $e->getMessage()]);
}
