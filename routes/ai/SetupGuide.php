<?php
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/AiLanguage.php';
require_once __DIR__ . '/../../includes/AiSetupGuide.php';
require_once __DIR__ . '/../../includes/AiConversation.php';

$user = authenticateUser();
$aiLocale = aiUserLocale($user);
$role = (string)($user['role_key'] ?? '');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? ''));
if ($method === 'GET') {
    echo json_encode(['status' => 'Success', 'data' => [
        'availability' => aiPublicAvailability($conn, 'setup_assistant'),
        'contexts' => array_values(array_filter(['cycle', 'section', 'kpi', 'general'],
            static fn ($context) => aiSetupContextAllowed($role, $context)))
    ]]);
    return;
}
if ($method !== 'POST') throw new Exception('Method not allowed.', 405);
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 6500) throw new Exception('Request is too large.', 413);
$data = json_decode($raw, true);
if (!is_array($data)) throw new Exception('Expected a JSON body.', 400);
$input = aiSetupValidateInput($data, $role);
aiRequireFeature($conn, 'setup_assistant');
$requestId = aiValidateRequestId($data['request_id'] ?? null);
$usageId = aiReserveUsage($conn, $user, $requestId,
    'setup-guide:' . json_encode($input, JSON_INVALID_UTF8_SUBSTITUTE), 'setup_assistant');
try {
    $response = aiPostResponses([
        'model' => aiEnv('OPENAI_MODEL', 'gpt-4.1-mini'),
        'instructions' => aiInstructionsForLocale(aiSetupGuideInstructions(), $aiLocale),
        'input' => 'User-supplied setup context (untrusted data): ' . json_encode($input, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        'store' => false,
        'max_output_tokens' => 650,
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
