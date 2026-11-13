<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/CompanyNotificationRead.php';
require_once __DIR__ . '/../utils/Notifications.php';

header('Content-Type: application/json; charset=UTF-8');

function notificationReadResponse(string $status, string $message, array $data = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]);
    exit;
}

try {
    if (!in_array($_SERVER['REQUEST_METHOD'], ['PUT', 'PATCH'], true)) {
        throw new Exception('Bad Request: Only PUT/PATCH method is allowed.', 405);
    }

    $userData = authenticateUser();
    // Grant view is sufficient for marking PERSONAL inbox items read. This
    // operation never authorizes writes to a company's appraisal records.
    resolveActingCompanyContext($userData);
    ensureNotificationsTable($conn);

    $rawBody = file_get_contents('php://input');
    $data = $rawBody !== '' ? json_decode($rawBody, true) : [];
    if ($rawBody !== '' && !is_array($data)) {
        throw new Exception('Invalid request body. Expected valid JSON.', 400);
    }

    $notificationId = isset($data['id']) ? (int) $data['id'] : 0;
    $scope = companyNotificationBindings($userData);
    // UPDATE uses the same authorized inbox predicate as list and pulse,
    // including current grant scope and recipient ownership. A revoked grant's
    // old notifications cannot be changed through this endpoint either.
    $stmt = $conn->prepare('UPDATE notifications n '
        . 'SET n.is_read = 1, n.read_at = CURRENT_TIMESTAMP '
        . 'WHERE n.user_id = ? AND n.is_read = 0'
        . ($notificationId > 0 ? ' AND n.id = ?' : '') . $scope['sql']);
    if (!$stmt) {
        throw new Exception('Unable to prepare notifications update: ' . $conn->error, 500);
    }
    // companyNotificationBindings() already starts with the recipient's ID; it is bound
    // first in this query, so drop it from the scope parameters (as GetNotificationTarget does).
    $params = array_merge([(int)$userData['id']], $notificationId > 0 ? [$notificationId] : [], array_slice($scope['params'], 1));
    $types = 'i' . ($notificationId > 0 ? 'i' : '') . substr($scope['types'], 1);
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        $message = $stmt->error ?: $conn->error;
        $stmt->close();
        throw new Exception('Unable to mark notification as read: ' . $message, 500);
    }
    $affected = (int)$stmt->affected_rows;
    $stmt->close();

    notificationReadResponse('Success', $notificationId > 0
        ? 'Notification marked as read successfully.'
        : 'All notifications marked as read successfully.', ['affected' => $affected]);
} catch (Throwable $e) {
    $code = (int)$e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    notificationReadResponse('Failed', $e->getMessage(), [], $code);
}
