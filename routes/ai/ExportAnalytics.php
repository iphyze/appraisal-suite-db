<?php
/**
 * GET /ai/analytics/excel?cycle_id=
 * Read-only Excel snapshot of the analytics available to the authenticated user.
 * Never invokes OpenAI, never bypasses a feature switch, and uses the exact
 * scoping and small-cohort suppression rules from /ai/analytics.
 */
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/AiFeatureFlags.php';
require_once __DIR__ . '/../../includes/AiAnalytics.php';
require_once __DIR__ . '/../../includes/CompanyExportAccess.php';
require_once __DIR__ . '/../../includes/ExportLocalization.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'GET') {
    throw new Exception('Method not allowed.', 405);
}
$user = authenticateUser();
$locale = exportLocale($user);
$role = (string)($user['role_key'] ?? '');
if (!in_array($role, ['supervisor', 'admin', 'super_admin'], true)) {
    throw new Exception('You cannot export Analytics.', 403);
}
$requested = (string)($_GET['cycle_id'] ?? '0');
if (!preg_match('/^[0-9]{1,10}$/D', $requested)) throw new Exception('Invalid appraisal cycle.', 422);
$companyId = aiReadCompanyId($user, isset($_GET['view_company_id']) ? (int)$_GET['view_company_id'] : null);
$cycles = aiAnalyticsCycles($conn, $companyId);
$cycle = aiAnalyticsPickCycle($cycles, (int)$requested);
if (!$cycle) throw new Exception('There are no appraisal cycles to export.', 422);
$cycleId = (int)$cycle['id'];
$companyStatement = $conn->prepare('SELECT name FROM companies WHERE id = ? LIMIT 1');
$companyStatement->bind_param('i', $companyId);
$companyStatement->execute();
$companyName = (string)($companyStatement->get_result()->fetch_assoc()['name'] ?? 'Company');
$companyStatement->close();

$flags = aiReadFeatureFlags($conn);
$management = aiAnalyticsIsManagement($user);
$enabled = static fn(string $key): bool => aiFeatureEnabled($flags, $key);
$available = $management ? ['rankings', 'calibration', 'alerts', 'progress'] : ['progress'];
$available = array_values(array_filter($available, $enabled));
if (!$available) throw new Exception('Analytics exports are unavailable while their features are disabled.', 403);

// Use the same application functions as the on-screen Analytics view.
$rankings = $enabled('rankings') && $management ? [
    'department' => aiAnalyticsRankings($conn, $user, $companyId, $cycleId, 'department'),
    'supervisor' => aiAnalyticsRankings($conn, $user, $companyId, $cycleId, 'supervisor'),
    'level' => aiAnalyticsRankings($conn, $user, $companyId, $cycleId, 'level'),
    'band' => aiAnalyticsRankings($conn, $user, $companyId, $cycleId, 'band'),
] : [];
$calibration = $enabled('calibration') && $management ? aiAnalyticsCalibration($conn, $user, $companyId, $cycleId) : null;
$alerts = $enabled('alerts') && $management ? aiAnalyticsAlerts($conn, $user, $companyId, $cycle) : null;
$progress = $enabled('progress') ? aiAnalyticsProgress($conn, $user, $companyId, $cycleId) : null;

// Existing permission/suppression queries finish before the single translation query.
$approved = exportContentMap($conn, $locale, [
    ['context' => 'cycle_title', 'values' => [(string)$cycle['title']]],
    ['context' => 'department', 'values' => array_merge(
        array_column($rankings['department']['rows'] ?? [], 'label'),
        array_column($progress['people'] ?? [], 'department'),
        array_column($alerts['drops'] ?? [], 'department'),
        array_column($alerts['sustained'] ?? [], 'department'),
        array_column($alerts['support'] ?? [], 'department'))],
    ['context' => 'scoring_profile', 'values' => array_column($rankings['level']['rows'] ?? [], 'label')],
]);
$navy = '17263D';
$green = '308F50';
$light = 'EDF7F0';
$muted = '63748A';
$book = new Spreadsheet();
$book->getProperties()->setCreator('AppraisalSuite')
    ->setTitle(exportLabel($locale, 'Appraisal Analytics') . ' - ' . exportContent($approved, 'cycle_title', $cycle['title']) . ' (' . (int)$cycle['year'] . ')')
    ->setSubject('Authorized appraisal analytics - confidential');
$cycleLabel = exportContent($approved, 'cycle_title', $cycle['title']) . ' (' . (int)$cycle['year'] . ')';
$subtitle = $companyName . '  |  ' . $cycleLabel . '  |  ' . exportLabel($locale, $management ? 'Management access' : 'My team only')
    . '  |  ' . exportLabel($locale, 'Generated') . ' '
    . ($locale === 'fr' ? exportDate($locale, gmdate('Y-m-d H:i'), true) : gmdate('d M Y, H:i')) . ' UTC';

/** Create a styled, readable, filterable worksheet. */
$sheet = static function (string $name, string $description, array $headers, array $rows, array $widths)
    use ($book, $cycleLabel, $subtitle, $navy, $green, $light, $muted, $locale): void {
    $sourceName = $name;
    $originalHeaders = $headers;
    $name = exportLabel($locale, $name);
    $description = exportLabel($locale, $description);
    $headers = array_map(static fn($header) => exportLabel($locale, $header), $headers);
    if ($sourceName === 'Overview') {
        foreach ($rows as &$line) {
            if (isset($line[0])) $line[0] = exportLabel($locale, $line[0]);
        }
        unset($line);
    }
    static $first = true;
    $tab = $first ? $book->getActiveSheet() : $book->createSheet();
    $first = false;
    $tab->setTitle($name);
    $cols = count($headers);
    $lastColumn = Coordinate::stringFromColumnIndex($cols);
    $tab->mergeCells("A1:{$lastColumn}1");
    $tab->setCellValue('A1', exportLabel($locale, 'APPRAISALSUITE  /  ANALYTICS REPORT'));
    $tab->getStyle("A1:{$lastColumn}1")->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $navy]],
        'font' => ['name' => 'Aptos Display', 'bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $tab->getRowDimension(1)->setRowHeight(42);
    $tab->mergeCells("A2:{$lastColumn}2");
    $tab->setCellValue('A2', $subtitle);
    $tab->getStyle("A2:{$lastColumn}2")->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $light]],
        'font' => ['name' => 'Aptos', 'size' => 10, 'color' => ['rgb' => $navy]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $tab->getRowDimension(2)->setRowHeight(30);
    $tab->mergeCells("A3:{$lastColumn}3");
    $tab->setCellValue('A3', $description);
    $tab->getStyle("A3:{$lastColumn}3")->applyFromArray([
        'font' => ['name' => 'Aptos', 'italic' => true, 'size' => 10, 'color' => ['rgb' => $muted]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    ]);
    $tab->getRowDimension(3)->setRowHeight(29);

    foreach ($headers as $i => $header) $tab->setCellValue([$i + 1, 5], $header);
    $tab->getStyle("A5:{$lastColumn}5")->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $green]],
        'font' => ['name' => 'Aptos', 'bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    ]);
    $tab->getRowDimension(5)->setRowHeight(33);

    if (!$rows) $rows = [array_merge([exportLabel($locale, 'No records available')], array_fill(0, $cols - 1, ''))];
    foreach ($rows as $i => $values) {
        $r = 6 + $i;
        foreach ($headers as $j => $_) {
            $value = $values[$j] ?? '';
            if ($value === null) $value = exportLabel($locale, 'Suppressed (<3 people)');
            if (is_bool($value)) $value = $value ? 'Yes' : 'No';
            if (is_string($value)) {
                // Never interpret employee names, department labels or comments as formulas.
                $tab->setCellValueExplicit([$j + 1, $r], mb_substr($value, 0, 2000), DataType::TYPE_STRING);
            } else {
                $tab->setCellValue([$j + 1, $r], $value);
            }
        }
        if ($i % 2 === 1) {
            $tab->getStyle("A{$r}:{$lastColumn}{$r}")->getFill()
                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F6F9');
        }
        $tab->getRowDimension($r)->setRowHeight(24);
    }
    $bottom = count($rows) + 5;
    $tab->getStyle("A6:{$lastColumn}{$bottom}")->applyFromArray([
        'font' => ['name' => 'Aptos', 'size' => 10, 'color' => ['rgb' => $navy]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'DDE5ED']]],
    ]);
    foreach ($widths as $j => $width) {
        $tab->getColumnDimension(Coordinate::stringFromColumnIndex($j + 1))->setWidth($width);
    }
    foreach ($originalHeaders as $j => $header) {
        $col = Coordinate::stringFromColumnIndex($j + 1);
        if (str_contains($header, '(%)')) $tab->getStyle("{$col}6:{$col}{$bottom}")->getNumberFormat()->setFormatCode('0.0"%"');
        elseif (str_contains($header, 'Average') || str_contains($header, 'Score') || str_contains($header, 'Difference')) {
            $tab->getStyle("{$col}6:{$col}{$bottom}")->getNumberFormat()->setFormatCode('0.00');
        }
    }
    $tab->freezePane('A6');
    $tab->setAutoFilter("A5:{$lastColumn}{$bottom}");
    $tab->getPageSetup()->setFitToWidth(1);
    $tab->getPageMargins()->setTop(.5);
    $tab->getPageMargins()->setBottom(.5);
    $tab->getPageMargins()->setLeft(.4);
    $tab->getPageMargins()->setRight(.4);
    $tab->getHeaderFooter()->setOddFooter($locale === 'fr'
        ? '&LConfidentiel | AppraisalSuite&RPage &P sur &N'
        : '&LConfidential | AppraisalSuite&RPage &P of &N');
    $tab->getSheetView()->setZoomScale(90);
};

$overview = [
    ['Company', $companyName],
    ['Cycle', $cycleLabel],
    ['Scope', $management ? 'Authorized management records' : 'Assigned staff only'],
    ['Generated (UTC)', gmdate('Y-m-d H:i')],
    ['Included views', implode(', ', array_map('ucfirst', $available))],
];
if ($calibration) {
    $overview[] = ['Scored appraisals', (int)$calibration['appraisals']];
    $overview[] = ['Company average score', (int)$calibration['appraisals'] >= AI_ANALYTICS_MIN_GROUP ? $calibration['company_average'] : null];
    $overview[] = ['Supervisors flagged for calibration', (int)$calibration['flagged']];
}
if ($progress) {
    $overview[] = ['Assigned staff', (int)$progress['assigned']];
    $overview[] = ['Appraisals submitted (%)', (int)$progress['percent_submitted']];
    $overview[] = ['Appraisals acknowledged (%)', (int)$progress['percent_complete']];
}
if ($alerts) {
    $overview[] = ['Significant score drops', count($alerts['drops'])];
    $overview[] = ['Outstanding 3+ years', count($alerts['sustained'])];
    $overview[] = ['Development support flags', count($alerts['support'])];
}
$sheet('Overview', 'Calculated from authorized records; no AI tokens used.', ['Indicator', 'Value'], $overview, [41, 62]);

if ($rankings) {
    foreach (['department' => 'Departments', 'supervisor' => 'Supervisor Rankings', 'level' => 'Staff Levels'] as $key => $tabName) {
        $rows = array_map(static fn(array $r): array => [
            $r['rank'], $key === 'department'
                ? exportContent($approved, 'department', $r['label'])
                : ($key === 'level' ? exportContent($approved, 'scoring_profile', $r['label']) : $r['label']),
            (int)$r['people'], (int)$r['appraisals'],
            $r['average'], $r['lowest'], $r['highest'], $r['outstanding_share'], (int)$r['pending'],
        ], $rankings[$key]['rows']);
        $sheet($tabName, 'Groups below three distinct people have suppressed score statistics.',
            ['Rank', $key === 'department' ? 'Department' : ($key === 'level' ? 'Staff level' : 'Supervisor'),
             'People', 'Appraisals', 'Average Score', 'Lowest Score', 'Highest Score', 'Outstanding (%)', 'Pending'],
            $rows, [10, 42, 12, 15, 19, 18, 18, 20, 13]);
    }
}
$distribution = $rankings['band']['distribution'] ?? ($calibration['distribution'] ?? null);
if ($distribution) {
    $sheet('Rating Spread', 'Guidelines are a calibration reference, not a forced ranking.',
        ['Rating band', 'Appraisals', 'Share (%)', 'Guideline min (%)', 'Guideline max (%)', 'Position'],
        array_map(static fn(array $band): array => [
            exportLabel($locale, $band['label']), $band['count'], $band['share'], $band['guide_low'], $band['guide_high'], exportLabel($locale, ucfirst((string)$band['position'])),
        ], $distribution['bands']), [31, 18, 19, 22, 22, 22]);
}
if ($calibration) {
    $sheet('Calibration', 'Differences are signals for review, not determinations of unfairness.',
        ['Supervisor', 'People', 'Appraisals', 'Average Score', 'Difference', 'Outstanding (%)', 'Below Meets (%)', 'Review'],
        array_map(static fn(array $s): array => [
            $s['name'], (int)$s['people'], (int)$s['appraisals'], $s['average'], $s['difference'],
            $s['outstanding_share'], $s['below_meets_share'],
            exportLabel($locale, ['high' => 'Rates high', 'low' => 'Rates low', 'typical' => 'In line', 'too_few' => 'Insufficient group size'][$s['flag']] ?? $s['flag']),
        ], $calibration['supervisors']), [38, 12, 15, 20, 18, 21, 22, 26]);
}
if ($progress) {
    $stageLabels = ['level_needed' => 'Level needed', 'objectives_needed' => 'Objectives not locked',
        'ready' => 'Ready to appraise', 'awaiting_ack' => 'Awaiting acknowledgement', 'complete' => 'Complete'];
    $sheet('Progress', 'Appraisal workflow status for your authorized scope.', ['Stage', 'People'],
        array_map(static fn($key, $label): array => [exportLabel($locale, $label), (int)($progress['stages'][$key] ?? 0)],
            array_keys($stageLabels), array_values($stageLabels)), [37, 20]);
    if ($management) {
        $sheet('Supervisor Progress', 'Each row uses assignments visible in this cycle.',
            ['Supervisor', 'Onboarded', 'Assigned', 'Level needed', 'Objectives needed', 'Ready', 'Awaiting ack.', 'Complete', 'Complete (%)'],
            array_map(static fn(array $r): array => [
                $r['name'], $r['onboarded'], $r['assigned'], $r['level_needed'], $r['objectives_needed'],
                $r['ready'], $r['awaiting_ack'], $r['complete'], $r['percent_done'],
            ], $progress['supervisors']), [41, 17, 15, 18, 22, 15, 20, 15, 19]);
    }
    $sheet('Pending Tasks', 'Read-only snapshot. Lists are limited to the records returned by Analytics.',
        ['Employee', 'Department', 'Supervisor', 'Stage', 'Appraisal link'],
        array_map(static fn(array $r): array => [
            $r['name'], exportContent($approved, 'department', $r['department']), $r['supervisor'], exportLabel($locale, $stageLabels[$r['stage']] ?? $r['stage']), (string)($r['link'] ?? ''),
        ], $progress['people']), [42, 32, 42, 33, 35]);
}
if ($alerts) {
    $sheet('Score Drops', 'Comparisons with earlier frameworks are indicative.',
        ['Employee', 'Department', 'Previous Score', 'Current Score', 'Difference', 'Appraisal link'],
        array_map(static fn(array $r): array => [$r['name'], exportContent($approved, 'department', $r['department']), $r['previous'], $r['score'], $r['change'], $r['link']], $alerts['drops']),
        [42, 32, 22, 22, 20, 35]);
    $sheet('Repeated Outstanding', 'Outstanding scores in at least three consecutive appraisal years.',
        ['Employee', 'Department', 'Consecutive years', 'Current Score', 'Appraisal link'],
        array_map(static fn(array $r): array => [$r['name'], exportContent($approved, 'department', $r['department']), $r['years'], $r['score'], $r['link']], $alerts['sustained']),
        [42, 32, 24, 21, 35]);
    $sheet('Development Alerts', 'People rated below Fully Meets. Review in context.',
        ['Employee', 'Department', 'Current Score', 'Appraisal link'],
        array_map(static fn(array $r): array => [$r['name'], exportContent($approved, 'department', $r['department']), $r['score'], $r['link']], $alerts['support']),
        [42, 32, 21, 35]);
}

$book->setActiveSheetIndex(0);
$filename = ($locale === 'fr' ? 'Analyses_Evaluations_' : 'Appraisal_Analytics_') . (int)$cycle['year'] . '_' . $cycleId . '.xlsx';
// All generated AI analytics Excel exports are reads (allowed for view grants)
// and must be audited under the ACTING company before bytes leave the server.
$exportRows = (int)($calibration['appraisals'] ?? $rankings['band']['distribution']['total'] ?? $progress['assigned'] ?? 0);
companyExportAudit($conn, $user, aiReadCompanyContext($user, $companyId), $companyId,
    'export_ai_analytics_excel', 'appraisal_cycles', $cycleId, $exportRows);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, no-store, max-age=0');
(new Xlsx($book))->save('php://output');
$book->disconnectWorksheets();
exit;
