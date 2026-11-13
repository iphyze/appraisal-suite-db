<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/CompanySetupWrite.php';

require_once 'includes/ContentTranslationResponse.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Bad Request: Only GET method is allowed", 400);
    }

    $userData          = authenticateUser();
    $loggedInUserRole  = $userData['role'];
    $companyScope      = companySetupReadScope($userData);
    $clause            = buildCompanyWhereClause($companyScope, 'd');

    $q = isset($_GET['q']) ? trim($_GET['q']) : (isset($_GET['search']) ? trim($_GET['search']) : '');
    $companyId = isset($_GET['company_id']) && $_GET['company_id'] !== '' ? (int) $_GET['company_id'] : null;
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
    if ($limit <= 0) $limit = 50;
    if ($limit > 100) $limit = 100;

    $sql = "
        SELECT d.id, d.company_id, d.name, d.is_active,
               c.code AS company_code, c.name AS company_name
        FROM departments d
        INNER JOIN companies c ON c.id = d.company_id
        WHERE d.is_active = 1
    ";
    $params = [];
    $types = '';

    if ($clause['value'] !== null) {
        $sql .= " AND d.company_id = ?";
        $params[] = $clause['value'];
        $types .= 'i';
    } elseif ($loggedInUserRole === 'super_admin' && $companyId) {
        $sql .= " AND d.company_id = ?";
        $params[] = $companyId;
        $types .= 'i';
    }

    if ($q !== '') {
        $params[] = '%' . $q . '%';
        $types .= 's';
        $hashes = contentTranslationFindSearchHashes($conn, $userData, $q, ['department']);
        $translatedMatch = contentTranslationHashSearchOr('d.name', $hashes, $params, $types);
        $sql .= ' AND (d.name LIKE ?' . $translatedMatch . ')';
    }

    $sql .= " ORDER BY d.name ASC LIMIT ?";
    $params[] = $limit;
    $types .= 'i';

    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception("Database error: " . $conn->error, 500);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $departments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Batch 6b: translate approved setup content in one batched read.
    contentTranslationApplyPaths($conn, $departments, $userData, ['*.name' => ['context' => 'department', 'into' => 'name_display']]);

    http_response_code(200);
    echo json_encode([
        'status' => 'Success',
        'message' => 'Departments search completed successfully',
        'data' => $departments,
    ]);

} catch (Exception $e) {
    error_log("SearchDepartments Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
