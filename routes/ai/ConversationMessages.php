<?php
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/AiFeatureFlags.php';
require_once __DIR__ . '/../../includes/AiChatHistory.php';
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') throw new Exception('Method not allowed.', 405);
$user = authenticateUser();
if (!aiAllowedRole((string)$user['role_key'])) throw new Exception('AI history access denied.', 403);
aiRequireFeature($conn, 'assistant');
$selected = isset($_GET['view_company_id']) ? (int)$_GET['view_company_id'] : null;
[$companyId, $fingerprint] = aiChatScope($conn, $user, $selected);
$id = aiChatValidateId($_GET['conversation_id'] ?? null);
$output = aiChatReadMessages($conn, $user, $companyId, $fingerprint, $id);
echo json_encode(['status' => 'Success', 'data' => $output], JSON_INVALID_UTF8_SUBSTITUTE);
