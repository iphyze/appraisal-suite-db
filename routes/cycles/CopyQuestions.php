<?php
/** Super Admin-only question copying between existing appraisal cycles. */
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/CompanyQuestionCopy.php';
header('Content-Type: application/json; charset=UTF-8');

try {
    $actor = requireRoles(['super_admin']);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST'], true)) throw new Exception('Only GET and POST are supported.', 405);
    $input = $method === 'GET' ? $_GET : json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) throw new Exception('Expected a JSON object.', 400);
    [$from, $to] = cqCopyIds($input['source_cycle_id'] ?? null, $input['target_cycle_id'] ?? null);

    if ($method === 'GET') {
        $bundle = cqLoad($conn, $from, $to);
        echo json_encode(['status' => 'Success', 'data' => cqPublicPreview($bundle, cqBuildPlan($bundle))]);
        return;
    }
    $reason = cqCopyReason((string)($input['reason'] ?? ''));
    $selected = cqSelectedKeys($input['question_keys'] ?? null);
    $conn->begin_transaction();
    try {
        // Live recheck and ordered cycle locks; never trust the earlier preview.
        $bundle = cqLoad($conn, $from, $to, true);
        $plan = cqBuildPlan($bundle);
        $result = cqApply($conn, $bundle, $plan, $selected, $actor, $reason);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    http_response_code(201);
    echo json_encode(['status' => 'Success', 'message' => 'Questions copied successfully.', 'data' => $result]);
} catch (Throwable $e) {
    $status = (int)$e->getCode();
    if ($status < 400 || $status > 599) $status = 500;
    error_log('CopyQuestions: ' . $e->getMessage());
    http_response_code($status);
    echo json_encode(['status' => 'Failed', 'message' => $status >= 500 ? 'Unable to copy questions.' : $e->getMessage()]);
}
