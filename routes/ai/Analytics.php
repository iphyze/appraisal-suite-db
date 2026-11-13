<?php
/**
 * GET /ai/analytics?view=rankings|calibration|alerts|progress&cycle_id=&group_by=
 * Calculated inside AppraisalSuite. No OpenAI request is made and no tokens are used.
 */
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/AiFeatureFlags.php';
require_once __DIR__ . '/../../includes/AiAnalytics.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'GET') throw new Exception('Method not allowed.', 405);
$user = authenticateUser();
$view = (string)($_GET['view'] ?? '');
$features = ['rankings' => 'rankings', 'calibration' => 'calibration', 'alerts' => 'alerts', 'progress' => 'progress'];
if (!isset($features[$view])) throw new Exception('Choose rankings, calibration, alerts or progress.', 422);
aiRequireFeature($conn, $features[$view]);

$selectedCompanyId = isset($_GET['view_company_id']) ? (int)$_GET['view_company_id'] : null;
$companyId = aiReadCompanyId($user, $selectedCompanyId);
$requested = isset($_GET['cycle_id']) && preg_match('/^[0-9]{1,10}$/D', (string)$_GET['cycle_id']) ? (int)$_GET['cycle_id'] : 0;
$cycles = aiAnalyticsCycles($conn, $companyId);
$cycle = aiAnalyticsPickCycle($cycles, $requested);

$data = ['view' => $view, 'cycles' => $cycles, 'cycle' => $cycle, 'uses_tokens' => false];
if ($cycle) {
    $cycleId = (int)$cycle['id'];
    if ($view === 'rankings') {
        $groupBy = (string)($_GET['group_by'] ?? 'department');
        $data['result'] = aiAnalyticsRankings($conn, $user, $companyId, $cycleId, $groupBy);
    } elseif ($view === 'calibration') {
        $data['result'] = aiAnalyticsCalibration($conn, $user, $companyId, $cycleId);
    } elseif ($view === 'alerts') {
        $data['result'] = aiAnalyticsAlerts($conn, $user, $companyId, $cycle);
    } else {
        $department = aiAnalyticsProgressDepartment($_GET['department'] ?? '');
        $data['result'] = aiAnalyticsProgress($conn, $user, $companyId, $cycleId, $department);
    }
}
header('Cache-Control: private, no-store, max-age=0');
echo json_encode(['status' => 'Success', 'data' => $data], JSON_INVALID_UTF8_SUBSTITUTE);
