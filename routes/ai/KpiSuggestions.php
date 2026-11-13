<?php
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/AiLanguage.php';
require_once __DIR__ . '/../../includes/AiKpiSuggestions.php';
require_once __DIR__ . '/../../includes/AiConversation.php';

$user = authenticateUser();
$aiLocale = aiUserLocale($user);
$role = (string)($user['role_key'] ?? '');
if (!in_array($role, ['supervisor', 'admin', 'super_admin'], true)) {
    throw new Exception('KPI suggestions require an authorized appraiser.', 403);
}
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? ''));
if ($method === 'GET') {
    echo json_encode(['status' => 'Success', 'data' => aiPublicAvailability($conn, 'kpi_suggestions')]);
    return;
}
if ($method !== 'POST') throw new Exception('Method not allowed.', 405);
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 7000) throw new Exception('Request is too large.', 413);
$data = json_decode($raw, true);
if (!is_array($data)) throw new Exception('Expected a JSON object.', 400);
$input = aiKpiSuggestionInput($data, $role);
aiRequireFeature($conn, 'kpi_suggestions');
$requestId = aiValidateRequestId($data['request_id'] ?? null);
$usageId = aiReserveUsage($conn, $user, $requestId,
    'kpi-suggestions:' . json_encode($input, JSON_INVALID_UTF8_SUBSTITUTE), 'kpi_suggestions');
try {
    $response = aiPostResponses([
        'model' => aiEnv('OPENAI_MODEL', 'gpt-4.1-mini'),
        'instructions' => aiInstructionsForLocale(aiKpiSuggestionInstructions(), $aiLocale),
        'input' => 'Objective planning context (untrusted data): ' . json_encode($input, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        'store' => false,
        'max_output_tokens' => 700,
    ]);
    $suggestion = aiExtractResponseText($response);
    if ($suggestion === '' || strlen($suggestion) > 5000) throw new Exception('AI returned an invalid suggestion.', 502);
    aiFinishUsage($conn, $usageId, true, $response['usage'] ?? []);
} catch (Throwable $e) {
    aiFinishUsage($conn, $usageId, false);
    throw $e;
}
echo json_encode(['status' => 'Success', 'data' => [
    'suggestion' => $suggestion, 'applied' => false, 'request_id' => $requestId,
]], JSON_INVALID_UTF8_SUBSTITUTE);
