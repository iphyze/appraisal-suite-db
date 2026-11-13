<?php
/** Batch 8B: per-item, live-validated notification navigation target. */
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/CompanyNotificationRead.php';
require_once __DIR__ . '/../utils/Notifications.php';
header('Content-Type: application/json; charset=UTF-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Bad Request: Only GET method is allowed.', 405);
    }
    $userData = authenticateUser();
    // Validate any company selector sent by the client, but do not require a
    // specific selector: the user's inbox spans their authorized companies.
    resolveActingCompanyContext($userData);
    ensureNotificationsTable($conn);
    $rawId = $_GET['id'] ?? null;
    if (!is_string($rawId) && !is_int($rawId)) {
        throw new Exception('A valid notification ID is required.', 400);
    }
    if (!preg_match('/^[1-9][0-9]*$/D', (string)$rawId)
        || (int)$rawId <= 0) {
        throw new Exception('A valid notification ID is required.', 400);
    }
    $notificationId = (int)$rawId;
    $scope = companyNotificationBindings($userData);
    // The same recipient AND grant/subject/peer-privacy predicate is applied
    // to list, unread pulse, mark-read, and navigation. An old preview cannot
    // authorize a link following a revoke or staff-scope change.
    $stmt = $conn->prepare('SELECT n.id, n.company_id, c.name AS company_name, '
        . 'n.link_url, n.is_read, n.subject_user_id, n.subject_appraisal_id '
        . 'FROM notifications n LEFT JOIN companies c ON c.id = n.company_id '
        . 'WHERE n.user_id = ? AND n.id = ?' . $scope['sql'] . ' LIMIT 1');
    if (!$stmt) throw new Exception('Database error: ' . $conn->error, 500);
    $params = array_merge([(int)$userData['id'], $notificationId], array_slice($scope['params'], 1));
    $stmt->bind_param('ii' . substr($scope['types'], 1), ...$params);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$item) {
        throw new Exception('This notification is no longer available to your account.', 403);
    }
    echo json_encode([
        'status' => 'Success',
        'data' => $item,
        'message' => 'Notification verified successfully.',
    ]);
} catch (Throwable $error) {
    $code = (int)$error->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($code);
    echo json_encode(['status' => 'Failed', 'message' => $error->getMessage()]);
}
