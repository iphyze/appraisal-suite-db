<?php

require_once __DIR__ . '/MailDelivery.php';

/**
 * Central email-address eligibility guard.
 *
 * Users without real email addresses must never receive an outbound email.
 * NULL/blank values and old generated placeholder addresses are skipped.
 */
function isDeliverableEmail($email): bool
{
    $email = strtolower(trim((string) $email));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $domain = substr(strrchr($email, '@') ?: '', 1);

    if (
        $domain === ''
        || $domain === 'invalid'
        || substr($domain, -8) === '.invalid'
    ) {
        return false;
    }

    if (
        strpos($email, 'legacy.') === 0
        || strpos($email, '@archive.invalid') !== false
    ) {
        return false;
    }

    return true;
}

/**
 * Simple mail diagnostic logger.
 *
 * Set MAIL_LOG_PATH in .env to a private writable path outside public_html,
 * for example:
 * MAIL_LOG_PATH="/home/your_cpanel_user/logs/lambert_mail.log"
 */
function logMailEvent(string $message, array $context = []): void
{
    $logPath = trim((string) ($_ENV['MAIL_LOG_PATH'] ?? ''));

    $line = sprintf(
        "[%s] %s %s%s",
        date('Y-m-d H:i:s'),
        $message,
        $context ? json_encode($context, JSON_UNESCAPED_SLASHES) : '',
        PHP_EOL
    );

    if ($logPath !== '') {
        $directory = dirname($logPath);

        if (is_dir($directory) && is_writable($directory)) {
            error_log($line, 3, $logPath);
            return;
        }
    }

    error_log(trim($line));
}

/**
 * Plain-text alternative for HTML emails: drops <head>/<style> content and
 * collapses the layout tables into readable lines.
 */
function buildPlainTextBody(string $html): string
{
    $text = preg_replace('#<(head|style|script|title)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
    $text = preg_replace('#<div\b[^>]*display:none[^>]*>.*?</div>#is', '', $text) ?? $text;
    $text = preg_replace('#<a\b[^>]*href=[\'"]([^\'"]+)[\'"][^>]*>(.*?)</a>#is', '$2 ($1)', $text) ?? $text;
    $text = preg_replace('#<(br|/p|/tr|/h1|/h2|/div)\b[^>]*>#i', "\n", $text) ?? $text;
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[\x{2007}\x{FEFF}\x{034F}]+/u', '', $text) ?? $text;
    $text = str_replace("\u{00A0}", ' ', $text);
    $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
    $text = preg_replace("/ *\n */", "\n", $text) ?? $text;
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
    return trim($text);
}

/**
 * Central email sender used by the portal.
 * Sends with the method the Super Admin selected (Microsoft 365 or SMTP), with an
 * optional automatic backup through the other method. See MailDelivery.php.
 *
 * Return values (unchanged):
 * - true: email submitted successfully.
 * - skipped_no_deliverable_email: user has no real deliverable address.
 * - error string: delivery failed.
 */
function sendMail(
    string $to,
    string $subject,
    string $htmlBody,
    ?string $fromName = null,
    array $cc = []
): bool|string {
    $to = trim($to);

    if (!isDeliverableEmail($to)) {
        logMailEvent('Mail skipped: recipient has no deliverable email.', [
            'recipient' => $to === '' ? '[empty]' : $to,
            'subject'   => $subject,
        ]);

        return 'skipped_no_deliverable_email';
    }

    return mailDeliver($to, $subject, $htmlBody, $fromName, $cc);
}
