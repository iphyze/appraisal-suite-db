<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/CompanyAuditRead.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Exception('Bad Request: Only GET method is allowed', 405);
    $userData = requireRoles(['super_admin', 'admin']);
    $context = resolveActingCompanyContext($userData);
    $filter = companyAuditReadWhere($userData, $context);
    $page = isset($_GET['page']) ? max((int)$_GET['page'], 1) : 1;
    $limit = max(10, min((int)($_GET['limit'] ?? 20), 100));
    $offset = ($page - 1) * $limit;
    $search = trim((string)($_GET['search'] ?? ''));
    if (strlen($search) > 200) throw new Exception('Search text is too long.', 422);

    $conditions = [];
    if ($filter['sql'] !== '') $conditions[] = $filter['sql'];
    $types = $filter['types'];
    $params = $filter['params'];
    if ($search !== '') {
        $conditions[] = '(a.action LIKE ? OR a.description LIKE ? OR u.email LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like);
        $types .= 'sss';
    }
    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $from = 'FROM audit_log a LEFT JOIN users u ON u.id = a.user_id';
    $count = $conn->prepare("SELECT COUNT(*) AS total {$from} {$where}");
    if (!$count) throw new Exception('Unable to load audit log.', 500);
    if ($types !== '') $count->bind_param($types, ...$params);
    $count->execute();
    $total = (int)($count->get_result()->fetch_assoc()['total'] ?? 0);
    $count->close();

    $sql = "SELECT a.id, a.company_id, c.name AS company_name, a.user_id,
        TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS user_name,
        u.email AS user_email, a.action, a.target_table, a.target_id,
        a.description, a.ip_address, a.created_at
        {$from} LEFT JOIN companies c ON c.id = a.company_id
        {$where} ORDER BY a.created_at DESC, a.id DESC LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Unable to load audit log.', 500);
    $params[] = $limit;
    $params[] = $offset;
    $stmt->bind_param($types . 'ii', ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    header('Cache-Control: private, no-store, max-age=0');
    echo json_encode(['status' => 'Success', 'message' => 'Audit log fetched successfully',
        'data' => $rows, 'meta' => ['total' => $total, 'page' => $page,
            'limit' => $limit, 'total_pages' => max(1, (int)ceil($total / $limit))]]);
} catch (Throwable $e) {
    $code = (int)$e->getCode();
    http_response_code($code >= 400 && $code <= 599 ? $code : 500);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
