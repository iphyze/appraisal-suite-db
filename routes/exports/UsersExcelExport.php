<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/CompanyExportAccess.php';
require_once __DIR__ . '/../utils/SimpleXlsx.php';
require_once __DIR__ . '/../../includes/ExportLocalization.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Bad Request: Only GET method is allowed.', 405);
    }

    $userData = requireRoles(['super_admin', 'admin']);
    $locale = exportLocale($userData);
    $actingContext = authRoleKey($userData['role'] ?? '') === 'admin'
        ? resolveActingCompanyContext($userData) : null;
    $companyScope = $actingContext !== null
        ? (int)$actingContext['company_id'] : resolveCompanyScope($userData);
    $companyClause = buildCompanyWhereClause($companyScope, 'u');
    $roleKey = authRoleKey($userData['role'] ?? '');

    $search = trim((string) ($_GET['search'] ?? ''));
    $roleFilter = trim((string) ($_GET['role'] ?? ''));
    $departmentFilter = trim((string) ($_GET['department'] ?? ''));
    $staffTypeFilter = trim((string) ($_GET['staff_type'] ?? ''));
    $statusFilter = isset($_GET['is_active']) && $_GET['is_active'] !== ''
        ? (int) $_GET['is_active']
        : null;

    $allowedSortFields = [
        'id' => 'u.id',
        'first_name' => 'u.first_name',
        'last_name' => 'u.last_name',
        'email' => 'u.email',
        'role' => 'r.name',
        'department' => 'u.department',
        'created_at' => 'u.created_at',
    ];
    $sortKey = (string) ($_GET['sortBy'] ?? 'first_name');
    $sortBy = $allowedSortFields[$sortKey] ?? 'u.first_name';
    $sortOrder = strtoupper((string) ($_GET['sortOrder'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

    $where = ['1=1'];
    $types = '';
    $params = [];

    if ($companyClause['value'] !== null) {
        $where[] = 'u.company_id = ?';
        $types .= 'i';
        $params[] = (int) $companyClause['value'];
    }

    if ($roleFilter !== '') {
        $where[] = 'LOWER(REPLACE(TRIM(r.name), \' \', \'_\')) = ?';
        $types .= 's';
        $params[] = authRoleKey($roleFilter);
    }

    if ($departmentFilter !== '') {
        $where[] = 'u.department = ?';
        $types .= 's';
        $params[] = $departmentFilter;
    }

    if ($staffTypeFilter !== '') {
        $where[] = 'u.staff_type = ?';
        $types .= 's';
        $params[] = $staffTypeFilter;
    }

    if ($statusFilter !== null) {
        $where[] = 'u.is_active = ?';
        $types .= 'i';
        $params[] = $statusFilter === 1 ? 1 : 0;
    }

    if ($roleKey === 'admin' && empty($actingContext['is_home'])) {
        // Directory XLSX is still scoped more tightly than directory name lookups.
        $grantUsers = companyExportGrantUserFilter($actingContext, (int)$userData['id'], 'u', 'r', false);
        if ($grantUsers['sql'] !== '') $where[] = substr($grantUsers['sql'], 5);
        $types .= $grantUsers['types'];
        array_push($params, ...$grantUsers['params']);
    } elseif ($roleKey === 'admin') {
        $staffScope = trim((string) ($userData['staff_scope'] ?? 'All'));
        if (in_array($staffScope, ['Local', 'Expatriate'], true)) {
            $where[] = "(
                LOWER(REPLACE(TRIM(r.name), ' ', '_')) <> 'staff'
                OR u.staff_type = ?
            )";
            $types .= 's';
            $params[] = $staffScope;
        }
    }

    if ($search !== '') {
        $where[] = "(
            u.first_name LIKE ?
            OR u.last_name LIKE ?
            OR CONCAT_WS(' ', u.first_name, u.last_name) LIKE ?
            OR COALESCE(u.email, '') LIKE ?
            OR COALESCE(u.staff_id, '') LIKE ?
            OR COALESCE(u.department, '') LIKE ?
            OR COALESCE(u.job_title, '') LIKE ?
            OR COALESCE(c.name, '') LIKE ?
        )";
        $like = '%' . $search . '%';
        $types .= 'ssssssss';
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
    }

    $sql = "
        SELECT
            u.staff_id,
            u.first_name,
            u.last_name,
            CONCAT_WS(' ', u.first_name, u.last_name) AS full_name,
            u.email,
            r.name AS role,
            u.staff_type,
            u.staff_scope,
            u.department,
            u.job_title,
            u.location,
            u.date_of_joining,
            u.is_active,
            u.must_change_password,
            u.last_login_at,
            u.created_at,
            c.code AS company_code,
            c.name AS company_name
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        INNER JOIN companies c ON c.id = u.company_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY {$sortBy} {$sortOrder}, u.last_name ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Database error: ' . $conn->error, 500);
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $approved = exportContentMap($conn, $locale, [
        ['context' => 'department', 'values' => array_column($records, 'department')],
        ['context' => 'job_title', 'values' => array_column($records, 'job_title')],
    ]);

    $headers = [
        'S/N',
        'Staff ID',
        'First Name',
        'Last Name',
        'Full Name',
        'Email',
        'Role',
        'Staff Type',
        'Staff Scope',
        'Department',
        'Job Title',
        'Location',
        'Company',
        'Company Code',
        'Date of Joining',
        'Status',
        'Password Change Required',
        'Last Login',
        'Created At',
    ];

    $rows = [];
    foreach ($records as $index => $record) {
        $rows[] = [
            $index + 1,
            $record['staff_id'] ?: '',
            $record['first_name'] ?: '',
            $record['last_name'] ?: '',
            $record['full_name'] ?: '',
            $record['email'] ?: '',
            exportLabel($locale, ucwords(str_replace('_', ' ', (string) $record['role']))),
            exportLabel($locale, $record['staff_type'] ?: ''),
            exportLabel($locale, $record['staff_scope'] ?: ''),
            exportContent($approved, 'department', $record['department'] ?: ''),
            exportContent($approved, 'job_title', $record['job_title'] ?: ''),
            $record['location'] ?: '',
            $record['company_name'] ?: '',
            $record['company_code'] ?: '',
            exportDate($locale, $record['date_of_joining'] ?: ''),
            exportLabel($locale, (int) $record['is_active'] === 1 ? 'Active' : 'Inactive'),
            exportLabel($locale, (int) $record['must_change_password'] === 1 ? 'Yes' : 'No'),
            exportDate($locale, $record['last_login_at'] ?: '', true),
            exportDate($locale, $record['created_at'] ?: '', true),
        ];
    }

    $scopeLabel = $companyScope === null ? 'All_Companies' : 'Company_' . (int) $companyScope;
    $filterSuffix = '';
    if ($roleFilter !== '') {
        $filterSuffix .= '_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $roleFilter);
    }
    if ($staffTypeFilter !== '') {
        $filterSuffix .= '_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $staffTypeFilter);
    }

    companyExportAudit($conn, $userData, $actingContext, $companyScope,
        'export_users_excel', 'users', null, count($records));
    streamSimpleXlsx(
        ($locale === 'fr' ? 'Utilisateurs_' : 'Users_') . $scopeLabel . $filterSuffix . '_' . date('Y-m-d') . '.xlsx',
        array_map(static fn($label) => exportLabel($locale, $label), $headers),
        $rows,
        exportLabel($locale, 'Users Directory')
    );
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code <= 599) ? $code : 500;
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'status' => 'Failed',
        'message' => $e->getMessage(),
    ]);
}
