<?php
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/AiLanguage.php';
require_once __DIR__ . '/../../includes/AiDevelopmentPlan.php';
require_once __DIR__ . '/../../includes/AiConversation.php';

$user = authenticateUser();
$aiLocale = aiUserLocale($user);
$role = (string)($user['role_key'] ?? '');
if (!aiAllowedRole($role)) throw new Exception('AI access denied.', 403);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? ''));
if ($method === 'GET') {
    // Access to the feature itself is limited to the roles which can draft a plan.
    if (!in_array($role, ['supervisor', 'admin', 'super_admin'], true)) {
        throw new Exception('Development planning is restricted to authorized appraisers.', 403);
    }
    echo json_encode(['status' => 'Success', 'data' => aiPublicAvailability($conn, 'development_plan')]);
    return;
}
if ($method !== 'POST') throw new Exception('Method not allowed.', 405);
aiRequireFeature($conn, 'development_plan');
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 11000) throw new Exception('Request is too large.', 413);
$data = json_decode($raw, true);
if (!is_array($data)) throw new Exception('Expected a JSON object.', 400);
$input = aiDevelopmentInput($data, $role);
$requestId = aiValidateRequestId($data['request_id'] ?? null);
$usageId = aiReserveUsage($conn, $user, $requestId,
    'development-plan:' . json_encode($input, JSON_INVALID_UTF8_SUBSTITUTE), 'development_plan');
try {
    $response = aiPostResponses([
        'model' => aiEnv('OPENAI_MODEL', 'gpt-4.1-mini'),
        'instructions' => aiInstructionsForLocale(aiDevelopmentInstructions(), $aiLocale),
        'input' => aiDevelopmentPrompt($input),
        'store' => false,
        'max_output_tokens' => 650,
    ]);
    $draft = aiExtractResponseText($response);
    if ($draft === '') throw new Exception('AI returned an empty plan.', 502);
    if (strlen($draft) > 3500) throw new Exception('AI returned a plan that is too long.', 502);
    aiFinishUsage($conn, $usageId, true, $response['usage'] ?? []);
} catch (Throwable $e) {
    aiFinishUsage($conn, $usageId, false);
    throw $e;
}
echo json_encode(['status' => 'Success', 'data' => [
    'suggestion' => $draft,
    'request_id' => $requestId,
    'applied' => false,
]], JSON_INVALID_UTF8_SUBSTITUTE);
