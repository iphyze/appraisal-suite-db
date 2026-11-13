<?php
/** GET /search/interpret?q=...&view_company_id=... — read-only, rule-based. */
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/AiFeatureFlags.php';
require_once __DIR__ . '/../../includes/AiReadTools.php';
require_once __DIR__ . '/../../includes/AiSmartSearch.php';
require_once __DIR__ . '/../../includes/ContentTranslation.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'GET') {
    throw new Exception('Method not allowed.', 405);
}
$user = authenticateUser();
aiRequireFeature($conn, 'smart_search');
$query = aiSmartSearchQuery($_GET['q'] ?? null);

// Super Admin explicitly selects the company; other roles use their own company.
$selectedCompanyId = null;
if (isset($_GET['view_company_id']) && $_GET['view_company_id'] !== '') {
    if (!preg_match('/^[1-9][0-9]{0,9}$/D', (string)$_GET['view_company_id'])) {
        throw new Exception('Invalid company context.', 422);
    }
    $selectedCompanyId = (int)$_GET['view_company_id'];
}
$companyId = aiReadCompanyId($user, $selectedCompanyId);

$stmt = $conn->prepare('SELECT name FROM departments WHERE company_id = ? AND is_active = 1 ORDER BY LENGTH(name) DESC LIMIT 250');
if (!$stmt) throw new Exception('Unable to interpret search right now.', 503);
$stmt->bind_param('i', $companyId);
$stmt->execute();
$departments = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'name');
$stmt->close();
$locale = appLocaleForResponse($user);
$aliases = [];
if ($locale === 'fr' && $departments) {
    // Only approved translations of departments in the *authorized acting company*.
    $translated = contentTranslationResolveBatch($conn, array_map(
        static fn($name) => ['text' => (string)$name, 'context' => 'department'], $departments
    ), 'fr');
    foreach ($departments as $index => $name) {
        if (isset($translated[$index]) && $translated[$index] !== $name) {
            $aliases[$name] = $translated[$index];
        }
    }
}
$result = aiSmartSearchInterpret($query, $departments, (string)$user['role_key'], $aliases);
if ($result && $locale === 'fr') {
    $result = aiSmartSearchFrenchResult($result);
}
// A suggestion that points to a switched-off page is dropped quietly; the normal search results still show.
if ($result && in_array($result['intent'], ['rankings', 'progress'], true)
    && !aiFeatureEnabled(aiReadFeatureFlags($conn), $result['intent'])) {
    $result = null;
}
header('Cache-Control: private, no-store, max-age=0');
echo json_encode(['status' => 'Success', 'data' => ['matched' => $result !== null, 'result' => $result]], JSON_INVALID_UTF8_SUBSTITUTE);
