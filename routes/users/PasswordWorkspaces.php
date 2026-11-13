<?php
/** Password-use notice, for the signed-in person or an authorized reset admin. */
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/IdentityPasswords.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Exception('Only GET is allowed.', 405);
    $actor = authenticateUser();
    $targetUserId = (int)($actor['id'] ?? 0);
    if (isset($_GET['user_id'])) {
        $raw = $_GET['user_id'];
        if (!is_string($raw) || !preg_match('/^[1-9][0-9]*$/D', $raw)) {
            throw new Exception('Invalid user ID.', 400);
        }
        $targetUserId = (int)$raw;
    }
    $members = identityPasswordMembershipRecords($conn, $targetUserId);
    if (!$members) throw new Exception('User not found.', 404);

    $self = (int)$actor['id'] === $targetUserId
        || ((int)($actor['identity_id'] ?? 0) > 0
            && (int)$members[0]['identity_id'] === (int)$actor['identity_id']);
    if (!$self) {
        if ((int)($actor['must_change_password'] ?? 0) === 1) {
            throw new Exception('Password update required before continuing.', 428);
        }
        if (!in_array(authRoleKey($actor['role']), ['super_admin', 'admin'], true)) {
            throw new Exception('You cannot view another account.', 403);
        }
        identityPasswordAssertAdminCanReset($conn, $actor, $members, $targetUserId);
    }
    echo json_encode([
        'status' => 'Success', 'data' => [
            'company_names' => identityPasswordCompanyNames($members),
            'membership_count' => count($members),
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
