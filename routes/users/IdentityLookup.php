<?php
/** Safe lookup for the Create/Edit user form: never expose another login's names or role. */
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/IdentityUserManagement.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') throw new Exception('Only GET is allowed.', 405);
    $actor = requireRoles(['super_admin', 'admin']);
    $context = companyUserWriteContext($actor);
    $email = userIdentityNormalizeEmail((string)($_GET['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Invalid email.', 422);
    $companyId = companyUserWriteCreateCompanyId($actor, $context, $_GET);
    $stmt = userIdentityPrepare($conn, 'SELECT id FROM companies WHERE id = ? AND is_active = 1');
    try { $stmt->bind_param('i', $companyId); userIdentityExecute($stmt);
        if (!$stmt->get_result()->fetch_assoc()) throw new Exception('Company unavailable.', 404); }
    finally { $stmt->close(); }
    $stmt = userIdentityPrepare($conn, 'SELECT id FROM users WHERE company_id = ? AND LOWER(TRIM(email)) = ? LIMIT 1');
    try { $stmt->bind_param('is', $companyId, $email); userIdentityExecute($stmt);
        $alreadyHere = (bool)$stmt->get_result()->fetch_assoc(); }
    finally { $stmt->close(); }
    $identity = userIdentityFindByEmail($conn, $email);
    $manual = identityUserManualReviewEmail($email) || identityUserEmailHasUnlinkedMembers($conn, $email);
    $visibleNames = [];
    if ($identity) {
        $grants = [];
        foreach (userIdentityMemberships($conn, (int)$identity['id'], false) as $member) {
            if ((int)$member['is_active'] !== 1 || (int)$member['company_is_active'] !== 1) continue;
            $id = (int)$member['company_id'];
            if (!array_key_exists($id, $grants)) {
                $grants[$id] = $id === (int)$actor['company_id'] || authRoleKey($actor['role']) === 'super_admin'
                    ? null : companyAccessFetchActiveGrant($conn, (int)$actor['id'], $id);
            }
            if (identityUserCanSeeMembership($actor, $member, $grants[$id])) $visibleNames[$id] = $member['company_name'];
        }
    }
    echo json_encode(['status' => 'Success', 'data' => [
        'exists' => $identity !== null,
        'already_in_company' => $alreadyHere,
        'manual_review' => $manual,
        'company_names' => array_values($visibleNames),
    ]]);
} catch (Throwable $e) {
    $code = (int)$e->getCode();
    http_response_code($code >= 400 && $code < 600 ? $code : 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getCode() >= 500 ? 'Unable to look up login.' : $e->getMessage()]);
}
