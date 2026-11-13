<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/CompanyNotificationRead.php';
require_once __DIR__ . '/../utils/Notifications.php';
require_once 'includes/NotificationLocalization.php';

header('Content-Type: application/json');
header('Cache-Control: private, no-store');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Bad Request: Only GET method is allowed', 405);
    }

    $userData = authenticateUser();
    // Respect a selected acting company, including immediate revocation/403.
    // The personal inbox itself continues showing ALL authorized companies.
    resolveActingCompanyContext($userData);
    ensureNotificationsTable($conn);

    $status = strtolower(trim((string)($_GET['status'] ?? 'all')));
    $status = in_array($status, ['all', 'unread', 'read'], true) ? $status : 'all';
    $page = max((int)($_GET['page'] ?? 1), 1);
    $limit = max(1, min((int)($_GET['limit'] ?? 20), 100));
    $offset = ($page - 1) * $limit;
    $statusWhere = $status === 'unread' ? ' AND n.is_read = 0' : ($status === 'read' ? ' AND n.is_read = 1' : '');

    $scope = companyNotificationBindings($userData);
    $sqlWhere = ' WHERE n.user_id = ?' . $scope['sql'];
    $filteredStmt = $conn->prepare('SELECT COUNT(*) AS total FROM notifications n' . $sqlWhere . $statusWhere);
    if (!$filteredStmt) throw new Exception('Database error: ' . $conn->error, 500);
    $filteredStmt->bind_param($scope['types'], ...$scope['params']);
    $filteredStmt->execute();
    $filteredTotal = (int)($filteredStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $filteredStmt->close();

    $totalPages = max((int)ceil($filteredTotal / $limit), 1);
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $limit;
    }

    $stmt = $conn->prepare('SELECT n.id, n.company_id, c.name AS company_name, c.code AS company_code, '
        . 'n.type, n.title, n.message, n.message_key, n.message_params, n.link_url, n.subject_user_id, n.subject_appraisal_id, n.is_read, n.created_at, n.read_at '
        . 'FROM notifications n LEFT JOIN companies c ON c.id = n.company_id'
        . $sqlWhere . $statusWhere . ' ORDER BY n.created_at DESC, n.id DESC LIMIT ? OFFSET ?');
    if (!$stmt) throw new Exception('Database error: ' . $conn->error, 500);
    $stmt->bind_param($scope['types'] . 'ii', ...array_merge($scope['params'], [$limit, $offset]));
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $rows = appNotificationLocalizeRows($rows, appLocaleForResponse($userData));

    $summaryStmt = $conn->prepare('SELECT COUNT(*) AS total, '
        . 'COALESCE(SUM(CASE WHEN n.is_read = 0 THEN 1 ELSE 0 END), 0) AS unread, '
        . 'COALESCE(SUM(CASE WHEN n.is_read = 1 THEN 1 ELSE 0 END), 0) AS read_count '
        . 'FROM notifications n' . $sqlWhere);
    if (!$summaryStmt) throw new Exception('Database error: ' . $conn->error, 500);
    $summaryStmt->bind_param($scope['types'], ...$scope['params']);
    $summaryStmt->execute();
    $summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
    $summaryStmt->close();

    echo json_encode([
        'status' => 'Success',
        'message' => 'Notifications fetched successfully',
        'data' => $rows,
        'meta' => [
            'total' => (int)($summary['total'] ?? 0),
            'unread' => (int)($summary['unread'] ?? 0),
            'read' => (int)($summary['read_count'] ?? 0),
            'filtered_total' => $filteredTotal,
            'filter' => $status,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => $totalPages,
        ],
    ]);
} catch (Exception $e) {
    $code = $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($code);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
