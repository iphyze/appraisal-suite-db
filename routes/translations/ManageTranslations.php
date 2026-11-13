<?php
/**
 * GET /translations/manage    Super Admin inventory, missing/draft/approved.
 * PUT /translations/manage    Super Admin Save Draft or Approve, with reason.
 * Central router verifies CSRF on all PUT requests; no public route.
 */
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/ContentTranslationManagement.php';

header('Cache-Control: private, no-store, max-age=0');
$user = requireRoles(['super_admin']);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $filters = ctmFilters($_GET);
    $sourceRows = ctmReadSources($conn, (int)$filters['company_id']);
    $translations = ctmReadTranslations($conn);
    $result = ctmBuildInventory($sourceRows, $translations, $filters);
    $companies = [];
    $companyResult = $conn->query('SELECT id, name FROM companies ORDER BY name, id');
    if (!$companyResult) throw new RuntimeException('Unable to load company filters.');
    while ($row = $companyResult->fetch_assoc()) {
        $companies[] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
    }
    $companyResult->free();
    echo json_encode(['status' => 'Success', 'data' => $result['rows'],
        'meta' => $result['meta'], 'companies' => $companies], JSON_INVALID_UTF8_SUBSTITUTE);
    return;
}

if ($method !== 'PUT') throw new Exception('Method not allowed.', 405);
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 35000) throw new Exception('Invalid translation request.', 413);
$body = json_decode($raw, true);
if (!is_array($body) || !isset($body['action'])) throw new Exception('Expected a JSON object.', 400);
$result = ctmSaveTranslation($conn, $user, $body);
echo json_encode(['status' => 'Success', 'data' => $result, 'message' => 'Translation saved.'], JSON_INVALID_UTF8_SUBSTITUTE);
