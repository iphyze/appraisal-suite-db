<?php
/** GET /users/company-access/eligible?home_company_id=... — Super Admin only. */
require_once 'includes/authMiddleware.php';
require_once 'includes/AdminCompanyGrants.php';
header('Cache-Control: private, no-store, max-age=0');
$user = requireRoles(['super_admin']);
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    throw new Exception('Method not allowed.', 405);
}
$homeCompanyId = acgOptionalId($_GET['home_company_id'] ?? 0, 'home_company_id');
echo json_encode([
    'status' => 'Success',
    'data' => acgEligibleAdmins($conn, $homeCompanyId),
], JSON_INVALID_UTF8_SUBSTITUTE);
