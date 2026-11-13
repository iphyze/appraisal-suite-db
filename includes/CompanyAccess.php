<?php

/**
 * The acting company is a permission decision made on the server, NOT a value
 * trusted from the browser. This file is deliberately not wired into the old
 * resolveCompanyScope() yet: Batch 4/5 must guard all reads/writes first.
 *
 * Pass the authenticated user returned by authenticateUser() (which reloads
 * the current role and account state on every request). Cross-company grant
 * checks ALSO query the database on each invocation: no JWT/session caching.
 *
 * Personal and supervisor-owned actions must remain record-company scoped;
 * those endpoints must NOT use this acting-company context (Batch 4/5).
 */

function companyAccessRoleKey($role): string
{
    return strtolower(str_replace(' ', '_', trim((string) $role)));
}

function companyAccessForbidden(): Exception
{
    return new Exception('You do not have access to that company.', 403);
}

/**
 * For non-Super Admins, a malformed company selector is denied rather than
 * silently falling back to the home company. Zero/blank mean no selection.
 * Super Admin retains the historical integer-cast selector behaviour.
 */
function companyAccessRequestedId($raw, bool $superAdmin): ?int
{
    if ($raw === null || $raw === '') {
        return null;
    }

    if ($superAdmin) {
        return is_scalar($raw) && (int) $raw > 0 ? (int) $raw : null;
    }

    if ((!is_int($raw) && !is_string($raw))
        || !preg_match('/^[0-9]+$/D', (string) $raw)) {
        throw companyAccessForbidden();
    }

    $id = filter_var((string) $raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($id === false) {
        throw companyAccessForbidden();
    }

    return $id > 0 ? $id : null;
}

function companyAccessHomeContext(array $userData, int $homeId): array
{
    $scope = (string) ($userData['staff_scope'] ?? 'All');
    if (!in_array($scope, ['All', 'Local', 'Expatriate'], true)) {
        $scope = 'All';
    }

    return [
        'company_id' => $homeId,
        'is_home' => true,
        'access_level' => 'full',
        'staff_scope' => $scope,
        'grant_id' => null,
        'company_name' => (string) ($userData['company_name'] ?? ''),
    ];
}

/**
 * The join rechecks both role and active flags against the live database.
 * A stale user object cannot turn a revoked grant or demoted user into access.
 */
function companyAccessFetchActiveGrant(mysqli $database, int $userId, int $companyId): ?array
{
    $stmt = $database->prepare("
        SELECT
            aca.id, aca.company_id, aca.access_level, aca.staff_scope,
            aca.receive_notifications, c.name AS company_name
        FROM admin_company_access aca
        INNER JOIN companies c ON c.id = aca.company_id AND c.is_active = 1
        INNER JOIN users u ON u.id = aca.user_id AND u.is_active = 1
        INNER JOIN roles r ON r.id = u.role_id
        WHERE aca.user_id = ?
          AND aca.company_id = ?
          AND aca.revoked_at IS NULL
          AND aca.company_id <> u.company_id
          AND LOWER(REPLACE(TRIM(r.name), ' ', '_')) = 'admin'
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception('Unable to check company access.', 500);
    }

    $stmt->bind_param('ii', $userId, $companyId);
    $stmt->execute();
    $grant = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $grant;
}

/**
 * Resolve the acting company for a request.
 *
 * $requestedCompanyId defaults to the query-string view_company_id. The
 * optional reader permits isolated unit testing; production callers omit it.
 *
 * A Super Admin with no selection has company_id = null, preserving the
 * existing all-company view. An Admin's home company is always full access.
 */
function resolveActingCompanyContext(
    array $userData,
    $requestedCompanyId = null,
    ?callable $grantReader = null
): array {
    $role = companyAccessRoleKey($userData['role_key'] ?? $userData['role'] ?? '');
    $homeId = (int) ($userData['company_id'] ?? 0);

    if (array_key_exists('is_active', $userData) && (int) $userData['is_active'] !== 1) {
        throw new Exception('Unauthorized: This account is unavailable.', 401);
    }

    // Other roles historically ignore any company selector, including invalid
    // values. Do not change that behaviour.
    if ($role !== 'admin' && $role !== 'super_admin') {
        if ($homeId <= 0) {
            throw new Exception('Unauthorized: No company has been assigned to this account.', 403);
        }
        return companyAccessHomeContext($userData, $homeId);
    }

    $rawRequested = $requestedCompanyId ?? ($_GET['view_company_id'] ?? null);
    $selectedId = companyAccessRequestedId($rawRequested, $role === 'super_admin');

    if ($role === 'super_admin') {
        return [
            'company_id' => $selectedId,
            'is_home' => $selectedId !== null && $selectedId === $homeId,
            'access_level' => 'full',
            'staff_scope' => 'All',
            'grant_id' => null,
            'company_name' => $selectedId === $homeId
                ? (string) ($userData['company_name'] ?? '') : '',
        ];
    }

    if ($homeId <= 0) {
        throw new Exception('Unauthorized: No company has been assigned to this account.', 403);
    }

    if ($selectedId === null || $selectedId === $homeId) {
        return companyAccessHomeContext($userData, $homeId);
    }

    if ($grantReader !== null) {
        $grant = $grantReader((int) ($userData['id'] ?? 0), $selectedId);
    } else {
        global $conn;
        if (!($conn instanceof mysqli)) {
            throw new Exception('Unable to check company access.', 500);
        }
        $grant = companyAccessFetchActiveGrant($conn, (int) ($userData['id'] ?? 0), $selectedId);
    }

    if (!is_array($grant)
        || (int) ($grant['id'] ?? 0) <= 0
        || (int) ($grant['company_id'] ?? 0) !== $selectedId
        || !empty($grant['revoked_at'])
        || !in_array($grant['access_level'] ?? null, ['view', 'manage'], true)
        || !in_array($grant['staff_scope'] ?? null, ['All', 'Local', 'Expatriate'], true)) {
        throw companyAccessForbidden();
    }

    return [
        'company_id' => $selectedId,
        'is_home' => false,
        'access_level' => $grant['access_level'],
        'staff_scope' => $grant['staff_scope'],
        'grant_id' => (int) $grant['id'],
        'company_name' => (string) ($grant['company_name'] ?? ''),
    ];
}

/**
 * View-only grants can read and export, but cannot modify company data.
 * Marking the acting user's OWN inbox notification read is a separate personal
 * operation and must be guarded by ownership rather than this write guard.
 */
function requireCompanyWriteAccess(array $context): void
{
    $level = $context['access_level'] ?? null;
    if ($level === 'full' || $level === 'manage') {
        return;
    }
    if ($level === 'view') {
        $name = trim((string) ($context['company_name'] ?? ''));
        if ($name === '') {
            $name = 'this company';
        }
        throw new Exception("You have view-only access to {$name}.", 403);
    }
    throw companyAccessForbidden();
}

/**
 * Strict scope for APPRAISEES within a granted company, regardless of whether
 * the appraisee is staff, supervisor or admin. The appraisal's staff_type
 * snapshot or the appraisee user's staff_type is the filter target.
 *
 * Home-company filtering is deliberately unchanged; only granted-company
 * contexts receive this additional predicate. NULL staff_type will not match
 * a Local/Expatriate grant (fail closed). Directory lists are separate.
 */
function companyAccessAppraiseeScopeWhere(array $context, string $alias = ''): array
{
    if ($alias !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
        throw new InvalidArgumentException('Invalid SQL alias provided.');
    }

    if (!empty($context['is_home']) || ($context['access_level'] ?? null) === 'full') {
        return ['sql' => '', 'type' => '', 'value' => null];
    }

    if (!in_array($context['access_level'] ?? null, ['view', 'manage'], true)) {
        throw companyAccessForbidden();
    }

    $scope = $context['staff_scope'] ?? null;
    if ($scope === 'All') {
        return ['sql' => '', 'type' => '', 'value' => null];
    }
    if (!in_array($scope, ['Local', 'Expatriate'], true)) {
        throw companyAccessForbidden();
    }

    $column = $alias !== '' ? "{$alias}.staff_type" : 'staff_type';
    return ['sql' => " AND {$column} = ?", 'type' => 's', 'value' => $scope];
}

/**
 * Switcher choices: home + active grants for Admin; home for staff/supervisor;
 * all active companies for Super Admin. Re-query live for every call.
 */
function listAccessibleCompanies(array $userData, ?mysqli $database = null): array
{
    $database = $database ?? ($GLOBALS['conn'] ?? null);
    if (!($database instanceof mysqli)) {
        throw new Exception('Unable to list accessible companies.', 500);
    }

    if (array_key_exists('is_active', $userData) && (int) $userData['is_active'] !== 1) {
        throw new Exception('Unauthorized: This account is unavailable.', 401);
    }

    $role = companyAccessRoleKey($userData['role_key'] ?? $userData['role'] ?? '');
    $userId = (int) ($userData['id'] ?? 0);

    if ($role === 'super_admin') {
        $stmt = $database->prepare('SELECT id, name, code FROM companies WHERE is_active = 1 ORDER BY name ASC');
        if (!$stmt) {
            throw new Exception('Unable to list accessible companies.', 500);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return array_map(static function (array $row) use ($userData): array {
            $id = (int) $row['id'];
            return [
                'company_id' => $id, 'name' => $row['name'], 'code' => $row['code'],
                'is_home' => $id === (int) ($userData['company_id'] ?? 0),
                'access_level' => 'full', 'staff_scope' => 'All', 'grant_id' => null,
            ];
        }, $rows);
    }

    // Fetch the home company from the current DB state rather than trusting
    // display names/codes in the incoming user object.
    $home = $database->prepare('
        SELECT c.id, c.name, c.code, u.staff_scope, r.name AS role_name
        FROM users u
        INNER JOIN companies c ON c.id = u.company_id
        INNER JOIN roles r ON r.id = u.role_id
        WHERE u.id = ? AND u.is_active = 1
        LIMIT 1
    ');
    if (!$home) {
        throw new Exception('Unable to list accessible companies.', 500);
    }
    $home->bind_param('i', $userId);
    $home->execute();
    $homeRow = $home->get_result()->fetch_assoc() ?: null;
    $home->close();
    if (!$homeRow) {
        throw new Exception('Unauthorized: This account is unavailable.', 401);
    }

    $homeId = (int) $homeRow['id'];
    $freshRole = companyAccessRoleKey($homeRow['role_name']);
    $homeContext = companyAccessHomeContext([
        'company_name' => $homeRow['name'],
        'staff_scope' => $homeRow['staff_scope'],
    ], $homeId);
    $list = [[
        'company_id' => $homeId,
        'name' => $homeRow['name'],
        'code' => $homeRow['code'],
        'is_home' => true,
        'access_level' => 'full',
        'staff_scope' => $homeContext['staff_scope'],
        'grant_id' => null,
    ]];

    if ($role !== 'admin' || $freshRole !== 'admin') {
        return $list;
    }

    $stmt = $database->prepare("
        SELECT aca.id AS grant_id, aca.company_id, aca.access_level, aca.staff_scope,
               c.name, c.code
        FROM admin_company_access aca
        INNER JOIN companies c ON c.id = aca.company_id AND c.is_active = 1
        INNER JOIN users u ON u.id = aca.user_id AND u.is_active = 1
        INNER JOIN roles r ON r.id = u.role_id
        WHERE aca.user_id = ? AND aca.revoked_at IS NULL
          AND aca.company_id <> u.company_id
          AND LOWER(REPLACE(TRIM(r.name), ' ', '_')) = 'admin'
        ORDER BY c.name ASC
    ");
    if (!$stmt) {
        throw new Exception('Unable to list accessible companies.', 500);
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as $row) {
        if (!in_array($row['access_level'], ['view', 'manage'], true)
            || !in_array($row['staff_scope'], ['All', 'Local', 'Expatriate'], true)) {
            continue; // Fail closed for malformed grant data.
        }
        $list[] = [
            'company_id' => (int) $row['company_id'],
            'name' => $row['name'], 'code' => $row['code'],
            'is_home' => false, 'access_level' => $row['access_level'],
            'staff_scope' => $row['staff_scope'], 'grant_id' => (int) $row['grant_id'],
        ];
    }

    return $list;
}
