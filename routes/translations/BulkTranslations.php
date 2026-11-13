<?php
/**
 * GET  /translations/bulk     Download XLSX workbook (missing or all)
 * POST /translations/bulk     Preview proposed Excel translations, no writes
 * PUT  /translations/bulk     Transactional draft/approve after Super Admin reason
 * Central router validates CSRF for POST/PUT, then this route restricts roles.
 */
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/ContentTranslationBulk.php';

header('Cache-Control: private, no-store, max-age=0');
$user = requireRoles(['super_admin']);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    require_once __DIR__ . '/../utils/SimpleXlsx.php';
    $filters = ctbExportFilters($_GET);
    $sources = ctmReadSources($conn, (int)$filters['company_id']);
    $translations = ctmReadTranslations($conn);
    $export = ctbExportRows($sources, $translations, $filters);
    $sheets = [
        ['name' => 'Translations',
         'headers' => ['Source hash', 'Context', 'English source', 'French translation', 'Expected status', 'Expected French', 'Company IDs'],
         'rows' => $export, 'widths' => [66, 23, 61, 61, 19, 61, 18]],
        ['name' => 'Instructions',
         'headers' => ['Step', 'Instructions'],
         'rows' => [
            [1, 'Only edit column D (French translation) on the Translations sheet. Keep columns A, B, C, E and F unchanged.'],
            [2, 'Fill the blank French cells, or edit an existing D cell. Blank French cells are skipped on import.'],
            [3, 'Import the .xlsx in Translation Management to preview every row. The import limit is 1,000 completed rows per operation.'],
            [4, 'Rows already approved with different French wording are conflicts and must be reviewed individually.'],
            [5, 'Select proposed rows and choose Save as Draft or Approve, then provide a Super Admin reason.'],
            [6, 'Draft translations do not appear to normal users. Existing approved translations remain approved unless individually revised.'],
            [7, 'Do not enter personal comments, employee names, staff IDs or private notes. This file is only for configured source text.'],
         ], 'widths' => [10, 110]],
    ];
    streamMultiSheetXlsx('AppraisalSuite_French_Translations_' . date('Y-m-d') . '.xlsx', $sheets);
}

if ($method !== 'POST' && $method !== 'PUT') throw new InvalidArgumentException('Method not allowed.', 405);
$raw = file_get_contents('php://input');
if (!is_string($raw) || strlen($raw) > CTB_MAX_REQUEST_BYTES) {
    throw new InvalidArgumentException('Translation import exceeds the request size limit.', 413);
}
$body = json_decode($raw, true);
if (!is_array($body) || ctbIsList($body)) throw new InvalidArgumentException('Expected translation import JSON object.', 400);
$incoming = ctbIncomingRows($body);
if ($method === 'POST') {
    $preview = ctbPreviewRows($incoming, ctmReadSources($conn), ctmReadTranslations($conn));
    echo json_encode(['status' => 'Success', 'data' => $preview], JSON_INVALID_UTF8_SUBSTITUTE);
    return;
}
$result = ctbCommit($conn, $user, $body);
echo json_encode(['status' => 'Success', 'data' => $result], JSON_INVALID_UTF8_SUBSTITUTE);
