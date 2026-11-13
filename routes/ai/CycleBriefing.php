<?php
/**
 * Cycle briefing for management.
 * GET  ?cycle_id=  -> the figures (free) and the saved briefing if one matches them.
 * POST {cycle_id, request_id, refresh} -> write a briefing with AI (tokens), then save it.
 * Only totals are sent to OpenAI: no employee or supervisor names.
 */
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/AiStructured.php';
require_once __DIR__ . '/../../includes/AiManagementMetrics.php';
require_once __DIR__ . '/../../includes/AiAnalytics.php';
require_once __DIR__ . '/../../includes/AiResultCache.php';
require_once __DIR__ . '/../../includes/AiLanguage.php';

$user = requireRoles(['admin', 'super_admin']);
$aiLocale = aiUserLocale($user);
aiRequireFeature($conn, 'cycle_briefing');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$selectedCompanyId = isset($_GET['view_company_id']) ? (int)$_GET['view_company_id'] : null;
$input = $method === 'POST' ? aiReadJsonBody(2000) : $_GET;
$companyId = aiReadCompanyId($user, $selectedCompanyId);
$cycles = aiAnalyticsCycles($conn, $companyId);
$cycle = aiAnalyticsPickCycle($cycles, aiManagementCycleId($input['cycle_id'] ?? 0));
if (!$cycle) throw new Exception('There are no appraisal cycles yet.', 404);
$cycleId = (int)$cycle['id'];

// ---- Facts (calculated locally, never names) ----
try {
    $summary = aiManagementRead($conn, $user, $selectedCompanyId, $cycleId)['summary'];
} catch (Exception $e) {
    if ($e->getCode() !== 404) throw $e;
    $summary = ['appraisals' => 0]; // The cycle has no appraisals yet.
}
$bands = aiAnalyticsRankings($conn, $user, $companyId, $cycleId, 'band')['distribution'];
$departments = array_values(array_filter(aiAnalyticsRankings($conn, $user, $companyId, $cycleId, 'department')['rows'], static fn($r) => !$r['small_group']));
$calibration = aiAnalyticsCalibration($conn, $user, $companyId, $cycleId);
$alerts = aiAnalyticsAlerts($conn, $user, $companyId, $cycle);
$progress = aiAnalyticsProgress($conn, $user, $companyId, $cycleId);
$facts = [
    'cycle' => $cycle['title'] . ' ' . $cycle['year'],
    'appraisals' => $summary,
    'rating_spread_vs_guideline' => array_map(static fn($b) => ['band' => $b['label'], 'share_percent' => $b['share'],
        'guideline' => $b['guide_low'] . '-' . $b['guide_high'] . '%', 'position' => $b['position']], $bands['bands']),
    'highest_departments' => array_map(static fn($r) => ['department' => $r['label'], 'average' => $r['average'], 'appraisals' => $r['appraisals']], array_slice($departments, 0, 3)),
    'lowest_departments' => array_map(static fn($r) => ['department' => $r['label'], 'average' => $r['average'], 'appraisals' => $r['appraisals']], array_slice(array_reverse($departments), 0, 3)),
    'supervisor_calibration' => ['company_average' => $calibration['company_average'],
        'rating_much_higher' => count(array_filter($calibration['supervisors'], static fn($s) => $s['flag'] === 'high')),
        'rating_much_lower' => count(array_filter($calibration['supervisors'], static fn($s) => $s['flag'] === 'low')),
        'supervisors_compared' => count(array_filter($calibration['supervisors'], static fn($s) => !$s['small_group']))],
    'alerts' => ['big_drops_since_last_year' => count($alerts['drops']), 'outstanding_three_years_running' => count($alerts['sustained']),
        'below_fully_meets' => count($alerts['support'])],
    'progress' => ['assigned' => $progress['assigned'], 'stages' => $progress['stages'],
        'percent_submitted' => $progress['percent_submitted'], 'percent_acknowledged' => $progress['percent_complete']],
];
$cacheKey = aiCacheKeyForLocale(array_merge(['briefing-v1', $facts], aiGrantCacheIdentity($user, $companyId)), $aiLocale);

$saved = aiCacheGet($conn, 'cycle_briefing', $companyId, $cacheKey);
if ($method === 'GET') {
    echo json_encode(['status' => 'Success', 'data' => [
        'cycle' => $cycle, 'cycles' => $cycles, 'facts' => $facts,
        'briefing' => $saved['data'] ?? null, 'saved' => (bool)$saved, 'generated_at' => $saved['created_at'] ?? null,
        'ai_available' => aiTokenAiAvailable($conn, 'cycle_briefing'),
    ]], JSON_INVALID_UTF8_SUBSTITUTE);
    return;
}
if ($method !== 'POST') throw new Exception('Method not allowed.', 405);
$requestId = aiValidateRequestId($input['request_id'] ?? null);
if ($saved && empty($input['refresh'])) {
    echo json_encode(['status' => 'Success', 'data' => ['briefing' => $saved['data'], 'saved' => true, 'generated_at' => $saved['created_at']]], JSON_INVALID_UTF8_SUBSTITUTE);
    return;
}
if ((int)($summary['appraisals'] ?? 0) < 3 && (int)$progress['assigned'] < 3) {
    throw new Exception('A briefing needs at least three appraisals or assignments in this cycle.', 422);
}
aiRequireTokenAi($conn, 'cycle_briefing');
$usageId = aiReserveUsage($conn, $user, $requestId, 'briefing:' . $cacheKey . ':' . (string)microtime(true), 'cycle_briefing');
try {
    $instructions = 'You write a one-page management briefing about a staff appraisal cycle for senior management. '
        . 'Use ONLY the figures provided. "Pending" means awaiting the employee\'s acknowledgement, not unappraised. '
        . 'The rating guideline is a calibration aid, not a forced ranking. Earlier cycles used different scales. '
        . 'Be direct and specific with numbers, no hype, no blame of individuals, no employment decisions. British English.';
    $schema = aiSchemaObject([
        'headline' => ['type' => 'string', 'description' => 'One sentence, the single most important message.'],
        'summary' => ['type' => 'string', 'description' => 'Two or three sentences.'],
        'highlights' => aiSchemaStrings(5),
        'risks' => aiSchemaStrings(4),
        'actions' => aiSchemaStrings(4),
    ]);
    [$data, $usage] = aiStructuredRequest($instructions, json_encode($facts, JSON_INVALID_UTF8_SUBSTITUTE), 'cycle_briefing', $schema, 900, $aiLocale);
    aiFinishUsage($conn, $usageId, true, $usage);
} catch (Throwable $e) {
    aiFinishUsage($conn, $usageId, false);
    throw $e;
}
aiCachePut($conn, 'cycle_briefing', $companyId, $cacheKey, $data, (int)$user['id'], 24 * 30);
echo json_encode(['status' => 'Success', 'data' => ['briefing' => $data, 'saved' => false, 'generated_at' => gmdate('Y-m-d H:i:s')]], JSON_INVALID_UTF8_SUBSTITUTE);
