<?php
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/AiConversation.php';
require_once __DIR__ . '/../../includes/AiFeatureFlags.php';
require_once __DIR__ . '/../../includes/AiChatHistory.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    throw new Exception('Method not allowed.', 405);
}
$user = authenticateUser();
if (!aiAllowedRole((string)$user['role_key'])) throw new Exception('AI assistant access denied.', 403);
aiRequireFeature($conn, 'assistant');

$body = file_get_contents('php://input');
if ($body === false || strlen($body) > 18000) throw new Exception('Request is too large.', 413);
$data = json_decode($body, true);
if (!is_array($data)) throw new Exception('Expected a JSON object.', 400);
$message = aiValidateMessage($data['message'] ?? null);
$requestId = aiValidateRequestId($data['request_id'] ?? null);
// Questions from the Ask AI panel are counted separately and need its switch too.
$fromPanel = ($data['source'] ?? '') === 'panel';
if ($fromPanel) aiRequireFeature($conn, 'ask_panel');
$conversationId = null;
if (array_key_exists('conversation_id', $data)) {
    $conversationId = aiChatValidateId($data['conversation_id']);
    // Client-supplied history cannot replace server-owned conversation state.
    if (isset($data['history']) && $data['history'] !== []) throw new Exception('History is managed by the server.', 422);
}

// Selected context comes only from the router's authenticated GET parameter,
// never the prompt or a user-supplied company_id in request JSON.
$selectedCompanyId = isset($_GET['view_company_id']) ? (int)$_GET['view_company_id'] : null;
// An admin's selected company must be checked even for a general AI answer
// that happens not to invoke a data tool. Do not spend tokens on a revoked grant.
// Super Admin's historical general-chat behavior without selection is unchanged.
if (($user['role_key'] ?? '') === 'admin') aiReadCompanyId($user, $selectedCompanyId);
if ($conversationId !== null) {
    [$companyId, $fingerprint] = aiChatScope($conn, $user, $selectedCompanyId);
    aiChatRequireOwned($conn, $user, $companyId, $fingerprint, $conversationId);
    $history = aiChatModelHistory($conn, $conversationId);
} else {
    $history = aiValidateHistory($data['history'] ?? null); // Backward compatibility.
}
$usageId = aiReserveUsage($conn, $user, $requestId, $message, $fromPanel ? 'ask_panel' : 'assistant');
try {
    $result = aiAskWithRecords($conn, $user, $message, $history, $selectedCompanyId);
    // Persist only an entirely successful exchange. Failure leaves no partial turn.
    if ($conversationId !== null) aiChatSaveExchange($conn, $conversationId, $requestId, $message, $result);
    aiFinishUsage($conn, $usageId, true, $result['usage']);
} catch (Throwable $e) {
    aiFinishUsage($conn, $usageId, false);
    throw $e;
}
echo json_encode([
    'status' => 'Success',
    'data' => [
        'answer' => $result['answer'],
        'references' => $result['references'] ?? [],
        'request_id' => $requestId,
        'conversation_id' => $conversationId,
        'source' => $result['live_data_used'] ? 'authorized_records' : 'general_guidance',
        'live_data_used' => (bool)$result['live_data_used'],
    ],
], JSON_INVALID_UTF8_SUBSTITUTE);
