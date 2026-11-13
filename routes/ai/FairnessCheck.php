<?php
/**
 * Fairness check before submit.
 * GET  -> whether the check (and its optional AI wording review) is available.
 * POST -> AI wording review of a draft appraisal. The rule checks run in the browser at no cost.
 * Nothing is saved. The draft is sent without the employee's or supervisor's name.
 */
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/AiStructured.php';
require_once __DIR__ . '/../../includes/AiLanguage.php';

$user = authenticateUser();
$aiLocale = aiUserLocale($user);
$role = (string)($user['role_key'] ?? '');
$canAppraise = $role === 'supervisor' || $role === 'admin';
if (!$canAppraise) throw new Exception('The fairness check is for supervisors and admins who appraise staff.', 403);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $settings = aiReadFeatureFlags($conn);
    echo json_encode(['status' => 'Success', 'data' => [
        'enabled' => aiFeatureEnabled($settings, 'fairness_check'),
        'ai_available' => aiTokenAiAvailable($conn, 'fairness_check'),
    ]]);
    return;
}
if ($method !== 'POST') throw new Exception('Method not allowed.', 405);

aiRequireTokenAi($conn, 'fairness_check');
$input = aiReadJsonBody(80000);
$requestId = aiValidateRequestId($input['request_id'] ?? null);
$items = $input['items'] ?? null;
if (!is_array($items) || !$items || count($items) > 80) throw new Exception('Send between 1 and 80 rated questions.', 422);

$lines = [];
foreach (array_values($items) as $i => $item) {
    if (!is_array($item)) throw new Exception('Invalid question.', 422);
    $ref = aiCleanText($item['ref'] ?? '', 40);
    $rating = $item['rating'] ?? null;
    if (!is_numeric($rating)) throw new Exception('Each question needs a rating.', 422);
    $lines[] = sprintf('[%s] Section: %s | Question: %s | Rating: %s (%s) | Comment: %s',
        $ref !== '' ? $ref : 'q' . ($i + 1),
        aiCleanText($item['section'] ?? '', 80), aiCleanText($item['question'] ?? '', 300),
        (string)(float)$rating, aiCleanText($item['label'] ?? '', 40),
        ($c = aiCleanText($item['comment'] ?? '', 1200)) !== '' ? $c : '(none)');
}
$breach = is_array($input['breach'] ?? null) ? $input['breach'] : [];
$recs = is_array($input['recommendations'] ?? null) ? $input['recommendations'] : [];
$score = is_numeric($input['score'] ?? null) ? round((float)$input['score'], 2) : null;
$context = "Overall score: " . ($score === null ? 'not available' : $score) . " out of 5\n"
    . 'Serious HSE/integrity breach declared: ' . (!empty($breach['declared']) ? 'Yes. Reason: ' . aiCleanText($breach['reason'] ?? '', 600) : 'No') . "\n"
    . 'Status upgrade recommendation: ' . aiCleanText($recs['status_upgrade'] ?? '', 500) . "\n"
    . 'Salary upgrade recommendation: ' . aiCleanText($recs['salary_upgrade'] ?? '', 500) . "\n"
    . 'Development recommendation: ' . aiCleanText($recs['development'] ?? '', 800);

$usageId = aiReserveUsage($conn, $user, $requestId, 'fairness:' . hash('sha256', implode("\n", $lines) . $context), 'fairness_check');
try {
    $instructions = 'You review a DRAFT staff appraisal for consistency before the supervisor submits it. '
        . 'Ratings: 1 Unsatisfactory, 2 Needs Improvement, 3 Fully Meets (a good rating), 4 Exceeds, 5 Outstanding. '
        . 'Flag only clear problems: a comment that contradicts its rating (e.g. critical comment with a 5, praise with a 1 or 2), '
        . 'comments too vague to support a 1, 2 or 5 rating, a declared breach that conflicts with high safety/integrity ratings, '
        . 'and recommendations that do not fit the score. Do not judge the person, do not suggest a different rating number, '
        . 'and never invent facts. If nothing is wrong, return an empty list. Write in plain, polite English for the supervisor.';
    $schema = aiSchemaObject([
        'findings' => ['type' => 'array', 'maxItems' => 6, 'items' => aiSchemaObject([
            'ref' => ['type' => 'string', 'description' => 'The [ref] of the question, or "" for the whole appraisal.'],
            'issue' => ['type' => 'string'],
            'suggestion' => ['type' => 'string'],
        ])],
    ]);
    [$data, $usage] = aiStructuredRequest($instructions, "Draft appraisal:\n" . implode("\n", $lines) . "\n\n" . $context, 'fairness_review', $schema, 700, $aiLocale);
    aiFinishUsage($conn, $usageId, true, $usage);
} catch (Throwable $e) {
    aiFinishUsage($conn, $usageId, false);
    throw $e;
}
$findings = array_values(array_filter(array_map(static fn($f) => [
    'ref' => is_string($f['ref'] ?? null) ? substr($f['ref'], 0, 40) : '',
    'issue' => is_string($f['issue'] ?? null) ? trim($f['issue']) : '',
    'suggestion' => is_string($f['suggestion'] ?? null) ? trim($f['suggestion']) : '',
], $data['findings'] ?? []), static fn($f) => $f['issue'] !== ''));
echo json_encode(['status' => 'Success', 'data' => ['findings' => $findings]], JSON_INVALID_UTF8_SUBSTITUTE);
