<?php
/** Public, enumeration-safe password recovery request. */
require_once __DIR__ . '/../../includes/ApiException.php';
require_once __DIR__ . '/../../includes/PasswordRecovery.php';
require_once __DIR__ . '/../../includes/EmailLocale.php';
require_once __DIR__ . '/../utils/EmailTemplates.php';
require_once __DIR__ . '/../utils/Mailer.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new Exception('Method not allowed.', 405);
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) throw new Exception('Invalid request format.', 400);
    $email = userIdentityNormalizeEmail((string)($data['email'] ?? ''));
    if (strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Enter a valid email address.', 400);
    }

    $request = passwordRecoveryIssue($conn, $email, substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 64));
    if ($request !== null) {
        $sent = false;
        try {
            $url = passwordRecoveryLink(passwordRecoveryPublicUrl(), $request['token']);
            $locale = appEmailRecipientLocale($conn, (int)$request['recipient_user_id'], (string)$request['email']);
            $subject = appEmailText($locale, 'passwordRecovery.subject');
            $heading = appEmailText($locale, 'passwordRecovery.heading');
            $intro = appEmailText($locale, 'passwordRecovery.intro');
            $button = appEmailText($locale, 'passwordRecovery.button');
            $note = appEmailText($locale, 'passwordRecovery.note');
            $safe = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html = '<!doctype html><html lang="' . $safe($locale) . '"><body style="margin:0;background:#f5f7f8;padding:32px 16px;font-family:Arial,sans-serif;color:#142036">'
                . '<div style="max-width:540px;margin:auto;background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:28px">'
                . '<h1 style="font-size:23px">' . $safe($heading) . '</h1><p>' . $safe($intro) . '</p>'
                . '<p style="margin:28px 0"><a href="' . $safe($url) . '" style="background:#2d8040;color:#fff;padding:13px 20px;border-radius:9px;text-decoration:none">'
                . $safe($button) . '</a></p><p style="font-size:13px;color:#526173">' . $safe($note) . '</p>'
                . '</div></body></html>';
            $sent = sendMail((string)$request['email'], $subject, $html, 'AppraisalSuite') === true;
            if (!$sent) error_log('[PasswordRecovery] Reset email could not be delivered.');
        } catch (Throwable $error) {
            error_log('[PasswordRecovery] Reset email preparation/delivery failed.');
        }
        // Never leave a usable link in the database when the email failed.
        try {
            passwordRecoveryDeliveryResult($conn, (int)$request['id'], $sent);
        } catch (Throwable $error) {
            // Mail/send bookkeeping must not make account existence observable.
            error_log('[PasswordRecovery] Unable to save delivery outcome.');
        }
    }

    echo json_encode(['status' => 'Success',
        'message' => 'If this email belongs to an eligible account, a password reset link will be sent.',
        'message_key' => 'auth.recoveryRequested', 'message_params' => new stdClass()]);
} catch (Throwable $error) {
    $status = (int)$error->getCode();
    $status = ($status >= 400 && $status <= 599) ? $status : 500;
    http_response_code($status);
    echo json_encode(['status' => 'Failed',
        'message' => $status === 400 ? 'Enter a valid email address.' : 'Unable to process your request.',
        'message_key' => $status === 400 ? 'auth.email_invalid' : 'auth.recoveryFailed',
        'message_params' => new stdClass()]);
}
