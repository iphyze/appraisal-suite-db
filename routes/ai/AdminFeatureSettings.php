<?php
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/AiFeatureFlags.php';

$user = requireRoles(['super_admin']);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    $flags = aiReadFeatureFlags($conn);
    $items = [];
    foreach (aiFeatureCatalog() as $key => $meta) {
        $items[] = [
            'key' => $key, 'label' => $meta['label'], 'released' => $meta['released'], 'enabled' => $flags[$key],
            'description' => $meta['description'] ?? '', 'uses_tokens' => !empty($meta['uses_tokens']),
            'group' => $meta['group'] ?? 'available', 'requires' => $meta['requires'] ?? null,
            'token_mode' => $meta['token_mode'] ?? (!empty($meta['uses_tokens']) ? 'always' : 'never'),
            'effective' => aiFeatureEnabled($flags, $key),
        ];
    }
    echo json_encode(['status' => 'Success', 'data' => [
        'features' => $items,
        'server_ready' => aiConfigured(),
        'budget' => aiBudgetStatus($conn),
        'message' => aiConfigured() ? '' : 'An API key and the server AI switch are required before any enabled AI module can run.',
    ]]);
    return;
}
if ($method !== 'PUT') throw new Exception('Method not allowed.', 405);
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 2048) throw new Exception('Invalid request.', 413);
$input = json_decode($raw, true);
if (!is_array($input)) throw new Exception('Expected JSON body.', 400);
[$feature, $enabled, $reason] = aiValidateFeatureChange($user['role_key'], $input['feature'] ?? null, $input['enabled'] ?? null, $input['reason'] ?? null);

try {
    $conn->begin_transaction();
    $stmt = $conn->prepare('SELECT is_enabled FROM ai_feature_settings WHERE feature_key = ? FOR UPDATE');
    $stmt->bind_param('s', $feature);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) throw new Exception('AI setting not found. Apply migrations 14 to 17.', 503);
    $previous = (int)$row['is_enabled'];
    if ($previous !== $enabled) {
        $actor = (int)$user['id'];
        $stmt = $conn->prepare('UPDATE ai_feature_settings SET is_enabled = ?, updated_by = ?, updated_at = UTC_TIMESTAMP() WHERE feature_key = ?');
        $stmt->bind_param('iis', $enabled, $actor, $feature);
        $stmt->execute();
        $stmt->close();
        $stmt = $conn->prepare('INSERT INTO ai_feature_change_log (feature_key, previous_state, new_state, changed_by, reason, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())');
        $stmt->bind_param('siiis', $feature, $previous, $enabled, $actor, $reason);
        $stmt->execute();
        $stmt->close();
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    if ($e instanceof Exception && $e->getCode() === 503) throw $e;
    error_log('[AppraisalSuite AI] Feature update failed: ' . get_class($e));
    throw new Exception('Unable to update AI feature settings.', 503);
}
// No silent persistence: UI can immediately refetch GET to confirm effective state.
echo json_encode(['status' => 'Success', 'data' => ['feature' => $feature, 'enabled' => (bool)$enabled, 'changed' => $previous !== $enabled]]);
