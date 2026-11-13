<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once __DIR__ . '/ObjectivePlanHelpers.php';
header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Exception('Bad Request: Only GET method is allowed', 405);
    $userData = requireRoles(['super_admin','admin','supervisor']);
    $staffUserId = isset($_GET['staff_user_id']) ? (int)$_GET['staff_user_id'] : 0;
    $sectionId = isset($_GET['section_id']) ? (int)$_GET['section_id'] : 0;
    $ctx = opResolveContext($conn, $userData, $staffUserId, $sectionId, true);
    http_response_code(200);
    echo json_encode(['status'=>'Success','message'=>'Objective setup loaded successfully.','data'=>opPayload($conn,$ctx)]);
} catch (Throwable $e) {
    $code=(int)$e->getCode(); $code=($code>=400&&$code<=599)?$code:500;
    http_response_code($code);
    echo json_encode(['status'=>'Failed','message'=>$e->getMessage()]);
}
