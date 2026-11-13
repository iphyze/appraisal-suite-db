<?php
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/AiLanguage.php';
require_once __DIR__ . '/../../includes/AiWriting.php';
require_once __DIR__ . '/../../includes/AiConversation.php';

$user = authenticateUser();
$aiLocale = aiUserLocale($user);
$role = (string)($user['role_key'] ?? '');
if (!aiAllowedRole($role)) throw new Exception('AI access denied.', 403);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? ''));
if ($method === 'GET') {
    echo json_encode(['status' => 'Success', 'data' => aiPublicAvailability($conn, 'writing_assistant')]);
    return;
}
if ($method !== 'POST') throw new Exception('Method not allowed.', 405);
aiRequireFeature($conn, 'writing_assistant');
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 9000) throw new Exception('Request is too large.', 413);
$data = json_decode($raw, true);
if (!is_array($data)) throw new Exception('Expected a JSON object.', 400);
$input = aiWritingInput($data, $role);
$requestId = aiValidateRequestId($data['request_id'] ?? null);
$fingerprint = 'writing-assistant:' . json_encode($input, JSON_INVALID_UTF8_SUBSTITUTE);
$usageId = aiReserveUsage($conn, $user, $requestId, $fingerprint, 'writing_assistant');
try {
    $response = aiPostResponses([
        'model' => aiEnv('OPENAI_MODEL', 'gpt-4.1-mini'),
        'instructions' => aiInstructionsForLocale(aiWritingInstructions(), $aiLocale),
        'input' => aiWritingPrompt($input),
        'store' => false,
        'max_output_tokens' => 500,
    ]);
    $suggestion = aiExtractResponseText($response);
    if ($suggestion === '') throw new Exception('AI returned an empty draft.', 502);
    aiFinishUsage($conn, $usageId, true, $response['usage'] ?? []);
} catch (Throwable $e) {
    aiFinishUsage($conn, $usageId, false);
    throw $e;
}
echo json_encode(['status' => 'Success', 'data' => [
    'suggestion' => $suggestion,
    'request_id' => $requestId,
    'applied' => false,
]], JSON_INVALID_UTF8_SUBSTITUTE);
