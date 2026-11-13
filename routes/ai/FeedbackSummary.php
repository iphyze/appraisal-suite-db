<?php
/**
 * Feedback summaries for management.
 * GET  ?cycle_id=  -> how many employees wrote feedback, and the saved summary if it still matches.
 * POST {cycle_id, request_id, refresh} -> summarise with AI (tokens), then save.
 * Employees' names are never sent: each comment is labelled F1, F2... and matched back here.
 */
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/AiStructured.php';
require_once __DIR__ . '/../../includes/AiManagementMetrics.php';
require_once __DIR__ . '/../../includes/AiAnalytics.php';
require_once __DIR__ . '/../../includes/AiResultCache.php';
require_once __DIR__ . '/../../includes/AiLanguage.php';

$user = requireRoles(['admin', 'super_admin']);
$aiLocale = aiUserLocale($user);
aiRequireFeature($conn, 'feedback_summary');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$selectedCompanyId = isset($_GET['view_company_id']) ? (int)$_GET['view_company_id'] : null;
$input = $method === 'POST' ? aiReadJsonBody(2000) : $_GET;
$companyId = aiReadCompanyId($user, $selectedCompanyId);
$cycles = aiAnalyticsCycles($conn, $companyId);
$cycle = aiAnalyticsPickCycle($cycles, aiManagementCycleId($input['cycle_id'] ?? 0));
if (!$cycle) throw new Exception('There are no appraisal cycles yet.', 404);
$cycleId = (int)$cycle['id'];

$where = aiReadWhere($conn, $user, $companyId, 'visible') . " AND ap.cycle_id = {$cycleId}
    AND ap.feedback IS NOT NULL AND TRIM(ap.feedback) <> ''";
$stmt = $conn->prepare("SELECT ap.id, ap.staff_fullname, ap.staff_department, ap.appraisal_summary, ap.feedback, ap.updated_at
    FROM appraisals ap WHERE {$where} ORDER BY ap.updated_at DESC, ap.id DESC LIMIT 120");
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$refs = [];
$lines = [];
foreach ($rows as $i => $r) {
    $ref = 'F' . ($i + 1);
    $refs[$ref] = ['name' => (string)$r['staff_fullname'], 'department' => (string)$r['staff_department'], 'link' => '/appraisals/view/' . (int)$r['id']];
    $score = $r['appraisal_summary'] === null ? 'n/a' : number_format((float)$r['appraisal_summary'], 2);
    $lines[] = "[{$ref}] Department: " . aiCleanText($r['staff_department'], 80) . " | Score: {$score} | Feedback: " . aiCleanText($r['feedback'], 700);
}
$cacheKey = aiCacheKeyForLocale(array_merge(['feedback-v1', $cycleId, array_map(static fn($r) => [$r['id'], $r['updated_at']], $rows)], aiGrantCacheIdentity($user, $companyId)), $aiLocale);
$saved = aiCacheGet($conn, 'feedback_summary', $companyId, $cacheKey);

/** Attach names and links (kept in AppraisalSuite) to the AI's references. */
$withPeople = static function (array $data) use ($refs): array {
    $data['disagreements'] = array_values(array_filter(array_map(static function ($d) use ($refs) {
        $ref = (string)($d['ref'] ?? '');
        return isset($refs[$ref]) ? $refs[$ref] + ['reason' => (string)($d['reason'] ?? '')] : null;
    }, $data['disagreements'] ?? [])));
    return $data;
};

if ($method === 'GET') {
    echo json_encode(['status' => 'Success', 'data' => [
        'cycle' => $cycle, 'cycles' => $cycles, 'feedback_count' => count($rows),
        'summary' => $saved ? $withPeople($saved['data']) : null, 'saved' => (bool)$saved,
        'generated_at' => $saved['created_at'] ?? null, 'ai_available' => aiTokenAiAvailable($conn, 'feedback_summary'),
    ]], JSON_INVALID_UTF8_SUBSTITUTE);
    return;
}
if ($method !== 'POST') throw new Exception('Method not allowed.', 405);
$requestId = aiValidateRequestId($input['request_id'] ?? null);
if ($saved && empty($input['refresh'])) {
    echo json_encode(['status' => 'Success', 'data' => ['summary' => $withPeople($saved['data']), 'saved' => true, 'generated_at' => $saved['created_at']]], JSON_INVALID_UTF8_SUBSTITUTE);
    return;
}
if (count($rows) < 3) throw new Exception('A summary needs feedback from at least three employees.', 422);
aiRequireTokenAi($conn, 'feedback_summary');
$usageId = aiReserveUsage($conn, $user, $requestId, 'feedback:' . $cacheKey . ':' . (string)microtime(true), 'feedback_summary');
try {
    $instructions = 'You summarise what employees wrote when acknowledging their appraisals, for HR and management. '
        . 'Group the comments into themes, count how many comments mention each theme, and estimate overall sentiment. '
        . 'List as disagreements only comments where the employee clearly disputes their rating, the process or the fairness of the appraisal, '
        . 'using the [F#] label and a short neutral reason. Never guess identities and do not quote more than a few words. British English.';
    $schema = aiSchemaObject([
        'overview' => ['type' => 'string'],
        'sentiment' => aiSchemaObject(['positive' => ['type' => 'integer'], 'mixed' => ['type' => 'integer'], 'negative' => ['type' => 'integer']]),
        'themes' => ['type' => 'array', 'maxItems' => 6, 'items' => aiSchemaObject([
            'title' => ['type' => 'string'], 'description' => ['type' => 'string'], 'count' => ['type' => 'integer'],
        ])],
        'disagreements' => ['type' => 'array', 'maxItems' => 15, 'items' => aiSchemaObject([
            'ref' => ['type' => 'string'], 'reason' => ['type' => 'string'],
        ])],
    ]);
    [$data, $usage] = aiStructuredRequest($instructions, count($rows) . " comments:\n" . implode("\n", $lines), 'feedback_summary', $schema, 1100, $aiLocale);
    aiFinishUsage($conn, $usageId, true, $usage);
} catch (Throwable $e) {
    aiFinishUsage($conn, $usageId, false);
    throw $e;
}
aiCachePut($conn, 'feedback_summary', $companyId, $cacheKey, $data, (int)$user['id'], 24 * 30);
echo json_encode(['status' => 'Success', 'data' => ['summary' => $withPeople($data), 'saved' => false, 'generated_at' => gmdate('Y-m-d H:i:s')]], JSON_INVALID_UTF8_SUBSTITUTE);
