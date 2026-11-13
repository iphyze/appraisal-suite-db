<?php
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/AiFeatureFlags.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'GET') {
    throw new Exception('Method not allowed.', 405);
}
$user = authenticateUser();
if (!aiAllowedRole((string)$user['role_key'])) throw new Exception('AI assistant access denied.', 403);
$availability = aiPublicAvailability($conn, 'assistant');
echo json_encode([
    'status' => 'Success',
    'data' => [
        'enabled' => $availability['enabled'],
        'feature_enabled' => $availability['feature_enabled'],
        'server_ready' => $availability['server_ready'],
        'phase' => 'read_only_assistant',
        'live_data_enabled' => $availability['enabled'],
        'hourly_limit' => aiIntSetting('AI_HOURLY_LIMIT', 12, 1, 100),
        'daily_limit' => aiIntSetting('AI_DAILY_LIMIT', 40, 1, 500),
        'panel_enabled' => aiPublicAvailability($conn, 'ask_panel')['enabled'],
        // Only whether the monthly budget is used up; figures are for the Super Admin.
        'budget_reached' => aiBudgetStatus($conn)['reached'],
    ],
]);
