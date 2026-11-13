<?php
/**
 * Email delivery: Microsoft 365 (Graph API, app-only) or SMTP, chosen by the Super Admin.
 *
 * - The active method and the automatic backup option live in mail_delivery_settings.
 * - Credentials live only in .env. They are never stored in the database or returned to the browser.
 * - sendMail() in Mailer.php keeps its contract: true on success, otherwise an error string.
 *
 * .env keys for Microsoft 365:
 *   GRAPH_TENANT_ID, GRAPH_CLIENT_ID, GRAPH_CLIENT_SECRET, GRAPH_SENDER, GRAPH_SECRET_EXPIRES (YYYY-MM-DD, optional)
 * Optional tuning:
 *   GRAPH_PER_MINUTE (default 30), GRAPH_DAILY_LIMIT (default 9500 recipients, under Microsoft's 10,000)
 *   MAIL_PROVIDER (smtp|graph) is used only until migration 19 is applied.
 *   GRAPH_LOGIN_BASE / GRAPH_API_BASE: leave unset (testing only; default to Microsoft's endpoints).
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

const MAIL_PROVIDERS = ['graph', 'smtp'];

function mailEnv(string $key, string $default = ''): string
{
    $value = $_ENV[$key] ?? getenv($key);
    return is_string($value) && trim($value) !== '' ? trim($value) : $default;
}

function mailProviderLabel(string $provider): string
{
    return $provider === 'graph' ? 'Microsoft 365' : 'SMTP';
}

/* ------------------------------------------------------------------ */
/*  Configuration (from .env only)                                     */
/* ------------------------------------------------------------------ */

function mailGraphConfig(): array
{
    $config = [
        'tenant' => mailEnv('GRAPH_TENANT_ID'),
        'client' => mailEnv('GRAPH_CLIENT_ID'),
        'secret' => mailEnv('GRAPH_CLIENT_SECRET'),
        'sender' => strtolower(mailEnv('GRAPH_SENDER')),
        'secret_expires' => mailEnv('GRAPH_SECRET_EXPIRES'),
    ];
    $missing = [];
    foreach (['tenant' => 'GRAPH_TENANT_ID', 'client' => 'GRAPH_CLIENT_ID', 'secret' => 'GRAPH_CLIENT_SECRET', 'sender' => 'GRAPH_SENDER'] as $field => $env) {
        if ($config[$field] === '') $missing[] = $env;
    }
    if ($config['sender'] !== '' && !filter_var($config['sender'], FILTER_VALIDATE_EMAIL)) $missing[] = 'GRAPH_SENDER (not a valid email address)';
    $config['missing'] = $missing;
    $config['configured'] = !$missing && function_exists('curl_init');
    if (!function_exists('curl_init')) $config['missing'][] = 'PHP cURL extension';
    return $config;
}

function mailSmtpConfig(): array
{
    $config = [
        'host' => mailEnv('SMTP_HOST'),
        'port' => (int)mailEnv('SMTP_PORT', '465'),
        'user' => mailEnv('SMTP_USER'),
        'pass' => (string)($_ENV['SMTP_PASS'] ?? getenv('SMTP_PASS') ?: ''),
        'from_name' => mailEnv('SMTP_FROM_NAME', 'Lambert Electromec Appraisal System'),
    ];
    $missing = [];
    foreach (['host' => 'SMTP_HOST', 'user' => 'SMTP_USER', 'pass' => 'SMTP_PASS'] as $field => $env) {
        if (trim((string)$config[$field]) === '') $missing[] = $env;
    }
    $config['missing'] = $missing;
    $config['configured'] = !$missing;
    return $config;
}

function mailProviderConfigured(string $provider): bool
{
    return $provider === 'graph' ? mailGraphConfig()['configured'] : mailSmtpConfig()['configured'];
}

/* ------------------------------------------------------------------ */
/*  Settings and events (database)                                     */
/* ------------------------------------------------------------------ */

function mailDbConnection(): ?mysqli
{
    $conn = $GLOBALS['conn'] ?? null;
    return $conn instanceof mysqli ? $conn : null;
}

/** Active method and backup option. Before migration 19: MAIL_PROVIDER from .env, else SMTP. */
function mailDeliverySettings(?mysqli $conn = null): array
{
    $conn = $conn ?? mailDbConnection();
    if ($conn) {
        try {
            $result = $conn->query('SELECT active_provider, fallback_enabled, updated_by, updated_at FROM mail_delivery_settings WHERE id = 1');
            $row = $result ? $result->fetch_assoc() : null;
            if ($result) $result->free();
            if ($row && in_array($row['active_provider'], MAIL_PROVIDERS, true)) {
                return ['provider' => $row['active_provider'], 'fallback' => (int)$row['fallback_enabled'] === 1,
                    'updated_by' => $row['updated_by'] ? (int)$row['updated_by'] : null, 'updated_at' => $row['updated_at'], 'stored' => true];
            }
        } catch (Throwable $e) {
            // Table not created yet (migration 19).
        }
    }
    $envProvider = strtolower(mailEnv('MAIL_PROVIDER', 'smtp'));
    return ['provider' => in_array($envProvider, MAIL_PROVIDERS, true) ? $envProvider : 'smtp',
        'fallback' => mailEnv('MAIL_FALLBACK', 'smtp') !== 'none', 'updated_by' => null, 'updated_at' => null, 'stored' => false];
}

/** Records an attempt (no content or addresses). Never throws. */
function mailRecordEvent(string $provider, bool $sent, int $recipients, ?string $error, bool $isBackup = false, bool $isTest = false): void
{
    $conn = mailDbConnection();
    if (!$conn) return;
    try {
        $outcome = $sent ? 'sent' : 'failed';
        $error = $error === null ? null : mb_substr(mailCleanError($error), 0, 500);
        $backup = $isBackup ? 1 : 0;
        $test = $isTest ? 1 : 0;
        $stmt = $conn->prepare('INSERT INTO mail_delivery_events (provider, outcome, recipients, is_backup, is_test, error, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())');
        if (!$stmt) return;
        $stmt->bind_param('ssiiis', $provider, $outcome, $recipients, $backup, $test, $error);
        $stmt->execute();
        $stmt->close();
        if (random_int(1, 200) === 1) $conn->query('DELETE FROM mail_delivery_events WHERE created_at < UTC_TIMESTAMP() - INTERVAL 90 DAY LIMIT 1000');
    } catch (Throwable $e) {
        // Logging must never stop an email.
    }
}

/** Recipients sent through Microsoft 365 in the last 24 hours (for the daily limit). */
function mailGraphRecipientsLast24h(?mysqli $conn = null): int
{
    $conn = $conn ?? mailDbConnection();
    if (!$conn) return 0;
    try {
        $result = $conn->query("SELECT COALESCE(SUM(recipients), 0) FROM mail_delivery_events WHERE provider = 'graph' AND outcome = 'sent' AND created_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR");
        $value = $result ? (int)$result->fetch_row()[0] : 0;
        if ($result) $result->free();
        return $value;
    } catch (Throwable $e) {
        return 0;
    }
}

/** Removes anything secret-looking from an error before it is stored or shown. */
function mailCleanError(string $error): string
{
    $secret = mailEnv('GRAPH_CLIENT_SECRET');
    if ($secret !== '') $error = str_replace($secret, '[secret]', $error);
    $smtpPass = (string)($_ENV['SMTP_PASS'] ?? '');
    if ($smtpPass !== '') $error = str_replace($smtpPass, '[secret]', $error);
    $error = preg_replace('/Bearer\s+[A-Za-z0-9\-_.~+\/=]+/', 'Bearer [token]', $error) ?? $error;
    return trim(preg_replace('/\s+/', ' ', $error) ?? $error);
}

/* ------------------------------------------------------------------ */
/*  Delivery with the active method and optional backup                */
/* ------------------------------------------------------------------ */

/**
 * Sends one email with the active method. If it fails and the backup is on, retries with the other.
 * @return true|string
 */
function mailDeliver(string $to, string $subject, string $html, ?string $fromName, array $cc)
{
    $settings = mailDeliverySettings();
    $primary = $settings['provider'];
    $backup = $primary === 'graph' ? 'smtp' : 'graph';
    $recipients = 1 + count($cc);

    $result = mailProviderConfigured($primary)
        ? mailSendWith($primary, $to, $subject, $html, $fromName, $cc)
        : mailProviderLabel($primary) . ' is selected but not configured on the server.';
    mailRecordEvent($primary, $result === true, $recipients, $result === true ? null : $result);
    if ($result === true) return true;

    if ($settings['fallback'] && mailProviderConfigured($backup)) {
        logMailEvent('Primary delivery failed; trying the backup method.', ['primary' => $primary, 'backup' => $backup, 'subject' => $subject]);
        $second = mailSendWith($backup, $to, $subject, $html, $fromName, $cc);
        mailRecordEvent($backup, $second === true, $recipients, $second === true ? null : $second, true);
        if ($second === true) return true;
        $label = static fn(string $p, string $e) => str_starts_with($e, mailProviderLabel($p)) ? $e : mailProviderLabel($p) . ': ' . $e;
        return $label($primary, mailCleanError($result)) . ' | Backup ' . $label($backup, mailCleanError($second));
    }
    return mailCleanError($result);
}

/** @return true|string */
function mailSendWith(string $provider, string $to, string $subject, string $html, ?string $fromName, array $cc)
{
    try {
        return $provider === 'graph'
            ? mailGraphSend($to, $subject, $html, $fromName, $cc)
            : mailSmtpSend($to, $subject, $html, $fromName, $cc);
    } catch (Throwable $e) {
        return mailCleanError($e->getMessage());
    }
}

/** Same CC rules as before: valid, deliverable, not the main recipient, no duplicates. */
function mailCleanCc(string $to, array $cc): array
{
    $seen = [];
    foreach ($cc as $address) {
        $address = strtolower(trim((string)$address));
        if ($address === '' || $address === strtolower($to) || isset($seen[$address]) || !isDeliverableEmail($address)) continue;
        $seen[$address] = true;
    }
    return array_keys($seen);
}

/* ------------------------------------------------------------------ */
/*  SMTP (unchanged behaviour)                                         */
/* ------------------------------------------------------------------ */

/** @return true|string */
function mailSmtpSend(string $to, string $subject, string $html, ?string $fromName, array $cc)
{
    $config = mailSmtpConfig();
    if (!$config['configured']) return 'SMTP credentials are not configured in .env';
    $fromName = $fromName ?? $config['from_name'];
    $mail = null;
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $config['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['user'];
        $mail->Password = $config['pass'];
        $mail->Port = $config['port'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->SMTPAutoTLS = false;
        $mail->Timeout = 30;
        $mail->SMTPDebug = 0;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($config['user'], $fromName);
        $mail->addReplyTo($config['user'], $fromName);
        $mail->addAddress($to);
        $ccList = mailCleanCc($to, $cc);
        foreach ($ccList as $address) $mail->addCC($address);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = buildPlainTextBody($html);
        logMailEvent('Attempting email delivery.', ['provider' => 'smtp', 'recipient' => $to, 'subject' => $subject, 'smtp_host' => $config['host'], 'cc_count' => count($ccList)]);
        $mail->send();
        logMailEvent('Email submitted successfully.', ['provider' => 'smtp', 'recipient' => $to, 'subject' => $subject]);
        return true;
    } catch (PHPMailerException $e) {
        $error = $mail ? $mail->ErrorInfo : $e->getMessage();
        logMailEvent('Email delivery failed.', ['provider' => 'smtp', 'recipient' => $to, 'subject' => $subject, 'error' => $error]);
        return $error;
    }
}

/* ------------------------------------------------------------------ */
/*  Microsoft 365 via Microsoft Graph (client credentials)             */
/* ------------------------------------------------------------------ */

function mailGraphCacheFile(array $config): string
{
    $dir = mailEnv('MAIL_TOKEN_CACHE_DIR', sys_get_temp_dir());
    return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'appraisalsuite_graph_' . hash('sha256', $config['tenant'] . '|' . $config['client']) . '.json';
}

/** App-only access token, reused until five minutes before it expires. */
function mailGraphToken(array $config, bool $refresh = false): string
{
    $file = mailGraphCacheFile($config);
    if (!$refresh && is_readable($file)) {
        $cached = json_decode((string)@file_get_contents($file), true);
        if (is_array($cached) && !empty($cached['token']) && (int)($cached['expires_at'] ?? 0) > time() + 300) return (string)$cached['token'];
    }
    $ch = curl_init(rtrim(mailEnv('GRAPH_LOGIN_BASE', 'https://login.microsoftonline.com'), '/') . '/' . rawurlencode($config['tenant']) . '/oauth2/v2.0/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $config['client'], 'client_secret' => $config['secret'],
            'grant_type' => 'client_credentials', 'scope' => 'https://graph.microsoft.com/.default',
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new RuntimeException('Could not reach Microsoft sign-in: ' . $curlError);
    $data = json_decode((string)$body, true);
    if ($status !== 200 || empty($data['access_token'])) {
        $detail = (string)($data['error_description'] ?? $data['error'] ?? "HTTP {$status}");
        $code = preg_match('/AADSTS\d+/', $detail, $m) ? $m[0] . ': ' : '';
        $hint = str_contains($detail, 'AADSTS7000215') ? 'The client secret is wrong. Use the secret Value, not the Secret ID.'
            : (str_contains($detail, 'AADSTS7000222') ? 'The client secret has expired. Create a new one and update .env.'
            : (str_contains($detail, 'AADSTS700016') ? 'The Application (client) ID was not found in this tenant.'
            : (str_contains($detail, 'AADSTS90002') ? 'The Tenant ID is wrong.' : strtok($detail, "\r\n"))));
        throw new RuntimeException('Microsoft sign-in failed. ' . $code . $hint);
    }
    $payload = json_encode(['token' => $data['access_token'], 'expires_at' => time() + (int)($data['expires_in'] ?? 3599)]);
    $tmp = $file . '.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $payload, LOCK_EX) !== false) {
        @chmod($tmp, 0600);
        @rename($tmp, $file);
    }
    return (string)$data['access_token'];
}

/** Keeps sending under Microsoft's per-minute limit across all requests on this server. */
function mailGraphPace(): void
{
    $perMinute = max(1, min(60, (int)mailEnv('GRAPH_PER_MINUTE', '30')));
    $file = rtrim(mailEnv('MAIL_TOKEN_CACHE_DIR', sys_get_temp_dir()), '/\\') . DIRECTORY_SEPARATOR . 'appraisalsuite_graph_pace.json';
    $handle = @fopen($file, 'c+');
    if (!$handle) return;
    try {
        if (!flock($handle, LOCK_EX)) return;
        $stamps = json_decode((string)stream_get_contents($handle), true);
        $now = microtime(true);
        $stamps = array_values(array_filter(is_array($stamps) ? $stamps : [], static fn($t) => is_numeric($t) && $t > $now - 60));
        if (count($stamps) >= $perMinute) {
            $wait = min(60.0, 60 - ($now - min($stamps)) + 0.05);
            if ($wait > 0) {
                if (function_exists('set_time_limit')) @set_time_limit(120);
                usleep((int)($wait * 1000000));
            }
            $now = microtime(true);
            $stamps = array_values(array_filter($stamps, static fn($t) => $t > $now - 60));
        }
        $stamps[] = $now;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($stamps));
        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

/** @return true|string */
function mailGraphSend(string $to, string $subject, string $html, ?string $fromName, array $cc)
{
    $config = mailGraphConfig();
    if (!$config['configured']) return 'Microsoft 365 is not configured on the server (' . implode(', ', $config['missing']) . ').';
    $ccList = mailCleanCc($to, $cc);
    $dailyLimit = max(1, (int)mailEnv('GRAPH_DAILY_LIMIT', '9500'));
    if (mailGraphRecipientsLast24h() + 1 + count($ccList) > $dailyLimit) {
        return "Microsoft 365 daily limit reached ({$dailyLimit} recipients in 24 hours).";
    }

    $address = static fn($email, $name = null) => ['emailAddress' => array_filter(['address' => $email, 'name' => $name], static fn($v) => $v !== null && $v !== '')];
    $message = [
        'subject' => $subject,
        'body' => ['contentType' => 'HTML', 'content' => $html],
        'toRecipients' => [$address($to)],
        'from' => $address($config['sender'], $fromName ?: null),
        'replyTo' => [$address($config['sender'], $fromName ?: null)],
    ];
    if ($ccList) $message['ccRecipients'] = array_map($address, $ccList);
    $payload = json_encode(['message' => $message, 'saveToSentItems' => true], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $url = rtrim(mailEnv('GRAPH_API_BASE', 'https://graph.microsoft.com'), '/') . '/v1.0/users/' . rawurlencode($config['sender']) . '/sendMail';

    logMailEvent('Attempting email delivery.', ['provider' => 'graph', 'recipient' => $to, 'subject' => $subject, 'cc_count' => count($ccList)]);
    $refreshed = false;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        mailGraphPace();
        $token = mailGraphToken($config, $refreshed);
        $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_HEADERFUNCTION => static function ($curl, $line) use (&$headers) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) return 'Could not reach Microsoft Graph: ' . $curlError;
        if ($status === 202) {
            logMailEvent('Email submitted successfully.', ['provider' => 'graph', 'recipient' => $to, 'subject' => $subject]);
            return true;
        }
        if ($status === 401 && !$refreshed) { $refreshed = true; continue; }
        if (in_array($status, [429, 503, 504], true) && $attempt < 3) {
            $wait = max(1, min(30, (int)($headers['retry-after'] ?? 5)));
            if (function_exists('set_time_limit')) @set_time_limit(120);
            sleep($wait);
            continue;
        }
        $error = json_decode((string)$body, true)['error'] ?? [];
        $code = (string)($error['code'] ?? "HTTP {$status}");
        $text = (string)($error['message'] ?? 'Unexpected response from Microsoft Graph.');
        $hint = match (true) {
            $code === 'ErrorAccessDenied' || $status === 403 => ' The app is not allowed to send from ' . $config['sender'] . '. Check the Exchange role assignment and scope (step 7 of the setup guide); changes can take up to 2 hours.',
            $code === 'ErrorInvalidUser' || $code === 'MailboxNotEnabledForRESTAPI' || $status === 404 => ' The sending mailbox ' . $config['sender'] . ' was not found. Check GRAPH_SENDER.',
            $status === 429 => ' Microsoft is limiting the sending rate. Try again in a few minutes.',
            default => '',
        };
        logMailEvent('Email delivery failed.', ['provider' => 'graph', 'recipient' => $to, 'subject' => $subject, 'error' => $code]);
        return mailCleanError("Microsoft 365 error {$code}: {$text}{$hint}");
    }
    return 'Microsoft 365 did not accept the email after several attempts.';
}

/* ------------------------------------------------------------------ */
/*  Status for the Super Admin page                                    */
/* ------------------------------------------------------------------ */

function mailSecretExpiry(string $date): array
{
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
        return ['date' => null, 'days_left' => null, 'state' => $date === '' ? 'unknown' : 'invalid'];
    }
    $days = (int)floor((strtotime($date . ' 23:59:59 UTC') - time()) / 86400);
    return ['date' => $date, 'days_left' => $days, 'state' => $days < 0 ? 'expired' : ($days <= 30 ? 'soon' : 'ok')];
}

/** Last success, last error and 24-hour counts per method. */
function mailProviderStats(mysqli $conn, string $provider): array
{
    $stats = ['last_sent_at' => null, 'last_failed_at' => null, 'last_error' => null, 'sent_24h' => 0, 'failed_24h' => 0];
    try {
        $stmt = $conn->prepare("SELECT
            MAX(CASE WHEN outcome = 'sent' THEN created_at END) AS last_sent_at,
            MAX(CASE WHEN outcome = 'failed' THEN created_at END) AS last_failed_at,
            SUM(outcome = 'sent' AND created_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR) AS sent_24h,
            SUM(outcome = 'failed' AND created_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR) AS failed_24h
            FROM mail_delivery_events WHERE provider = ?");
        $stmt->bind_param('s', $provider);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $stats['last_sent_at'] = $row['last_sent_at'] ?? null;
        $stats['last_failed_at'] = $row['last_failed_at'] ?? null;
        $stats['sent_24h'] = (int)($row['sent_24h'] ?? 0);
        $stats['failed_24h'] = (int)($row['failed_24h'] ?? 0);
        if ($stats['last_failed_at']) {
            $stmt = $conn->prepare("SELECT error FROM mail_delivery_events WHERE provider = ? AND outcome = 'failed' ORDER BY id DESC LIMIT 1");
            $stmt->bind_param('s', $provider);
            $stmt->execute();
            $stats['last_error'] = $stmt->get_result()->fetch_row()[0] ?? null;
            $stmt->close();
        }
    } catch (Throwable $e) {
        // Migration 19 not applied: no stats yet.
    }
    return $stats;
}

/** Pure validation for a settings change. */
function mailValidateSettingsChange(string $role, $provider, $fallback, $reason): array
{
    if ($role !== 'super_admin') throw new Exception('Only the Super Admin can change email delivery.', 403);
    if (!is_string($provider) || !in_array($provider, MAIL_PROVIDERS, true)) throw new Exception('Choose Microsoft 365 or SMTP.', 422);
    if (!is_bool($fallback)) throw new Exception('Automatic backup must be on or off.', 422);
    if (!is_string($reason) || !preg_match('//u', $reason) || strlen(trim($reason)) < 5 || strlen(trim($reason)) > 500) {
        throw new Exception('Enter a reason between 5 and 500 characters.', 422);
    }
    return [$provider, $fallback, trim($reason)];
}
