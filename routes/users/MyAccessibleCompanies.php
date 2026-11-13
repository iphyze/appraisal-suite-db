<?php
/** GET /users/my-accessible-companies — authenticated, refreshed from the DB. */
require_once 'includes/authMiddleware.php';
require_once 'includes/CompanyAccess.php'; // Added in Batch 2.
header('Cache-Control: private, no-store, max-age=0');
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    throw new Exception('Method not allowed.', 405);
}
$user = authenticateUser();
echo json_encode([
    'status' => 'Success',
    'data' => listAccessibleCompanies($user, $conn),
], JSON_INVALID_UTF8_SUBSTITUTE);
