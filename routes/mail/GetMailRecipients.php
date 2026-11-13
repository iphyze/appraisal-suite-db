<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'routes/utils/MailRecipients.php';
require_once 'includes/CompanyMailAccess.php';
header('Content-Type: application/json');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Exception('Bad Request: Only GET method is allowed', 405);
    $userData = requireRoles(['super_admin', 'admin']);
    $mailContext = companyMailContext($userData);
    $audience = trim((string)($_GET['audience'] ?? 'pending_acknowledgements'));
    $cycleId = isset($_GET['cycle_id']) ? (int)$_GET['cycle_id'] : 0;
    $search = trim((string)($_GET['search'] ?? ''));
    $includeOtherScopes = (string) ($_GET['include_other_scopes'] ?? '0') === '1';
    $result = fetchMailRecipients($conn, $userData, $audience, $cycleId, $search, $includeOtherScopes, $mailContext);
    echo json_encode(['status' => 'Success', 'message' => 'Recipients fetched successfully.', 'data' => $result['rows'], 'meta' => ['count' => count($result['rows']), 'cycle' => $result['cycle'], 'staff_scope' => $result['scope'], 'can_expand' => $result['can_expand'], 'expanded' => $result['expanded']]]);
} catch (Exception $e) {
    $code = (int)$e->getCode(); $code = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($code);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
