<?php
require_once __DIR__ . '/../../includes/ApiException.php';
/**
 * Super Admin: email delivery method (Microsoft 365 or SMTP) and automatic backup.
 * GET  /mail/delivery-settings  -> current method, status of both methods, recent changes.
 * PUT  /mail/delivery-settings  -> {provider, fallback_enabled, reason}
 * Credentials are never returned: only whether each method is set up, and which .env keys are missing.
 */
require_once 'includes/authMiddleware.php';
require_once __DIR__ . '/../utils/Mailer.php';

header('Content-Type: application/json');
$user = requireRoles(['super_admin']);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

/** Everything the settings page shows. */
function mailDeliveryOverview(mysqli $conn, int $userId): array
{
    $settings = mailDeliverySettings($conn);
    $graph = mailGraphConfig();
    $smtp = mailSmtpConfig();
    $dailyLimit = max(1, (int)mailEnv('GRAPH_DAILY_LIMIT', '9500'));

    $updatedByName = null;
    if ($settings['updated_by']) {
        $stmt = $conn->prepare('SELECT fullname FROM users WHERE id = ?');
        $stmt->bind_param('i', $settings['updated_by']);
        $stmt->execute();
        $updatedByName = $stmt->get_result()->fetch_row()[0] ?? null;
        $stmt->close();
    }

    $history = [];
    try {
        $result = $conn->query('SELECT l.previous_provider, l.new_provider, l.previous_fallback, l.new_fallback, l.reason, l.created_at, u.fullname
            FROM mail_delivery_change_log l LEFT JOIN users u ON u.id = l.changed_by ORDER BY l.id DESC LIMIT 10');
        while ($result && ($row = $result->fetch_assoc())) {
            $history[] = [
                'from' => $row['previous_provider'], 'to' => $row['new_provider'],
                'backup_from' => (int)$row['previous_fallback'] === 1, 'backup_to' => (int)$row['new_fallback'] === 1,
                'reason' => (string)$row['reason'], 'at' => $row['created_at'], 'by' => (string)($row['fullname'] ?? 'Unknown user'),
            ];
        }
    } catch (Throwable $e) {
        // Migration 19 not applied.
    }

    // Pre-fills the "Send the test to" box with the Super Admin's own address.
    $stmt = $conn->prepare('SELECT email FROM users WHERE id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $ownEmail = (string)($stmt->get_result()->fetch_row()[0] ?? '');
    $stmt->close();

    return [
        'test_default_to' => isDeliverableEmail($ownEmail) ? $ownEmail : '',
        'active_provider' => $settings['provider'],
        'fallback_enabled' => $settings['fallback'],
        'stored' => $settings['stored'],
        'updated_at' => $settings['updated_at'],
        'updated_by' => $updatedByName,
        'providers' => [
            'graph' => [
                'key' => 'graph', 'label' => 'Microsoft 365',
                'configured' => $graph['configured'], 'missing' => $graph['missing'],
                'sender' => $graph['sender'] ?: null,
                'secret' => mailSecretExpiry($graph['secret_expires']),
                'daily' => ['used' => mailGraphRecipientsLast24h($conn), 'limit' => $dailyLimit],
                'per_minute' => max(1, min(60, (int)mailEnv('GRAPH_PER_MINUTE', '30'))),
                'stats' => mailProviderStats($conn, 'graph'),
            ],
            'smtp' => [
                'key' => 'smtp', 'label' => 'SMTP',
                'configured' => $smtp['configured'], 'missing' => $smtp['missing'],
                'sender' => $smtp['user'] ?: null,
                'host' => $smtp['host'] ?: null,
                'stats' => mailProviderStats($conn, 'smtp'),
            ],
        ],
        'history' => $history,
    ];
}

if ($method === 'GET') {
    header('Cache-Control: private, no-store, max-age=0');
    echo json_encode(['status' => 'Success', 'data' => mailDeliveryOverview($conn, (int)$user['id'])], JSON_INVALID_UTF8_SUBSTITUTE);
    return;
}
if ($method !== 'PUT') throw new Exception('Method not allowed.', 405);

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 2048) throw new Exception('Invalid request.', 413);
$input = json_decode($raw, true);
if (!is_array($input)) throw new Exception('Expected a JSON object.', 400);
[$provider, $fallback, $reason] = mailValidateSettingsChange((string)$user['role_key'],
    $input['provider'] ?? null, $input['fallback_enabled'] ?? null, $input['reason'] ?? null);

if (!mailProviderConfigured($provider)) {
    $missing = $provider === 'graph' ? mailGraphConfig()['missing'] : mailSmtpConfig()['missing'];
    throw new ApiException('mail.providerMissingConfig', ['provider' => mailProviderLabel($provider), 'missing' => implode(', ', $missing)], 422, mailProviderLabel($provider) . ' is not set up on the server yet. Missing: ' . implode(', ', $missing) . '.');
}

try {
    $conn->begin_transaction();
    $result = $conn->query('SELECT active_provider, fallback_enabled FROM mail_delivery_settings WHERE id = 1 FOR UPDATE');
    $row = $result ? $result->fetch_assoc() : null;
    if (!$row) throw new Exception('Email delivery settings were not found. Apply migration 19.', 503);
    $previousProvider = (string)$row['active_provider'];
    $previousFallback = (int)$row['fallback_enabled'];
    $newFallback = $fallback ? 1 : 0;
    $actor = (int)$user['id'];
    if ($previousProvider !== $provider || $previousFallback !== $newFallback) {
        $stmt = $conn->prepare('UPDATE mail_delivery_settings SET active_provider = ?, fallback_enabled = ?, updated_by = ?, updated_at = UTC_TIMESTAMP() WHERE id = 1');
        $stmt->bind_param('sii', $provider, $newFallback, $actor);
        $stmt->execute();
        $stmt->close();
        $stmt = $conn->prepare('INSERT INTO mail_delivery_change_log (previous_provider, new_provider, previous_fallback, new_fallback, changed_by, reason, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())');
        $stmt->bind_param('ssiiis', $previousProvider, $provider, $previousFallback, $newFallback, $actor, $reason);
        $stmt->execute();
        $stmt->close();

        // Also shown on the Audit Log page.
        $description = 'Email delivery changed: ' . mailProviderLabel($previousProvider) . ' to ' . mailProviderLabel($provider)
            . '; automatic backup ' . ($newFallback ? 'on' : 'off') . '. Reason: ' . $reason;
        $log = $conn->prepare('INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description) VALUES (?, ?, ?, ?, ?, ?)');
        if ($log) {
            $companyId = (int)($user['company_id'] ?? 0);
            $action = 'email_delivery_changed';
            $table = 'mail_delivery_settings';
            $targetId = 1;
            $log->bind_param('iissis', $companyId, $actor, $action, $table, $targetId, $description);
            $log->execute();
            $log->close();
        }
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    if ($e instanceof Exception && in_array($e->getCode(), [503, 422], true)) throw $e;
    error_log('[AppraisalSuite mail] Settings update failed: ' . get_class($e));
    throw new Exception('Unable to update email delivery.', 503);
}
echo json_encode(['status' => 'Success', 'data' => mailDeliveryOverview($conn, (int)$user['id'])], JSON_INVALID_UTF8_SUBSTITUTE);
