<?php
require_once __DIR__ . '/../../includes/ApiException.php';
/**
 * Super Admin: send a test email through one specific method (no backup), so a method can be
 * checked before switching to it. Goes to the given address, or to your own if none is given.
 * POST /mail/delivery-settings/test  {provider, to?}
 * Limited to 10 test emails an hour, so the test cannot be used for bulk sending.
 */
require_once 'includes/authMiddleware.php';
require_once __DIR__ . '/../utils/Mailer.php';
require_once __DIR__ . '/../utils/EmailTemplates.php';
require_once __DIR__ . '/../../includes/EmailLocale.php';

header('Content-Type: application/json');
$user = requireRoles(['super_admin']);
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') throw new Exception('Method not allowed.', 405);

$input = json_decode((string)file_get_contents('php://input'), true);
$provider = is_array($input) ? ($input['provider'] ?? null) : null;
if (!is_string($provider) || !in_array($provider, MAIL_PROVIDERS, true)) throw new Exception('Choose Microsoft 365 or SMTP.', 422);
if (!mailProviderConfigured($provider)) throw new ApiException('mail.providerUnconfigured', ['provider' => mailProviderLabel($provider)], 422, mailProviderLabel($provider) . ' is not set up on the server yet.');

$stmt = $conn->prepare('SELECT email, fullname FROM users WHERE id = ?');
$userId = (int)$user['id'];
$stmt->bind_param('i', $userId);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();
$ownEmail = trim((string)($me['email'] ?? ''));
$requested = is_array($input) && isset($input['to']) ? strtolower(trim((string)$input['to'])) : '';
if ($requested !== '') {
    if (strlen($requested) > 254 || !filter_var($requested, FILTER_VALIDATE_EMAIL) || !isDeliverableEmail($requested)) {
        throw new Exception('Enter a valid email address to send the test to.', 422);
    }
    $to = $requested;
} else {
    $to = $ownEmail;
    if (!isDeliverableEmail($to)) throw new Exception('Your account has no deliverable email address. Enter an address to send the test to.', 422);
}

// At most 10 test emails an hour (all methods together).
try {
    $res = $conn->query("SELECT COUNT(*) FROM mail_delivery_events WHERE is_test = 1 AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 HOUR");
    $recent = $res ? (int)($res->fetch_row()[0] ?? 0) : 0;
    if ($recent >= 10) throw new Exception('You have sent 10 test emails in the last hour. Please wait a little before sending another.', 429);
} catch (mysqli_sql_exception $e) {
    // Migration 19 not applied yet: no limit can be counted.
}

$label = mailProviderLabel($provider);
$from = $provider === 'graph' ? mailGraphConfig()['sender'] : mailSmtpConfig()['user'];
$companyName = (string)($user['company_name'] ?? 'Lambert Electromec Ltd');
$locale = appEmailRecipientLocale($conn, $to === strtolower($ownEmail) ? $userId : 0, $to);
$person = $to === strtolower($ownEmail) ? (string)($me['fullname'] ?? 'Super Admin') : appEmailText($locale, 'defaults.there');
$utcTime = gmdate('j M Y, H:i') . ' UTC';
if ($locale === 'fr') {
    $utcTime = gmdate('d/m/Y H:i') . ' UTC';
}
$html = _emailLayout([
    'locale' => $locale,
    'eyebrow' => appEmailText($locale, 'test.eyebrow'),
    'title' => appEmailText($locale, 'test.title', ['provider' => $label]),
    'preheader' => appEmailText($locale, 'test.preheader', ['provider' => $label]),
    'company' => $companyName,
    'year' => date('Y'),
    'content' => _textBlock(_greeting($person, $locale)
        . "<p style='margin:0;'>" . _safe(appEmailText($locale, 'test.text', ['provider' => $label])) . '</p>')
        . _detailRows([
            appEmailText($locale, 'test.method') => $label,
            appEmailText($locale, 'test.from') => $from,
            appEmailText($locale, 'test.requestedBy') => (string)($me['fullname'] ?? 'Super Admin'),
            appEmailText($locale, 'test.sentAt') => $utcTime,
        ]),
]);

$started = microtime(true);
$result = mailSendWith($provider, $to, appEmailText($locale, 'test.subject', ['provider' => $label]), $html, $companyName, []);
mailRecordEvent($provider, $result === true, 1, $result === true ? null : (string)$result, false, true);

echo json_encode(['status' => 'Success', 'data' => [
    'provider' => $provider,
    'sent' => $result === true,
    'to' => $to,
    'message' => $result === true
        ? "Test email sent to {$to} through {$label}." . ($to === strtolower($ownEmail) ? ' Check your inbox.' : ' Ask the recipient to check that inbox, including the spam folder.')
        : mailCleanError((string)$result),
    'message_key' => $result === true ? ($to === strtolower($ownEmail) ? 'mail.testSentSelf' : 'mail.testSentOther') : null,
    'message_params' => $result === true ? ['address' => $to, 'provider' => $label] : new stdClass(),
    'seconds' => round(microtime(true) - $started, 1),
]], JSON_INVALID_UTF8_SUBSTITUTE);
