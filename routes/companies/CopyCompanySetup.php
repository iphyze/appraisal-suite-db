<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/CompanySetupClone.php';
header('Content-Type: application/json; charset=UTF-8');

try {
    $actor = requireRoles(['super_admin']);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST'], true)) {
        throw new Exception('Only GET and POST are supported.', 405);
    }
    $data = $method === 'POST' ? json_decode((string)file_get_contents('php://input'), true) : $_GET;
    if (!is_array($data)) throw new Exception('Invalid request body.', 400);
    $sourceId = filter_var($data['source_cycle_id'] ?? null, FILTER_VALIDATE_INT);
    $targetId = filter_var($data['target_company_id'] ?? null, FILTER_VALIDATE_INT);
    companySetupCloneValidateIds((int)$sourceId, (int)$targetId);
    if ($method === 'GET') {
        $bundle = companySetupCloneLoad($conn, (int)$sourceId, (int)$targetId);
        http_response_code(200);
        echo json_encode(['status' => 'Success', 'data' => companySetupClonePreview($bundle)]);
        return;
    }
    $reason = companySetupCloneReason((string)($data['reason'] ?? ''));
    $conn->begin_transaction();
    try {
        // Re-check the source and target inside the transaction, not from an earlier preview.
        $bundle = companySetupCloneLoad($conn, (int)$sourceId, (int)$targetId, true);
        $result = companySetupCloneApplyBundle($conn, $bundle, $actor, $reason);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    http_response_code(201);
    echo json_encode(['status' => 'Success', 'message' => 'Company setup copied.', 'data' => $result]);
} catch (Throwable $e) {
    $code = (int)$e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    error_log('CopyCompanySetup: ' . $e->getMessage());
    http_response_code($code);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
