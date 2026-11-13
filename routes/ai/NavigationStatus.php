<?php
/** Lightweight, authenticated availability for navigation only. */
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/AiFeatureFlags.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'GET') {
    throw new Exception('Method not allowed.', 405);
}
$user = authenticateUser();
$role = (string)($user['role_key'] ?? '');
if (!aiAllowedRole($role)) throw new Exception('Access denied.', 403);
$flags = aiReadFeatureFlags($conn);
header('Cache-Control: private, no-store, max-age=0');
echo json_encode(['status' => 'Success', 'data' => [
    'features' => aiNavigationAvailability($flags, aiConfigured(), $role),
]]);
