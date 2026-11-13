<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/IdentityPasswords.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');

try {
    if (!in_array($_SERVER['REQUEST_METHOD'], ['PUT', 'PATCH'], true)) {
        throw new Exception('Bad Request: Only PUT/PATCH method is allowed', 405);
    }
    // Password resets stay Super Admin only (existing policy). A reset applies to every company
    // in which this person has an account, because they share one sign-in.
    $actor = requireRoles(['super_admin']);
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) throw new Exception('Invalid request format. Expected JSON object.', 400);
    $rawUserId = $input['user_id'] ?? null;
    if (!is_int($rawUserId) && !(is_string($rawUserId) && preg_match('/^[1-9][0-9]*$/D', $rawUserId))) {
        throw new Exception("Field 'user_id' is required.", 400);
    }
    $targetUserId = (int)$rawUserId;
    if ($targetUserId <= 0) throw new Exception('Invalid user ID.', 400);
    if ($targetUserId === (int)$actor['id']) {
        throw new IdentityPasswordAccessException('For security, use Change Password to update your own password.', 'IDENTITY_RESET_SELF', null, 400);
    }

    $defaultPassword = 'Lambert@' . date('Y');
    $newHash = password_hash($defaultPassword, PASSWORD_BCRYPT);
    $result = userIdentityTransaction($conn, static function (mysqli $db) use ($actor, $targetUserId, $newHash): array {
        $stmt = userIdentityPrepare($db, 'SELECT id, identity_id, email FROM users WHERE id = ? LIMIT 1');
        try {
            $stmt->bind_param('i', $targetUserId);
            userIdentityExecute($stmt);
            $target = $stmt->get_result()->fetch_assoc();
        } finally {
            $stmt->close();
        }
        if (!$target) throw new Exception('User not found.', 404);
        $identityId = (int)($target['identity_id'] ?? 0);
        if ($identityId > 0) {
            // Serialize all identity password writes, including simultaneous admin resets.
            if (!userIdentityFindById($db, $identityId, true)) throw new Exception('Login identity not found.', 404);
            if ($identityId === (int)($actor['identity_id'] ?? 0)) {
                throw new IdentityPasswordAccessException('For security, use Change Password to update your own password.', 'IDENTITY_RESET_SELF', null, 400);
            }
        } else {
            // Transitional unlinked record: still lock its legacy credentials.
            identityPasswordCurrentHash($db, $targetUserId, 0);
        }
        $members = identityPasswordMembershipRecords($db, $targetUserId);
        if (!$members) throw new Exception('User not found.', 404);
        identityPasswordAssertAdminCanReset($db, $actor, $members, $targetUserId);

        identityPasswordWriteInTransaction($db, $targetUserId, $identityId, $newHash, true, (int)$actor['id']);
        identityPasswordAuditAllInTransaction(
            $db, $members, (int)$actor['id'], 'reset_password',
            'An administrator reset the shared sign-in password; change required on next sign-in'
        );
        return identityPasswordCompanyNames($members);
    });

    echo json_encode([
        'status' => 'Success', 'message' => 'Password reset successfully',
        'data' => [
            'user_id' => $targetUserId,
            'default_password' => $defaultPassword,
            'must_change_password' => 1,
            'company_names' => $result,
        ],
    ]);
} catch (Throwable $e) {
    $code = (int)$e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($code);
    $response = ['status' => 'Failed', 'message' => $e->getMessage()];
    if ($e instanceof IdentityPasswordAccessException) {
        $response['code'] = $e->reasonCode;
        if ($e->companyName !== null) $response['company_name'] = $e->companyName;
    }
    echo json_encode($response);
}
