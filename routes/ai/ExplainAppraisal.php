<?php
/**
 * Explain this appraisal in plain language.
 * GET  ?appraisal_id=  -> the saved explanation for this version, if any (free).
 * POST {appraisal_id, request_id, refresh} -> explain with AI (tokens), then save for this version.
 * Uses the same access rules as the Assistant; names are not sent to OpenAI.
 */
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/AiStructured.php';
require_once __DIR__ . '/../../includes/AiResultCache.php';
require_once __DIR__ . '/../../includes/AiLanguage.php';

$user = authenticateUser();
$aiLocale = aiUserLocale($user);
if (!aiAllowedRole((string)$user['role_key'])) throw new Exception('Access denied.', 403);
aiRequireFeature($conn, 'explain_appraisal');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$input = $method === 'POST' ? aiReadJsonBody(1000) : $_GET;
$id = filter_var($input['appraisal_id'] ?? null, FILTER_VALIDATE_INT);
if (!$id || $id <= 0) throw new Exception('A valid appraisal is required.', 422);
$selectedCompanyId = isset($_GET['view_company_id']) ? (int)$_GET['view_company_id'] : null;
$companyId = aiReadCompanyId($user, $selectedCompanyId);

// Same scopes as aiReadDetails: own, team, and (management) visible appraisals.
$scopes = $user['role_key'] === 'super_admin' ? ['visible'] : ['mine'];
if (aiReadScopeAllowed($user, 'team')) $scopes[] = 'team';
if (aiReadScopeAllowed($user, 'visible')) $scopes[] = 'visible';
$actingContext = aiReadCompanyContext($user, $companyId);
if (empty($actingContext['is_home']) && ($actingContext['access_level'] ?? '') !== 'full') $scopes = ['visible'];
$where = implode(' OR ', array_map(static fn($s) => '(' . aiReadWhere($conn, $user, $companyId, $s) . ')', array_unique($scopes)));
$stmt = $conn->prepare("SELECT ap.id, ap.staff_user_id, ap.appraisal_summary, ap.kpi_rating, ap.evaluation_statement, ap.status,
    ap.scoring_profile_label, ap.updated_at, ac.title AS cycle_title, ac.year AS cycle_year
    FROM appraisals ap INNER JOIN appraisal_cycles ac ON ac.id = ap.cycle_id
    WHERE ap.id = ? AND ({$where}) LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$record = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$record) throw new Exception('Appraisal not found or not accessible.', 404);

$audience = (int)$record['staff_user_id'] === (int)$user['id'] ? 'employee' : 'reviewer';
$cacheKey = aiCacheKeyForLocale(array_merge(['explain-v1', (int)$record['id'], (string)$record['updated_at'], $audience], aiGrantCacheIdentity($user, $companyId)), $aiLocale);
$saved = aiCacheGet($conn, 'explain_appraisal', $companyId, $cacheKey);

if ($method === 'GET') {
    echo json_encode(['status' => 'Success', 'data' => [
        'explanation' => $saved['data'] ?? null, 'saved' => (bool)$saved, 'generated_at' => $saved['created_at'] ?? null,
        'ai_available' => aiTokenAiAvailable($conn, 'explain_appraisal'),
    ]], JSON_INVALID_UTF8_SUBSTITUTE);
    return;
}
if ($method !== 'POST') throw new Exception('Method not allowed.', 405);
$requestId = aiValidateRequestId($input['request_id'] ?? null);
if ($saved && empty($input['refresh'])) {
    echo json_encode(['status' => 'Success', 'data' => ['explanation' => $saved['data'], 'saved' => true, 'generated_at' => $saved['created_at']]], JSON_INVALID_UTF8_SUBSTITUTE);
    return;
}

// Question ratings and the supervisor's comments for this appraisal.
$lines = [];
$stmt = $conn->prepare("SELECT s.section_label, s.section_avg, s.section_weight FROM appraisal_section_scores s WHERE s.appraisal_id = ? ORDER BY s.id LIMIT 15");
$stmt->bind_param('i', $id);
$stmt->execute();
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $s) {
    $lines[] = 'Section ' . aiCleanText($s['section_label'], 80) . ': average ' . ($s['section_avg'] === null ? 'n/a' : number_format((float)$s['section_avg'], 2))
        . ', weight ' . ($s['section_weight'] === null ? 'n/a' : rtrim(rtrim(number_format((float)$s['section_weight'], 2), '0'), '.') . '%');
}
$stmt->close();
foreach (['appraisal_kpi_responses' => 'Objective', 'appraisal_section_responses' => 'Question'] as $table => $kind) {
    $stmt = $conn->prepare("SELECT question_text, rating, rating_comment FROM {$table} WHERE appraisal_id = ? ORDER BY id LIMIT 40");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $q) {
        $comment = aiCleanText($q['rating_comment'] ?? '', 500);
        $lines[] = "{$kind}: " . aiCleanText($q['question_text'], 220) . ' | Rating ' . ($q['rating'] === null ? 'n/a' : (float)$q['rating'])
            . ($comment !== '' ? ' | Comment: ' . $comment : '');
    }
    $stmt->close();
}
$facts = 'Cycle: ' . $record['cycle_title'] . ' ' . $record['cycle_year'] . "\n"
    . 'Final score: ' . ($record['appraisal_summary'] === null ? 'n/a' : number_format((float)$record['appraisal_summary'], 2)) . ' of 5 (' . aiCleanText($record['evaluation_statement'] ?? '', 80) . ")\n"
    . 'Level: ' . aiCleanText($record['scoring_profile_label'] ?? 'not recorded', 60) . "\n" . implode("\n", $lines);

aiRequireTokenAi($conn, 'explain_appraisal');
$usageId = aiReserveUsage($conn, $user, $requestId, 'explain:' . $cacheKey . ':' . (string)microtime(true), 'explain_appraisal');
try {
    $who = $audience === 'employee'
        ? 'Write to the employee as "you", warmly and respectfully.'
        : 'Write for a supervisor or manager reviewing the appraisal; refer to "the employee".';
    $instructions = 'You explain a completed staff appraisal in plain, encouraging language. ' . $who . ' '
        . 'Ratings: 1 Unsatisfactory, 2 Needs Improvement, 3 Fully Meets (a good rating, not an average one), 4 Exceeds, 5 Outstanding. '
        . 'Explain what the final score means and how the sections contributed. Use only the facts given; never invent events, '
        . 'never change or dispute a rating, and never give employment, pay or disciplinary advice. Short sentences. British English.';
    $schema = aiSchemaObject([
        'summary' => ['type' => 'string', 'description' => 'Two or three sentences.'],
        'score_explained' => ['type' => 'string'],
        'strengths' => aiSchemaStrings(4),
        'to_improve' => aiSchemaStrings(4),
        'next_steps' => aiSchemaStrings(3),
    ]);
    [$data, $usage] = aiStructuredRequest($instructions, $facts, 'appraisal_explanation', $schema, 800, $aiLocale);
    aiFinishUsage($conn, $usageId, true, $usage);
} catch (Throwable $e) {
    aiFinishUsage($conn, $usageId, false);
    throw $e;
}
aiCachePut($conn, 'explain_appraisal', $companyId, $cacheKey, $data, (int)$user['id'], 24 * 90);
echo json_encode(['status' => 'Success', 'data' => ['explanation' => $data, 'saved' => false, 'generated_at' => gmdate('Y-m-d H:i:s')]], JSON_INVALID_UTF8_SUBSTITUTE);
