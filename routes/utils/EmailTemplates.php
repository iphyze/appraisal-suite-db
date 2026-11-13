<?php

/**
 * AppraisalSuite email templates.
 *
 * Design: navy and green AppraisalSuite palette, Montserrat (Sora as the
 * secondary choice) with safe system fallbacks, table-based layout with inline
 * styles so it renders consistently in Gmail, Outlook and Apple Mail.
 *
 * Staff notifications deliberately exclude appraisal scores; staff must sign in
 * to view the secured report.
 *
 * Branding: the header carries the company's own name (Lambert Electromec Ltd,
 * or "Lem Maintenance Appraisal" for LEM). AppraisalSuite appears only as a
 * subtle credit in the footer. Only headings and labels use bold weights;
 * message text is set in regular weight.
 */

require_once __DIR__ . '/../../includes/Locale.php';

/** Email-only server catalogue; never inherit the acting user's request locale. */
function appEmailText(string $locale, string $key, array $params = []): string
{
    static $catalogues = [];
    $locale = appSupportedLocale($locale) ?? 'en';
    foreach (['en', $locale] as $code) {
        if (!isset($catalogues[$code])) $catalogues[$code] = require __DIR__ . '/../../includes/lang/' . $code . '.php';
    }
    $text = $catalogues[$locale][$key] ?? $catalogues['en'][$key] ?? $key;
    $replace = [];
    foreach ($params as $name => $value) $replace['{' . $name . '}'] = (string)$value;
    return strtr($text, $replace);
}

function appEmailSubject(string $kind, string $locale, array $params = []): string
{
    return appEmailText($locale, 'subject.' . $kind, $params);
}

function appEmailTemplateLocale(array $vars): string
{
    return appSupportedLocale($vars['locale'] ?? null) ?? 'en';
}

/* ------------------------------------------------------------------ */
/*  Brand tokens                                                       */
/* ------------------------------------------------------------------ */

function _brand(): array
{
    return [
        'navy'      => '#0E1E33',
        'ink'       => '#1F2D3A',
        'body'      => '#46555F',
        'muted'     => '#6B7A72',
        'green'     => '#0F5132',
        'greenMid'  => '#1E7A43',
        'greenLite' => '#34B36A',
        'mint'      => '#EEF6F0',
        'mintLine'  => '#D5E8DB',
        'amber'     => '#F4A62A',
        'amberSoft' => '#FFF6E6',
        'amberLine' => '#F3DDB4',
        'paper'     => '#F3F6F2',
        'card'      => '#FFFFFF',
        'line'      => '#E3E9E4',
    ];
}

function _font(): string
{
    // Unquoted on purpose: the value is placed inside single-quoted style attributes.
    return 'Montserrat,Sora,Segoe UI,Helvetica,Arial,sans-serif';
}

/** Kept for backward compatibility with callers that pass a company colour. */
function _portalGreen(): string
{
    return _brand()['green'];
}

function _safe($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* ------------------------------------------------------------------ */
/*  Building blocks                                                    */
/* ------------------------------------------------------------------ */

/** Display name used in the email header for each company. */
function _headerBrand(string $companyName): string
{
    $name = trim($companyName);
    $key = strtoupper(preg_replace('/[^A-Za-z]/', '', $name));
    if ($key === 'LEM' || str_starts_with($key, 'LEMMAINTENANCE')) return 'Lem Maintenance Appraisal';
    if ($key === 'LAMBERTCIV') return $name; // Distinct Côte d'Ivoire company branding.
    if (str_contains($key, 'LAMBERT')) return 'Lambert Electromec Ltd';
    return $name !== '' ? $name : 'Staff Appraisal';
}

/** Subtle "Powered by AppraisalSuite" credit for the footer. */
function _poweredBy(string $locale = 'en'): string
{
    $f = _font();
    $credit = _safe(appEmailText($locale, 'layout.poweredBy'));
    return "<span style='font-family:{$f};font-size:11px;font-weight:400;color:#A3ADA8;'>{$credit}</span>"
        . "<span style='font-family:{$f};font-size:11px;font-weight:500;color:#9AA6A0;'>Appraisal</span>"
        . "<span style='font-family:{$f};font-size:11px;font-weight:500;color:#8FBF9F;'>Suite</span>";
}

/**
 * Full email shell.
 *
 * @param array $o eyebrow, title, preheader, company, year, content (HTML rows)
 */
function _emailLayout(array $o): string
{
    $b = _brand();
    $f = _font();
    $brand = _safe(_headerBrand((string)($o['company'] ?? '')));
    $year = _safe($o['year'] ?? date('Y'));
    $eyebrow = _safe($o['eyebrow'] ?? '');
    $title = _safe($o['title'] ?? '');
    $preheader = _safe($o['preheader'] ?? '');
    $content = $o['content'] ?? '';
    $locale = appEmailTemplateLocale($o);
    $powered = _poweredBy($locale);
    $yearLabel = _safe(appEmailText($locale, 'layout.year', ['year' => $year]));
    $footer = _safe(appEmailText($locale, 'layout.footer'));

    return "<!DOCTYPE html>
<html lang='{$locale}' xmlns='http://www.w3.org/1999/xhtml'>
<head>
<meta charset='UTF-8'>
<meta name='viewport' content='width=device-width, initial-scale=1.0'>
<meta name='x-apple-disable-message-reformatting'>
<meta name='color-scheme' content='light'>
<meta name='supported-color-schemes' content='light'>
<title>{$title}</title>
<link href='https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=Sora:wght@600;700&display=swap' rel='stylesheet'>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=Sora:wght@600;700&display=swap');
  body { margin:0 !important; padding:0 !important; width:100% !important; }
  a { text-decoration:none; }
  @media only screen and (max-width:620px) {
    .as-container { width:100% !important; }
    .as-pad { padding-left:24px !important; padding-right:24px !important; }
    .as-title { font-size:22px !important; line-height:30px !important; }
    .as-hide-sm { display:none !important; }
    .as-btn a { display:block !important; }
  }
</style>
</head>
<body style='margin:0;padding:0;background:{$b['paper']};-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;'>
<div style='display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:{$b['paper']};opacity:0;'>{$preheader}&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;</div>
<table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0' style='background:{$b['paper']};'>
<tr><td align='center' style='padding:40px 12px;'>

  <table role='presentation' class='as-container' width='600' cellpadding='0' cellspacing='0' border='0' style='width:600px;max-width:600px;background:{$b['card']};border:1px solid {$b['line']};border-radius:18px;overflow:hidden;'>

    <!-- Header -->
    <tr><td class='as-pad' style='background:{$b['navy']};padding:28px 40px 26px;'>
      <table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0'>
        <tr>
          <td valign='middle' style='font-family:{$f};font-size:19px;font-weight:700;letter-spacing:-0.2px;color:#FFFFFF;'>{$brand}</td>
          <td valign='middle' align='right' class='as-hide-sm' style='font-family:{$f};font-size:11px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;color:#B8C6D3;'>{$yearLabel}</td>
        </tr>
      </table>
    </td></tr>

    <!-- Accent rule -->
    <tr><td style='padding:0;line-height:0;font-size:0;'>
      <table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0'><tr>
        <td width='78%' height='4' style='background:{$b['greenLite']};line-height:4px;font-size:0;'>&nbsp;</td>
        <td width='22%' height='4' style='background:{$b['amber']};line-height:4px;font-size:0;'>&nbsp;</td>
      </tr></table>
    </td></tr>

    <!-- Title -->
    <tr><td class='as-pad' style='padding:38px 40px 6px;font-family:{$f};'>
      <p style='margin:0 0 12px;font-family:{$f};font-size:11px;font-weight:700;letter-spacing:1.8px;text-transform:uppercase;color:{$b['greenMid']};'>{$eyebrow}</p>
      <h1 class='as-title' style='margin:0;font-family:{$f};font-size:25px;line-height:33px;font-weight:700;letter-spacing:-0.3px;color:{$b['navy']};'>{$title}</h1>
    </td></tr>

    {$content}

    <!-- Footer -->
    <tr><td class='as-pad' style='padding:26px 40px 28px;border-top:1px solid {$b['line']};background:#FAFBF9;font-family:{$f};'>
      <p style='margin:0 0 6px;font-family:{$f};font-size:12px;font-weight:700;color:{$b['ink']};'>{$brand}</p>
      <p style='margin:0;font-family:{$f};font-size:12px;font-weight:400;line-height:19px;color:{$b['muted']};'>{$footer}</p>
      <p style='margin:16px 0 0;font-family:{$f};line-height:16px;'>{$powered}</p>
    </td></tr>

  </table>

</td></tr>
</table>
</body>
</html>";
}

/** Body copy block. $html must already be escaped. */
function _textBlock(string $html, string $padding = '18px 40px 6px'): string
{
    $b = _brand();
    $f = _font();
    return "<tr><td class='as-pad' style='padding:{$padding};font-family:{$f};font-size:15px;font-weight:400;line-height:25px;color:{$b['body']};'>{$html}</td></tr>";
}

function _greeting(string $name, string $locale = 'en'): string
{
    $b = _brand();
    $f = _font();
    $greeting = _safe(appEmailText($locale, 'greeting', ['name' => $name]));
    return "<p style='margin:0 0 12px;font-family:{$f};font-size:15px;font-weight:400;color:{$b['ink']};'>{$greeting}</p>";
}

/** Two-column summary list. $rows = [label => value]; values are escaped here. */
function _detailRows(array $rows): string
{
    $b = _brand();
    $f = _font();
    $html = '';
    $i = 0;
    foreach ($rows as $label => $value) {
        $border = $i > 0 ? "border-top:1px solid {$b['line']};" : '';
        $html .= "<tr>
          <td style='{$border}padding:13px 18px;font-family:{$f};font-size:13px;font-weight:400;color:{$b['muted']};width:42%;'>" . _safe($label) . "</td>
          <td style='{$border}padding:13px 18px;font-family:{$f};font-size:14px;font-weight:400;color:{$b['navy']};'>" . _safe($value) . "</td>
        </tr>";
        $i++;
    }
    return "<tr><td class='as-pad' style='padding:22px 40px 4px;'>
      <table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0' style='border:1px solid {$b['line']};border-radius:12px;background:#FCFDFC;'>{$html}</table>
    </td></tr>";
}

/** Tinted panel. $bodyHtml must already be escaped. $tone: 'mint' | 'amber'. */
function _panel(string $label, string $bodyHtml, string $tone = 'mint'): string
{
    $b = _brand();
    $f = _font();
    $bg = $tone === 'amber' ? $b['amberSoft'] : $b['mint'];
    $line = $tone === 'amber' ? $b['amberLine'] : $b['mintLine'];
    $accent = $tone === 'amber' ? $b['amber'] : $b['greenLite'];
    $labelColor = $tone === 'amber' ? '#8A5A00' : $b['green'];
    $labelHtml = $label !== ''
        ? "<p style='margin:0 0 8px;font-family:{$f};font-size:11px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:{$labelColor};'>" . _safe($label) . "</p>"
        : '';
    return "<tr><td class='as-pad' style='padding:22px 40px 4px;'>
      <table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0' style='background:{$bg};border:1px solid {$line};border-left:4px solid {$accent};border-radius:12px;'>
        <tr><td style='padding:18px 20px;font-family:{$f};font-size:14px;font-weight:400;line-height:23px;color:{$b['ink']};'>{$labelHtml}{$bodyHtml}</td></tr>
      </table>
    </td></tr>";
}

/** Primary call-to-action. Kept compatible with the previous signature. */
function _ctaButton(string $url, string $label, string $companyColor = '', string $note = ''): string
{
    $b = _brand();
    $f = _font();
    $safeUrl = _safe($url);
    $safeLabel = _safe($label);
    $noteHtml = $note !== ''
        ? "<p style='margin:14px 0 0;font-family:{$f};font-size:12px;font-weight:400;line-height:19px;color:{$b['muted']};'>" . _safe($note) . "</p>"
        : '';
    return "<tr><td class='as-pad' style='padding:30px 40px 38px;'>
      <table role='presentation' cellpadding='0' cellspacing='0' border='0' class='as-btn'><tr>
        <td align='center' bgcolor='{$b['green']}' style='border-radius:10px;background:{$b['green']};'>
          <a href='{$safeUrl}' target='_blank' style='display:inline-block;padding:15px 30px;font-family:{$f};font-size:14px;font-weight:600;letter-spacing:0.2px;color:#FFFFFF;text-decoration:none;border-radius:10px;'>{$safeLabel}&nbsp;&nbsp;&rarr;</a>
        </td>
      </tr></table>
      {$noteHtml}
    </td></tr>";
}

function _portalAccessBlock(string $accountEmail, string $appUrl, string $companyColor = '', string $cycleYear = '', string $locale = 'en'): string
{
    $b = _brand();
    $email = _safe($accountEmail ?: appEmailText($locale, 'portal.emailFallback'));
    $url = _safe($appUrl);
    $password = _safe('Lambert@' . date('Y')); // Retain existing onboarding credential behaviour.
    $em = "style='color:{$b['navy']};font-weight:400;'";

    // Preserve the original inline email/password emphasis and avoid escaping HTML twice.
    $body = "<p style='margin:0 0 8px;'>" . _safe(appEmailText($locale, 'portal.signInPrefix'))
        . "<span {$em}>{$email}</span>" . _safe(appEmailText($locale, 'portal.signInSuffix')) . '</p>'
        . "<p style='margin:0 0 8px;'>" . _safe(appEmailText($locale, 'portal.firstTimePrefix'))
        . "<span {$em}>{$password}</span>" . _safe(appEmailText($locale, 'portal.firstTimeSuffix')) . '</p>'
        . "<p style='margin:0 0 10px;'>" . _safe(appEmailText($locale, 'portal.changed')) . '</p>'
        . "<a href='{$url}' style='color:{$b['greenMid']};font-weight:400;text-decoration:underline;'>{$url}</a>";

    return _panel(appEmailText($locale, 'portal.title'), $body);
}

function _excerpt(string $text, int $length): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if (function_exists('mb_strlen') && mb_strlen($text) > $length) return rtrim(mb_substr($text, 0, $length - 1)) . '…';
    if (!function_exists('mb_strlen') && strlen($text) > $length) return rtrim(substr($text, 0, $length - 1)) . '…';
    return $text;
}

/* ------------------------------------------------------------------ */
/*  Templates                                                          */
/* ------------------------------------------------------------------ */

function getAppraisalSubmittedEmail(array $vars): string
{
    $locale = appEmailTemplateLocale($vars);
    $staffName = (string)($vars['staff_name'] ?? appEmailText($locale, 'defaults.teamMember'));
    $supervisorName = (string)($vars['supervisor_name'] ?? appEmailText($locale, 'defaults.supervisor'));
    $year = (string)($vars['cycle_year'] ?? date('Y'));
    $company = (string)($vars['company_name'] ?? appEmailText($locale, 'defaults.organisation'));
    $appUrl = (string)($vars['app_url'] ?? '#');
    $args = ['supervisor' => $supervisorName, 'year' => $year];
    $content = _textBlock(_greeting($staffName, $locale)
        . "<p style='margin:0;'>" . _safe(appEmailText($locale, 'submitted.text', $args)) . '</p>');
    $content .= _detailRows([
        appEmailText($locale, 'details.cycle') => appEmailText($locale, 'details.cycleValue', ['year' => $year]),
        appEmailText($locale, 'details.appraisedBy') => $supervisorName,
        appEmailText($locale, 'details.nextStep') => appEmailText($locale, 'submitted.nextStep'),
    ]);
    $content .= _portalAccessBlock((string)($vars['staff_email'] ?? ''), $appUrl, '', $year, $locale);
    $content .= _ctaButton($appUrl, appEmailText($locale, 'submitted.button'), '', appEmailText($locale, 'submitted.note'));
    return _emailLayout([
        'locale' => $locale, 'eyebrow' => appEmailText($locale, 'submitted.eyebrow'),
        'title' => appEmailText($locale, 'submitted.title', $args),
        'preheader' => appEmailText($locale, 'submitted.preheader', $args),
        'company' => $company, 'year' => $year, 'content' => $content,
    ]);
}

function getAppraisalUpdatedEmail(array $vars): string
{
    $locale = appEmailTemplateLocale($vars);
    $staff = (string)($vars['staff_name'] ?? appEmailText($locale, 'defaults.teamMember'));
    $supervisor = (string)($vars['supervisor_name'] ?? appEmailText($locale, 'defaults.supervisor'));
    $year = (string)($vars['cycle_year'] ?? date('Y'));
    $company = (string)($vars['company_name'] ?? appEmailText($locale, 'defaults.organisation'));
    $url = (string)($vars['app_url'] ?? '#');
    $updateNo = max(1, (int)($vars['update_number'] ?? 1));
    $isFinal = $updateNo >= 2;
    $args = ['supervisor' => $supervisor, 'year' => $year];
    $content = _textBlock(_greeting($staff, $locale)
        . "<p style='margin:0;'>" . _safe(appEmailText($locale, 'updated.text', $args)) . '</p>');
    $content .= _detailRows([
        appEmailText($locale, 'details.cycle') => appEmailText($locale, 'details.cycleValue', ['year' => $year]),
        appEmailText($locale, 'details.updatedBy') => $supervisor,
        appEmailText($locale, 'details.update') => appEmailText($locale, $isFinal ? 'updated.progressFinal' : 'updated.progress', ['number' => $updateNo]),
        appEmailText($locale, 'details.nextStep') => appEmailText($locale, 'updated.nextStep'),
    ]);
    if ($isFinal) {
        $content .= _panel(appEmailText($locale, 'updated.finalTitle'),
            "<p style='margin:0;'>" . _safe(appEmailText($locale, 'updated.finalText')) . '</p>', 'amber');
    }
    $content .= _portalAccessBlock((string)($vars['staff_email'] ?? ''), $url, '', $year, $locale)
        . _ctaButton($url, appEmailText($locale, 'updated.button'), '', appEmailText($locale, 'updated.note'));
    return _emailLayout([
        'locale' => $locale, 'eyebrow' => appEmailText($locale, 'updated.eyebrow'),
        'title' => appEmailText($locale, 'updated.title', $args),
        'preheader' => appEmailText($locale, 'updated.preheader', $args),
        'company' => $company, 'year' => $year, 'content' => $content,
    ]);
}

function getFeedbackSubmittedEmail(array $vars): string
{
    $locale = appEmailTemplateLocale($vars);
    $supervisor = (string)($vars['supervisor_name'] ?? appEmailText($locale, 'defaults.supervisor'));
    $staff = (string)($vars['staff_name'] ?? appEmailText($locale, 'defaults.teamMember'));
    $feedback = trim((string)($vars['feedback'] ?? ''));
    $year = (string)($vars['cycle_year'] ?? date('Y'));
    $company = (string)($vars['company_name'] ?? appEmailText($locale, 'defaults.organisation'));
    $url = (string)($vars['app_url'] ?? '#');
    $args = ['staff' => $staff, 'year' => $year];
    $content = _textBlock(_greeting($supervisor, $locale)
        . "<p style='margin:0;'>" . _safe(appEmailText($locale, 'feedback.text', $args)) . '</p>');
    if ($feedback !== '') {
        // A person's own words MUST remain unchanged (only HTML escaped).
        $content .= _panel(appEmailText($locale, 'feedback.label', ['staff' => $staff]),
            "<p style='margin:0;font-style:italic;'>&ldquo;" . nl2br(_safe($feedback)) . '&rdquo;</p>');
    }
    $content .= _detailRows([
        appEmailText($locale, 'details.employee') => $staff,
        appEmailText($locale, 'details.cycle') => appEmailText($locale, 'details.cycleValue', ['year' => $year]),
        appEmailText($locale, 'details.status') => appEmailText($locale, 'feedback.acknowledged'),
    ]) . _ctaButton($url, appEmailText($locale, 'feedback.button'));
    return _emailLayout([
        'locale' => $locale, 'eyebrow' => appEmailText($locale, 'feedback.eyebrow'),
        'title' => appEmailText($locale, 'feedback.title', $args),
        'preheader' => appEmailText($locale, 'feedback.preheader', $args),
        'company' => $company, 'year' => $year, 'content' => $content,
    ]);
}

function getManualNotificationEmail(array $vars): string
{
    $locale = appEmailTemplateLocale($vars);
    $recipient = (string)($vars['recipient_name'] ?? appEmailText($locale, 'defaults.teamMember'));
    $message = trim((string)($vars['custom_message'] ?? ''));
    $year = (string)($vars['cycle_year'] ?? date('Y'));
    $company = (string)($vars['company_name'] ?? appEmailText($locale, 'defaults.organisation'));
    $url = (string)($vars['app_url'] ?? '#');
    $role = (string)($vars['recipient_type'] ?? 'staff');
    $sender = trim((string)($vars['sender_name'] ?? ''));
    $args = ['year' => $year, 'company' => $company];
    $content = _textBlock(_greeting($recipient, $locale)
        . "<p style='margin:0;'>" . _safe(appEmailText($locale, 'manual.text', $args)) . '</p>');
    if ($message !== '') {
        $label = $sender !== ''
            ? appEmailText($locale, 'manual.messageFrom', ['sender' => $sender])
            : appEmailText($locale, 'manual.message');
        // Author's custom message is never translated or modified.
        $content .= _panel($label, "<p style='margin:0;'>" . nl2br(_safe($message)) . '</p>');
    }
    if (!empty($vars['is_portal_user']) || in_array($role, ['staff', 'supervisor'], true)) {
        $content .= _portalAccessBlock((string)($vars['recipient_email'] ?? ''), $url, '', $year, $locale);
    }
    $content .= _ctaButton($url, appEmailText($locale, 'manual.button'));
    return _emailLayout([
        'locale' => $locale, 'eyebrow' => appEmailText($locale, 'manual.eyebrow'),
        'title' => appEmailText($locale, 'manual.title', $args),
        // Custom preheader is the author's own text, not a translation target.
        'preheader' => $message !== '' ? _excerpt($message, 110) : appEmailText($locale, 'manual.preheader', $args),
        'company' => $company, 'year' => $year, 'content' => $content,
    ]);
}

/**
 * Localize the known machine-generated cross-company events only.
 * Unknown, potentially human-authored messages remain exactly as written.
 */
function appEmailGrantEvent(string $title, string $message, string $locale, array $params = []): array
{
    $known = [
        'New appraisal submitted' => ['grant.submittedTitle', 'grant.submittedMessage'],
        'Appraisal updated' => ['grant.updatedTitle', 'grant.updatedMessage'],
        'Appraisal feedback submitted' => ['grant.feedbackTitle', 'grant.feedbackMessage'],
        'Management follow-up response received' => ['grant.followUpTitle', 'grant.followUpMessage'],
    ];
    if (!isset($known[$title]) || !isset($params['name'])) return [$title, $message];
    [$titleKey, $messageKey] = $known[$title];
    if ($locale === 'en') return [$title, $message]; // Preserve English wording verbatim.
    return [appEmailText($locale, $titleKey), appEmailText($locale, $messageKey, $params)];
}

function getGrantedCompanyEventEmail(array $vars): string
{
    $locale = appEmailTemplateLocale($vars);
    $company = (string)($vars['company_name'] ?? appEmailText($locale, 'defaults.organisation'));
    $title = (string)($vars['title'] ?? appEmailText($locale, 'grant.defaultTitle'));
    $message = (string)($vars['message'] ?? '');
    $content = _textBlock(_greeting((string)($vars['recipient_name'] ?? appEmailText($locale, 'defaults.administrator')), $locale)
        . "<p style='margin:0;'>" . _safe(appEmailText($locale, 'grant.text', ['company' => $company])) . '</p>');
    $content .= _panel($title, "<p style='margin:0;'>" . nl2br(_safe($message)) . '</p>');
    // Only an authenticated notifications path; never embed appraisal IDs here.
    $base = rtrim((string)($vars['app_url'] ?? ''), '/');
    $content .= _ctaButton($base . '/notifications', appEmailText($locale, 'grant.button'));
    return _emailLayout([
        'locale' => $locale, 'eyebrow' => appEmailText($locale, 'grant.eyebrow'),
        'title' => $title, 'preheader' => $message,
        'company' => $company, 'year' => date('Y'), 'content' => $content,
    ]);
}
