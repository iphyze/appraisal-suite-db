<?php
/** Super Admin: monthly AI token budget and usage by feature. */
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/AiFeatureFlags.php';

$user = requireRoles(['super_admin']);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $status = aiBudgetStatus($conn);
    $catalog = aiFeatureCatalog();
    $byFeature = array_map(static function ($row) use ($catalog) {
        $row['label'] = $catalog[$row['feature']]['label'] ?? 'Other or earlier requests';
        return $row;
    }, aiBudgetUsageByFeature($conn));
    header('Cache-Control: private, no-store, max-age=0');
    echo json_encode(['status' => 'Success', 'data' => [
        'budget' => $status,
        'by_feature' => $byFeature,
        'estimated_cost' => aiBudgetCostEstimate($status['input_tokens'], $status['output_tokens']),
        'cost_currency' => 'USD',
    ]]);
    return;
}

if ($method !== 'PUT') throw new Exception('Method not allowed.', 405);
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 2048) throw new Exception('Invalid request.', 413);
$input = json_decode($raw, true);
if (!is_array($input)) throw new Exception('Expected JSON body.', 400);
[$limit, $warn, $reason] = aiValidateBudgetChange((string)$user['role_key'],
    $input['monthly_token_limit'] ?? null, $input['warn_percent'] ?? null, $input['reason'] ?? null);

try {
    $conn->begin_transaction();
    $result = $conn->query('SELECT monthly_token_limit, warn_percent FROM ai_budget_settings WHERE id = 1 FOR UPDATE');
    $row = $result ? $result->fetch_assoc() : null;
    if (!$row) throw new Exception('AI budget settings not found. Apply migration 17.', 503);
    $previousLimit = (int)$row['monthly_token_limit'];
    $previousWarn = (int)$row['warn_percent'];
    if ($previousLimit !== $limit || $previousWarn !== $warn) {
        $actor = (int)$user['id'];
        $stmt = $conn->prepare('UPDATE ai_budget_settings SET monthly_token_limit = ?, warn_percent = ?, updated_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = 1');
        $stmt->bind_param('iii', $limit, $warn, $actor);
        $stmt->execute();
        $stmt->close();
        $stmt = $conn->prepare('INSERT INTO ai_budget_change_log (previous_limit, new_limit, previous_warn_percent, new_warn_percent, changed_by, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())');
        $stmt->bind_param('iiiiis', $previousLimit, $limit, $previousWarn, $warn, $actor, $reason);
        $stmt->execute();
        $stmt->close();
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    if ($e instanceof Exception && $e->getCode() === 503) throw $e;
    error_log('[AppraisalSuite AI] Budget update failed: ' . get_class($e));
    throw new Exception('Unable to update the AI budget.', 503);
}
echo json_encode(['status' => 'Success', 'data' => ['budget' => aiBudgetStatus($conn)]]);
