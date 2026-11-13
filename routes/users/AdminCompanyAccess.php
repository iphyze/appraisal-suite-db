<?php
require_once __DIR__ . '/../../includes/ApiException.php';
/**
 * Super Admin company grant management.
 * GET    /users/company-access?status=all|active|revoked&page=1&limit=100
 * POST   /users/company-access {user_id, company_id, access_level?, staff_scope?, receive_notifications?, reason}
 * PATCH  /users/company-access {grant_id, access_level?, staff_scope?, receive_notifications?, reason}
 * DELETE /users/company-access {grant_id, reason}
 * All mutations require CSRF via the existing central router.
 */
require_once 'includes/authMiddleware.php';
require_once 'includes/AdminCompanyGrants.php';
header('Cache-Control: private, no-store, max-age=0');
$user = requireRoles(['super_admin']);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $status = $_GET['status'] ?? 'all';
    if (!is_string($status)) throw new Exception('Invalid grant status filter.', 422);
    $userId = acgOptionalId($_GET['user_id'] ?? 0, 'user_id');
    $companyId = acgOptionalId($_GET['company_id'] ?? 0, 'company_id');
    $page = acgPositiveId($_GET['page'] ?? 1, 'page');
    $limit = acgPositiveId($_GET['limit'] ?? 100, 'limit');
    if ($limit > 250) throw new Exception('Maximum page size is 250.', 422);
    $result = acgListGrants($conn, $status, $userId, $companyId, $page, $limit);
    echo json_encode(['status' => 'Success', 'data' => $result['grants'], 'meta' => $result['meta']], JSON_INVALID_UTF8_SUBSTITUTE);
    return;
}

if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    throw new Exception('Method not allowed.', 405);
}
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 8192) throw new Exception('Invalid request body.', 413);
$decoded = json_decode($raw);
if (!($decoded instanceof stdClass)) {
    throw new Exception('Expected a JSON object.', 400);
}
$payload = json_decode($raw, true);

if ($method === 'POST') {
    $grant = acgCreateGrant($conn, $user, $payload);
    http_response_code(201);
    $message = 'Company access granted successfully.';
} elseif ($method === 'PUT' || $method === 'PATCH') {
    $grant = acgUpdateGrant($conn, $user, $payload);
    $message = 'Company access updated successfully.';
} else {
    $grant = acgRevokeGrant($conn, $user, $payload);
    $message = 'Company access revoked successfully.';
}
echo json_encode(apiMessagePayload('Success', $message, ['data' => $grant]), JSON_INVALID_UTF8_SUBSTITUTE);
