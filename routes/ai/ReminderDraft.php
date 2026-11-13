<?php
/**
 * Draft a reminder email from the Progress tracker figures (tokens). Nothing is sent here:
 * the draft opens in Send Email, where an admin reviews it and chooses the recipients.
 * Only counts are sent to OpenAI, never names.
 */
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/AiStructured.php';
require_once __DIR__ . '/../../includes/AiLanguage.php';
require_once __DIR__ . '/../../includes/AiManagementMetrics.php';
require_once __DIR__ . '/../../includes/AiAnalytics.php';

$user = requireRoles(['admin', 'super_admin']);
$aiLocale = aiUserLocale($user);
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') throw new Exception('Method not allowed.', 405);
aiRequireFeature($conn, 'reminder_drafts');
$input = aiReadJsonBody(3000);
$requestId = aiValidateRequestId($input['request_id'] ?? null);
$audiences = [
    'staff_acknowledgement' => ['mode' => 'pending_acknowledgements', 'to' => 'employees whose appraisal is awaiting their acknowledgement'],
    'supervisors_pending' => ['mode' => 'pending_supervisors', 'to' => 'supervisors who still have staff to appraise'],
    'supervisors_setup' => ['mode' => 'pending_supervisors', 'to' => 'supervisors who still need to set staff levels or lock objectives'],
];
$audience = (string)($input['audience'] ?? '');
if (!isset($audiences[$audience])) throw new Exception('Choose who the reminder is for.', 422);
$tones = ['friendly' => 'friendly and encouraging', 'firm' => 'polite but firm', 'urgent' => 'urgent, because the deadline is close'];
$tone = $tones[(string)($input['tone'] ?? 'friendly')] ?? $tones['friendly'];
$deadline = aiCleanText($input['deadline'] ?? '', 40);
if ($deadline !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) throw new Exception('Use a date for the deadline.', 422);
$extra = aiCleanText($input['note'] ?? '', 300);

$selectedCompanyId = isset($_GET['view_company_id']) ? (int)$_GET['view_company_id'] : null;
$companyId = aiReadCompanyId($user, $selectedCompanyId);
$cycle = aiAnalyticsPickCycle(aiAnalyticsCycles($conn, $companyId), aiManagementCycleId($input['cycle_id'] ?? 0));
if (!$cycle) throw new Exception('There are no appraisal cycles yet.', 404);
$progress = aiAnalyticsProgress($conn, $user, $companyId, (int)$cycle['id']);
$s = $progress['stages'];
$counts = [
    'staff_acknowledgement' => $s['awaiting_ack'],
    'supervisors_pending' => count(array_filter($progress['supervisors'], static fn($x) => ($x['ready'] + $x['level_needed'] + $x['objectives_needed']) > 0)),
    'supervisors_setup' => count(array_filter($progress['supervisors'], static fn($x) => ($x['level_needed'] + $x['objectives_needed']) > 0)),
][$audience];
if ($counts === 0) throw new Exception('Nobody needs this reminder at the moment.', 422);

$facts = 'Cycle: ' . $cycle['title'] . ' ' . $cycle['year'] . "\nAudience: " . $audiences[$audience]['to']
    . "\nPeople in this audience: {$counts}\nWhole cycle: {$progress['assigned']} assigned, {$s['level_needed']} need a level, "
    . "{$s['objectives_needed']} need objectives locked, {$s['ready']} ready to appraise, {$s['awaiting_ack']} awaiting acknowledgement, {$s['complete']} complete."
    . ($deadline !== '' ? "\nDeadline: " . date('l j F Y', strtotime($deadline)) : '')
    . ($extra !== '' ? "\nAdmin's note to include: {$extra}" : '');

aiRequireTokenAi($conn, 'reminder_drafts');
$usageId = aiReserveUsage($conn, $user, $requestId, 'reminder:' . hash('sha256', $facts . $tone) . ':' . (string)microtime(true), 'reminder_drafts');
try {
    $instructions = 'You draft a short reminder email body for a company staff appraisal portal. The email system adds the greeting '
        . '("Hello <name>,"), the sign-in details and the footer, so write only the message: 2 short paragraphs, plain text, no greeting, '
        . 'no sign-off, no names, no placeholders in brackets. Tone: ' . $tone . '. Say clearly what to do in the portal. British English.';
    $schema = aiSchemaObject(['subject' => ['type' => 'string'], 'message' => ['type' => 'string']]);
    [$data, $usage] = aiStructuredRequest($instructions, $facts, 'reminder_email', $schema, 400, $aiLocale);
    aiFinishUsage($conn, $usageId, true, $usage);
} catch (Throwable $e) {
    aiFinishUsage($conn, $usageId, false);
    throw $e;
}
echo json_encode(['status' => 'Success', 'data' => [
    'subject' => trim((string)($data['subject'] ?? '')), 'message' => trim((string)($data['message'] ?? '')),
    'email_mode' => $audiences[$audience]['mode'], 'cycle_id' => (int)$cycle['id'], 'recipients' => $counts,
]], JSON_INVALID_UTF8_SUBSTITUTE);
