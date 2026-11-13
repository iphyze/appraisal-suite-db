<?php
/** Styled, read-only management insights export. Uses the same guarded metrics as /ai/insights. */
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/AiFeatureFlags.php';
require_once __DIR__ . '/../../includes/AiManagementMetrics.php';
require_once __DIR__ . '/../../includes/AiResultCache.php';
require_once __DIR__ . '/../../includes/AiLanguage.php';
require_once __DIR__ . '/../../includes/CompanyExportAccess.php';
require_once __DIR__ . '/../../includes/ExportLocalization.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    throw new Exception('Method not allowed.', 405);
}
$user = requireRoles(['admin', 'super_admin']);
$locale = exportLocale($user);
aiRequireFeature($conn, 'management_insights');
$viewCompanyId = isset($_GET['view_company_id']) ? (int)$_GET['view_company_id'] : null;
$cycleId = aiManagementCycleId($_GET['cycle_id'] ?? 0);
$report = aiManagementRead($conn, $user, $viewCompanyId, $cycleId);
if (empty($report['cycle_id'])) throw new Exception('No appraisal cycle available for export.', 422);

$selected = null;
foreach ($report['cycles'] as $option) {
    if ((int)$option['id'] === (int)$report['cycle_id']) { $selected = $option; break; }
}
// Resolve only approved, configuration-authored text with one batched lookup.
$approved = exportContentMap($conn, $locale, [
    ['context' => 'cycle_title', 'values' => array_merge(
        [(string)($selected['title'] ?? 'Appraisal')],
        array_column($report['trend'], 'label'))],
    ['context' => 'department', 'values' => array_column($report['departments'], 'department')],
]);
$cycleName = exportContent($approved, 'cycle_title', trim((string)($selected['title'] ?? 'Appraisal')))
    . ' (' . (int)($selected['year'] ?? 0) . ')';
$summary = $report['summary'];
$green = '27934D';
$navy = '14243A';
$light = 'EEF7F0';
$book = new Spreadsheet();
$book->getProperties()->setCreator('AppraisalSuite')->setTitle(exportLabel($locale, 'AI Management Insights') . ' - ' . $cycleName)
    ->setSubject('Authorized management appraisal aggregates');

/** Each worksheet is styled consistently with the existing green/navy AppraisalSuite palette. */
$addSheet = static function (string $name, string $subtitle, array $headers, array $rows, array $widths) use ($book, $navy, $green, $light, $locale) {
    $sourceName = $name;
    $name = exportLabel($locale, $name);
    $subtitle = exportLabel($locale, $subtitle);
    $headers = array_map(static fn($value) => exportLabel($locale, $value), $headers);
    if ($sourceName === 'Summary' || $sourceName === 'AI Observations') {
        foreach ($rows as &$line) {
            if (isset($line[0])) $line[0] = exportLabel($locale, $line[0]);
            if ($sourceName === 'AI Observations' && isset($line[1]) && $line[0] === exportLabel($locale, 'Not generated')) {
                $line[1] = exportLabel($locale, $line[1]);
            }
        }
        unset($line);
    }
    static $first = true;
    $sheet = $first ? $book->getActiveSheet() : $book->createSheet();
    $first = false;
    $sheet->setTitle($name);
    $endCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
    $sheet->mergeCells("A1:{$endCol}1");
    $sheet->setCellValue('A1', exportLabel($locale, 'APPRAISALSUITE  /  AI MANAGEMENT INSIGHTS'));
    $sheet->getStyle("A1:{$endCol}1")->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $navy]],
        'font' => ['bold' => true, 'size' => 15, 'color' => ['rgb' => 'FFFFFF']],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(34);
    $sheet->mergeCells("A2:{$endCol}2");
    $sheet->setCellValue('A2', $subtitle);
    $sheet->getStyle("A2:{$endCol}2")->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $light]],
        'font' => ['size' => 11, 'color' => ['rgb' => $navy]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension(2)->setRowHeight(28);
    foreach ($headers as $index => $value) $sheet->setCellValue([$index + 1, 4], $value);
    $sheet->getStyle("A4:{$endCol}4")->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $green]],
        'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    ]);
    $sheet->getRowDimension(4)->setRowHeight(31);
    foreach ($rows as $i => $values) {
        $r = $i + 5;
        foreach ($values as $j => $value) {
            // Treat labels as text, never spreadsheet formula input.
            if ($value === null) $value = exportLabel($locale, 'Not shown (<3 staff)');
            if (is_string($value)) $sheet->setCellValueExplicit([$j + 1, $r], $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            else $sheet->setCellValue([$j + 1, $r], $value);
        }
        if ($i % 2 === 1) $sheet->getStyle("A{$r}:{$endCol}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F4F7FA');
    }
    $last = max(5, count($rows) + 4);
    $sheet->getStyle("A5:{$endCol}{$last}")->applyFromArray([
        'font' => ['size' => 10, 'color' => ['rgb' => $navy]],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'E2E8F0']]],
    ]);
    for ($r = 5; $r <= $last; $r++) $sheet->getRowDimension($r)->setRowHeight(22);
    foreach ($widths as $i => $width) $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1))->setWidth($width);
    $sheet->freezePane('A5');
    $sheet->setAutoFilter("A4:{$endCol}{$last}");
    $sheet->getPageSetup()->setFitToWidth(1);
    $sheet->getPageMargins()->setTop(.5);
    $sheet->getPageMargins()->setBottom(.5);
    $sheet->getPageMargins()->setLeft(.4);
    $sheet->getPageMargins()->setRight(.4);
    return $sheet;
};

$addSheet('Summary', $cycleName . '  |  ' . exportLabel($locale, 'Authorized appraisal records only'), ['Metric', 'Value'], [
    ['Appraisals recorded', (int)$summary['appraisals']],
    ['Distinct appraised employees', (int)$summary['unique_staff']],
    ['Average final score', $summary['unique_staff'] >= 3 ? $summary['average_score'] : null],
    ['Acknowledged', (int)$summary['acknowledged']],
    ['Awaiting acknowledgement', (int)$summary['pending_acknowledgements']],
    ['Open management follow-ups', (int)$summary['open_follow_ups']],
    ['Responses awaiting resolution', (int)$summary['responded_follow_ups']],
    ['Pending safety/integrity verification', (int)$summary['pending_breach_reviews']],
], [39, 27]);

$addSheet('Departments', $cycleName . '  |  ' . exportLabel($locale, 'Groups with fewer than three staff have no average'),
    ['Department', 'Appraisals', 'Average score', 'Awaiting acknowledgement'],
    array_map(static fn($r) => [
        exportContent($approved, 'department', $r['department']), (int)$r['appraisals'],
        $r['small_cohort'] ? null : (float)$r['average_score'], (int)$r['pending_acknowledgements'],
    ], $report['departments']), [35, 18, 23, 29]);

$addSheet('Score Distribution', $cycleName . '  |  ' . exportLabel($locale, 'Final score bands'), ['Final score band', 'Appraisals'],
    array_map(static fn($r) => [exportLabel($locale, $r['label']), (int)$r['count']], $report['distribution']), [32, 20]);

$addSheet('Cycle History', exportLabel($locale, 'Indicative only: scoring structures may differ across cycles'),
    ['Cycle', 'Year', 'Appraisals', 'Average score'],
    array_map(static fn($r) => [
        exportContent($approved, 'cycle_title', $r['label']), (int)$r['year'], (int)$r['appraisals'],
        $r['average_score'] === null ? null : (float)$r['average_score'],
    ], $report['trend']), [38, 16, 19, 23]);

// Include only already-saved, still-current AI interpretations. No tokens are spent.
// The cache keys use the same suppressed model payload as the original endpoint.
$observations = [];
if ((int)$summary['unique_staff'] >= 3) {
    $cacheCompany = aiReadCompanyId($user, $viewCompanyId);
    $grantCacheIdentity = aiGrantCacheIdentity($user, $cacheCompany);
    $modelData = aiManagementModelPayload($report);
    foreach (['overview' => 'Overall performance', 'departments' => 'Departments', 'follow_ups' => 'Follow-ups'] as $focus => $label) {
        $cached = aiCacheGet($conn, 'management_insights', $cacheCompany, aiCacheKeyForLocale(array_merge(['v1', $focus, $modelData], $grantCacheIdentity), $locale));
        $answer = trim((string)($cached['data']['answer'] ?? ''));
        if ($answer === '') continue;
        $firstLine = true;
        foreach (array_slice(preg_split('/\r\n|\r|\n/', $answer), 0, 80) as $line) {
            $line = trim((string)$line);
            if ($line === '') continue;
            $observations[] = [$firstLine ? $label : '', $line, $firstLine ? (string)($cached['created_at'] ?? '') : ''];
            $firstLine = false;
        }
    }
}
if (!$observations) {
    $observations[] = ['Not generated', 'No current saved AI interpretation is available for this cycle. Generate AI insights in the app first.', ''];
}
$observationsSheet = $addSheet('AI Observations', exportLabel($locale, 'Saved interpretations only  |  Review human decisions independently'),
    ['Analysis focus', 'AI-generated observation', 'Generated (UTC)'], $observations, [26, 100, 27]);
foreach (range(5, count($observations) + 4) as $rowNumber) $observationsSheet->getRowDimension($rowNumber)->setRowHeight(40);

$book->setActiveSheetIndex(0);
$filename = ($locale === 'fr' ? 'Analyses_IA_Direction_' : 'AI_Management_Insights_') . (int)($selected['year'] ?? 0) . '.xlsx';
$exportCompanyId = aiReadCompanyId($user, $viewCompanyId);
companyExportAudit($conn, $user, aiReadCompanyContext($user, $exportCompanyId), $exportCompanyId,
    'export_ai_management_insights_excel', 'appraisal_cycles', (int)$report['cycle_id'], (int)$summary['appraisals']);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, no-store, max-age=0');
$writer = new Xlsx($book);
$writer->save('php://output');
$book->disconnectWorksheets();
exit;
