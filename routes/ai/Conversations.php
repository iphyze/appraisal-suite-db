<?php
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/AiFeatureFlags.php';
require_once __DIR__ . '/../../includes/AiChatHistory.php';

$user = authenticateUser();
if (!aiAllowedRole((string)$user['role_key'])) throw new Exception('AI history access denied.', 403);
// Chat-history reads and management are part of the Assistant feature. A disabled
// Assistant must not leave its transcripts accessible through a direct API URL.
aiRequireFeature($conn, 'assistant');
$selected = isset($_GET['view_company_id']) ? (int)$_GET['view_company_id'] : null;
[$companyId, $fingerprint] = aiChatScope($conn, $user, $selected);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$ownerId = (int)$user['id'];
$role = (string)$user['role_key'];
aiChatPurgeOld($conn, $ownerId);

if ($method === 'GET') {
    $search = $_GET['search'] ?? '';
    if (!is_string($search) || strlen($search) > 100 || !preg_match('//u', $search)) throw new Exception('Invalid search.', 422);
    $output = aiChatList($conn, $user, $companyId, $fingerprint, trim($search));
} elseif (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 2000) throw new Exception('Invalid request size.', 413);
    $input = json_decode($raw, true);
    if (!is_array($input)) throw new Exception('Expected a JSON object.', 400);
    if ($method === 'POST') {
        $stmt = $conn->prepare("INSERT INTO ai_conversations (owner_user_id, company_context_id, role_key, permission_fingerprint, title) VALUES (?, ?, ?, ?, 'New conversation')");
        $stmt->bind_param('iiss', $ownerId, $companyId, $role, $fingerprint);
        $stmt->execute();
        $output = ['id' => (int)$conn->insert_id, 'title' => 'New conversation'];
        $stmt->close();
    } else {
        $id = aiChatValidateId($input['conversation_id'] ?? null);
        aiChatRequireOwned($conn, $user, $companyId, $fingerprint, $id);
        if ($method === 'PUT') {
            $title = aiChatValidateTitle($input['title'] ?? null);
            $stmt = $conn->prepare('UPDATE ai_conversations SET title = ? WHERE id = ?');
            $stmt->bind_param('si', $title, $id);
            $stmt->execute();
            $stmt->close();
            $output = ['id' => $id, 'title' => $title];
        } else {
            $stmt = $conn->prepare('DELETE FROM ai_conversations WHERE id = ? AND owner_user_id = ?');
            $stmt->bind_param('ii', $id, $ownerId);
            $stmt->execute();
            $stmt->close();
            $output = ['deleted' => true];
        }
    }
} else {
    throw new Exception('Method not allowed.', 405);
}
echo json_encode(['status' => 'Success', 'data' => $output], JSON_INVALID_UTF8_SUBSTITUTE);
