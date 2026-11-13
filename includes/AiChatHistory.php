<?php
/** AI Assistant chat storage — never trust IDs or scope supplied by the model/client. */
require_once __DIR__ . '/AiReadTools.php';
require_once __DIR__ . '/AiFoundation.php';

function aiChatContext(array $user, ?int $selectedCompanyId): int
{
    return aiReadCompanyId($user, $selectedCompanyId);
}

/** Any permission change that affects previously visible employee data hides older chats. */
function aiChatPermissionFingerprint(mysqli $conn, array $user, int $companyId): string
{
    $role = (string)$user['role_key'];
    $context = aiReadCompanyContext($user, $companyId);
    // Preserve the exact previous fingerprint for home and Super Admin chats.
    $parts = [$role, (string)$user['staff_scope'], (int)$user['is_supervisor'], $companyId];
    if (empty($context['is_home']) && ($context['access_level'] ?? '') !== 'full') {
        $parts[] = ['grant_id' => (int)$context['grant_id'],
            'access_level' => (string)$context['access_level'],
            'staff_scope' => (string)$context['staff_scope']];
    }
    if ($role === 'admin') {
        $id = (int)$user['id'];
        $stmt = $conn->prepare('SELECT visibility_mode FROM admin_appraisal_visibility_policies WHERE viewer_admin_id = ? AND company_id = ? LIMIT 1');
        $stmt->bind_param('ii', $id, $companyId);
        $stmt->execute();
        $parts[] = $stmt->get_result()->fetch_assoc()['visibility_mode'] ?? 'none';
        $stmt->close();
        $stmt = $conn->prepare('SELECT target_admin_id FROM admin_appraisal_visibility_targets WHERE viewer_admin_id = ? AND company_id = ? ORDER BY target_admin_id');
        $stmt->bind_param('ii', $id, $companyId);
        $stmt->execute();
        $parts[] = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'target_admin_id');
        $stmt->close();
    }
    if ($role === 'supervisor' || ($role === 'admin' && (int)($user['is_supervisor'] ?? 0) === 1)) {
        // Changes to supervised staff may affect historic authorized AI outputs.
        $id = (int)$user['id'];
        $stmt = $conn->prepare('SELECT sa.cycle_id, sa.staff_id FROM supervisor_assignments sa INNER JOIN users u ON u.id = sa.staff_id WHERE sa.supervisor_id = ? AND u.company_id = ? ORDER BY sa.cycle_id, sa.staff_id');
        $stmt->bind_param('ii', $id, $companyId);
        $stmt->execute();
        $parts[] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
}

function aiChatRetentionDays(): int
{
    return aiIntSetting('AI_CHAT_RETENTION_DAYS', 180, 7, 365);
}

/** Periodic on-access cleanup, scoped to this owner (never executed by OpenAI). */
function aiChatPurgeOld(mysqli $conn, int $ownerId): void
{
    $days = aiChatRetentionDays();
    $stmt = $conn->prepare('DELETE FROM ai_conversations WHERE owner_user_id = ? AND created_at < DATE_SUB(CURRENT_TIMESTAMP(), INTERVAL ? DAY)');
    $stmt->bind_param('ii', $ownerId, $days);
    $stmt->execute();
    $stmt->close();
}

function aiChatValidateId($raw): int
{
    $id = filter_var($raw, FILTER_VALIDATE_INT);
    if ($id === false || $id === null || $id < 1) throw new Exception('Invalid conversation identifier.', 422);
    return $id;
}

function aiChatValidateTitle($raw): string
{
    if (!is_string($raw) || !preg_match('//u', $raw)) throw new Exception('Invalid conversation title.', 422);
    $title = trim($raw);
    if ($title === '' || (function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title)) > 120) throw new Exception('Use a title between 1 and 120 characters.', 422);
    return $title;
}

function aiChatScope(mysqli $conn, array $user, ?int $selectedCompanyId): array
{
    $companyId = aiChatContext($user, $selectedCompanyId);
    return [$companyId, aiChatPermissionFingerprint($conn, $user, $companyId)];
}

/** 404 instead of 403 avoids disclosing existence of another user's chats. */
function aiChatRequireOwned(mysqli $conn, array $user, int $companyId, string $fingerprint, int $id): array
{
    $ownerId = (int)$user['id'];
    $role = (string)$user['role_key'];
    $stmt = $conn->prepare('SELECT id, title FROM ai_conversations WHERE id = ? AND owner_user_id = ? AND company_context_id = ? AND role_key = ? AND permission_fingerprint = ? LIMIT 1');
    $stmt->bind_param('iiiss', $id, $ownerId, $companyId, $role, $fingerprint);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) throw new Exception('Conversation not found.', 404);
    return $row;
}

function aiChatList(mysqli $conn, array $user, int $companyId, string $fingerprint, string $search = ''): array
{
    $ownerId = (int)$user['id'];
    $role = (string)$user['role_key'];
    $pattern = '%' . $search . '%';
    $stmt = $conn->prepare('SELECT id, title, created_at, updated_at FROM ai_conversations WHERE owner_user_id = ? AND company_context_id = ? AND role_key = ? AND permission_fingerprint = ? AND title LIKE ? ORDER BY updated_at DESC, id DESC LIMIT 75');
    $stmt->bind_param('iisss', $ownerId, $companyId, $role, $fingerprint, $pattern);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map(static fn($r) => ['id' => (int)$r['id'], 'title' => $r['title'], 'created_at' => $r['created_at'], 'updated_at' => $r['updated_at']], $rows);
}

function aiChatReadMessages(mysqli $conn, array $user, int $companyId, string $fingerprint, int $id): array
{
    aiChatRequireOwned($conn, $user, $companyId, $fingerprint, $id);
    $stmt = $conn->prepare('SELECT id, sender, content, references_json, live_data_used, created_at FROM ai_conversation_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 150');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $rows = array_reverse($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();
    return array_map(static function ($r) {
        return ['id' => (int)$r['id'], 'role' => $r['sender'], 'content' => $r['content'],
            'references' => json_decode((string)($r['references_json'] ?? '[]'), true) ?: [],
            'live' => (bool)$r['live_data_used'], 'created_at' => $r['created_at']];
    }, $rows);
}

function aiChatModelHistory(mysqli $conn, int $id): array
{
    $stmt = $conn->prepare('SELECT sender, content FROM ai_conversation_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 8');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $rows = array_reverse($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();
    return array_map(static fn($r) => ['role' => $r['sender'], 'content' => $r['content']], $rows);
}

/** Save a successful exchange atomically. No partial or failed AI response is stored. */
function aiChatSaveExchange(mysqli $conn, int $id, string $requestId, string $question, array $reply): void
{
    $references = json_encode($reply['references'] ?? [], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    $answer = (string)$reply['answer'];
    $live = empty($reply['live_data_used']) ? 0 : 1;
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO ai_conversation_messages (conversation_id, sender, content, request_id) VALUES (?, 'user', ?, ?)");
        $stmt->bind_param('iss', $id, $question, $requestId);
        $stmt->execute();
        $stmt->close();
        $stmt = $conn->prepare("INSERT INTO ai_conversation_messages (conversation_id, sender, content, references_json, live_data_used, request_id) VALUES (?, 'assistant', ?, ?, ?, ?)");
        $stmt->bind_param('issis', $id, $answer, $references, $live, $requestId);
        $stmt->execute();
        $stmt->close();
        $stmt = $conn->prepare("UPDATE ai_conversations SET title = IF(title = 'New conversation', LEFT(?, 120), title), updated_at = UTC_TIMESTAMP() WHERE id = ?");
        $title = trim(preg_replace('/\s+/u', ' ', $question));
        $stmt->bind_param('si', $title, $id);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}
