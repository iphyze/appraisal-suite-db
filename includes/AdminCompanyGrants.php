<?php
/**
 * Super Admin grant administration (Batch 3).
 * All grant mutations and their audit_log entries are one transaction.
 * Existing company-scoped endpoints are intentionally unchanged until Batches 4/5.
 */

function acgPositiveId($value, string $field): int
{
    if ((!is_int($value) && !is_string($value))
        || !preg_match('/^[1-9][0-9]*$/D', (string)$value)) {
        throw new Exception("Field '{$field}' must be a positive integer.", 422);
    }
    $id = filter_var((string)$value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) throw new Exception("Field '{$field}' must be a positive integer.", 422);
    return $id;
}

function acgOptionalId($value, string $field): int
{
    if ($value === null || $value === '' || $value === 0 || $value === '0') return 0;
    return acgPositiveId($value, $field);
}

function acgReason($value): string
{
    if (!is_string($value)) throw new Exception('A reason of 5–500 characters is required.', 422);
    $reason = trim($value);
    if (!preg_match('//u', $reason)) throw new Exception('Reason must be valid UTF-8.', 422);
    // Unicode codepoints, matching MariaDB CHAR_LENGTH for VARCHAR(500).
    $length = preg_match_all('/[\s\S]/u', $reason);
    if ($length < 5 || $length > 500) {
        throw new Exception('A reason of 5–500 characters is required.', 422);
    }
    return $reason;
}

function acgNotificationsFlag($value): int
{
    if (!in_array($value, [0, 1, '0', '1', false, true], true)) {
        throw new Exception("Field 'receive_notifications' must be a boolean.", 422);
    }
    return (int)(bool)$value;
}

function acgGrantSettings(array $input, bool $creating): array
{
    $allowed = ['access_level', 'staff_scope', 'receive_notifications'];
    $settings = $creating
        ? ['access_level' => 'view', 'staff_scope' => 'All', 'receive_notifications' => 0]
        : [];

    foreach ($allowed as $field) {
        if (!array_key_exists($field, $input)) continue;
        $value = $input[$field];
        if ($field === 'access_level') {
            if (!is_string($value) || !in_array($value, ['view', 'manage'], true)) {
                throw new Exception("Field 'access_level' must be 'view' or 'manage'.", 422);
            }
        } elseif ($field === 'staff_scope') {
            if (!is_string($value) || !in_array($value, ['All', 'Local', 'Expatriate'], true)) {
                throw new Exception("Field 'staff_scope' must be All, Local or Expatriate.", 422);
            }
        } else {
            $value = acgNotificationsFlag($value);
        }
        $settings[$field] = $value;
    }

    if (!$creating && !$settings) {
        throw new Exception('Supply at least one grant setting to change.', 422);
    }
    return $settings;
}

function acgAssertEligibleRecipient(array $recipient, array $company): void
{
    if ((int)($recipient['is_active'] ?? 0) !== 1
        || strtolower(str_replace(' ', '_', trim((string)($recipient['role_name'] ?? '')))) !== 'admin') {
        throw new Exception('Only active admins can receive company access.', 422);
    }
    if ((int)($company['is_active'] ?? 0) !== 1) {
        throw new Exception('The target company must be active.', 422);
    }
    if ((int)($recipient['company_id'] ?? 0) === (int)($company['id'] ?? 0)) {
        throw new Exception('An admin already has full access to their home company.', 422);
    }
}

function acgUpdateDelta(array $current, array $updates): array
{
    $before = [];
    $after = [];
    foreach ($updates as $key => $newValue) {
        $oldValue = $key === 'receive_notifications'
            ? (int)$current[$key]
            : (string)$current[$key];
        if ($oldValue !== $newValue) {
            $before[$key] = $oldValue;
            $after[$key] = $newValue;
        }
    }
    if (!$after) throw new Exception('No grant setting changed.', 422);
    return ['before' => $before, 'after' => $after];
}

/** Fetch and lock both records during a create/update transaction. */
function acgLockRecipientAndCompany(mysqli $conn, int $userId, int $companyId): array
{
    $stmt = $conn->prepare('SELECT u.id, u.company_id, u.is_active, u.email, u.fullname,
                                  r.name AS role_name
                           FROM users u INNER JOIN roles r ON r.id = u.role_id
                           WHERE u.id = ? FOR UPDATE');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $recipient = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$recipient) throw new Exception('Administrator not found.', 404);

    $stmt = $conn->prepare('SELECT id, name, code, is_active FROM companies WHERE id = ? FOR UPDATE');
    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $company = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$company) throw new Exception('Company not found.', 404);
    acgAssertEligibleRecipient($recipient, $company);
    return [$recipient, $company];
}

/** Read grant identifiers before locks, to keep lock ordering consistent. */
function acgGrantTarget(mysqli $conn, int $grantId): array
{
    $stmt = $conn->prepare('SELECT user_id, company_id FROM admin_company_access WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $grantId);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$target) throw new Exception('Company access grant not found.', 404);
    return $target;
}

function acgLockGrant(mysqli $conn, int $grantId): array
{
    $stmt = $conn->prepare('SELECT id, user_id, company_id, access_level, staff_scope,
                                  receive_notifications, revoked_at
                           FROM admin_company_access WHERE id = ? FOR UPDATE');
    $stmt->bind_param('i', $grantId);
    $stmt->execute();
    $grant = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$grant) throw new Exception('Company access grant not found.', 404);
    if ($grant['revoked_at'] !== null) throw new Exception('This company access grant is already revoked.', 409);
    return $grant;
}

function acgAudit(mysqli $conn, array $actor, int $targetCompanyId, int $grantId,
                  string $action, string $details, string $reason): void
{
    $actorId = (int)$actor['id'];
    $actorHome = trim((string)($actor['company_name'] ?? ''));
    if ($actorHome === '') $actorHome = 'company #' . (int)($actor['company_id'] ?? 0);
    $actorEmail = (string)($actor['email'] ?? 'Super Admin');
    $description = "{$actorEmail} (home company: {$actorHome}, #" . (int)($actor['company_id'] ?? 0)
        . ") {$details}. Reason: {$reason}";
    $targetTable = 'admin_company_access';
    // audit_log.target_id is INT UNSIGNED; grant PK is BIGINT UNSIGNED.
    // Preserve its full ID in the description if the audit column cannot hold it.
    $targetId = $grantId <= 4294967295 ? $grantId : null;
    $stmt = $conn->prepare('INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
                            VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('iissis', $targetCompanyId, $actorId, $action, $targetTable, $targetId, $description);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception('Unable to record company grant audit.', 500);
    }
    $stmt->close();
}

function acgReadGrant(mysqli $conn, int $grantId): array
{
    $stmt = $conn->prepare("SELECT g.id, g.user_id, g.company_id, g.access_level, g.staff_scope,
        g.receive_notifications, g.reason, g.granted_by, g.created_at, g.updated_at,
        g.revoked_at, g.revoked_by, g.revoke_reason,
        u.fullname AS user_name, u.email AS user_email, u.is_active AS user_is_active,
        u.company_id AS home_company_id, hc.name AS home_company_name, hc.code AS home_company_code,
        c.name AS company_name, c.code AS company_code, c.is_active AS company_is_active,
        gb.fullname AS granted_by_name, gb.email AS granted_by_email,
        rb.fullname AS revoked_by_name, rb.email AS revoked_by_email
      FROM admin_company_access g
      LEFT JOIN users u ON u.id = g.user_id
      LEFT JOIN companies hc ON hc.id = u.company_id
      LEFT JOIN companies c ON c.id = g.company_id
      LEFT JOIN users gb ON gb.id = g.granted_by
      LEFT JOIN users rb ON rb.id = g.revoked_by
      WHERE g.id = ? LIMIT 1");
    $stmt->bind_param('i', $grantId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$row) throw new Exception('Company access grant not found.', 404);
    return acgFormatGrant($row);
}

function acgFormatGrant(array $row): array
{
    foreach (['id', 'user_id', 'company_id', 'granted_by', 'home_company_id'] as $key) {
        $row[$key] = array_key_exists($key, $row) && $row[$key] !== null ? (int)$row[$key] : null;
    }
    $row['revoked_by'] = $row['revoked_by'] !== null ? (int)$row['revoked_by'] : null;
    $row['receive_notifications'] = (bool)$row['receive_notifications'];
    $row['is_active'] = $row['revoked_at'] === null;
    if (array_key_exists('user_is_active', $row)) $row['user_is_active'] = (bool)$row['user_is_active'];
    if (array_key_exists('company_is_active', $row)) $row['company_is_active'] = (bool)$row['company_is_active'];
    return $row;
}

/** All rows including history; no user-supplied SQL fragments. */
function acgListGrants(mysqli $conn, string $status, int $userId, int $companyId,
                       int $page, int $limit): array
{
    $conditions = ['all' => '', 'active' => ' AND g.revoked_at IS NULL',
                   'revoked' => ' AND g.revoked_at IS NOT NULL'];
    if (!array_key_exists($status, $conditions)) throw new Exception('Invalid grant status filter.', 422);
    $filter = $conditions[$status];
    $where = 'WHERE (? = 0 OR g.user_id = ?) AND (? = 0 OR g.company_id = ?)' . $filter;
    $count = $conn->prepare("SELECT COUNT(*) AS total FROM admin_company_access g {$where}");
    $count->bind_param('iiii', $userId, $userId, $companyId, $companyId);
    $count->execute();
    $total = (int)($count->get_result()->fetch_assoc()['total'] ?? 0);
    $count->close();
    $offset = ($page - 1) * $limit;
    $stmt = $conn->prepare("SELECT g.id, g.user_id, g.company_id, g.access_level, g.staff_scope,
        g.receive_notifications, g.reason, g.granted_by, g.created_at, g.updated_at,
        g.revoked_at, g.revoked_by, g.revoke_reason,
        u.fullname AS user_name, u.email AS user_email, u.is_active AS user_is_active,
        u.company_id AS home_company_id, hc.name AS home_company_name, hc.code AS home_company_code,
        c.name AS company_name, c.code AS company_code, c.is_active AS company_is_active,
        gb.fullname AS granted_by_name, gb.email AS granted_by_email,
        rb.fullname AS revoked_by_name, rb.email AS revoked_by_email
      FROM admin_company_access g
      LEFT JOIN users u ON u.id = g.user_id
      LEFT JOIN companies hc ON hc.id = u.company_id
      LEFT JOIN companies c ON c.id = g.company_id
      LEFT JOIN users gb ON gb.id = g.granted_by
      LEFT JOIN users rb ON rb.id = g.revoked_by
      {$where}
      ORDER BY (g.revoked_at IS NULL) DESC, g.created_at DESC, g.id DESC
      LIMIT ? OFFSET ?");
    $stmt->bind_param('iiiiii', $userId, $userId, $companyId, $companyId, $limit, $offset);
    $stmt->execute();
    $rows = array_map('acgFormatGrant', $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();
    return ['grants' => $rows, 'meta' => [
        'total' => $total, 'page' => $page, 'limit' => $limit,
        'total_pages' => max(1, (int)ceil($total / $limit)),
    ]];
}

function acgEligibleAdmins(mysqli $conn, int $homeCompanyId = 0): array
{
    $stmt = $conn->prepare("SELECT u.id, u.fullname AS name, u.email, u.company_id AS home_company_id,
                                  c.name AS home_company_name, c.code AS home_company_code
        FROM users u INNER JOIN roles r ON r.id = u.role_id
        INNER JOIN companies c ON c.id = u.company_id AND c.is_active = 1
        WHERE u.is_active = 1 AND LOWER(REPLACE(TRIM(r.name), ' ', '_')) = 'admin'
          AND (? = 0 OR u.company_id = ?)
        ORDER BY c.name ASC, u.fullname ASC, u.id ASC");
    $stmt->bind_param('ii', $homeCompanyId, $homeCompanyId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['home_company_id'] = (int)$row['home_company_id'];
    }
    unset($row);
    return $rows;
}

function acgCreateGrant(mysqli $conn, array $actor, array $input): array
{
    $userId = acgPositiveId($input['user_id'] ?? null, 'user_id');
    $companyId = acgPositiveId($input['company_id'] ?? null, 'company_id');
    $reason = acgReason($input['reason'] ?? null);
    $settings = acgGrantSettings($input, true);
    $grantId = 0;
    $conn->begin_transaction();
    try {
        [$recipient, $company] = acgLockRecipientAndCompany($conn, $userId, $companyId);
        $check = $conn->prepare('SELECT id FROM admin_company_access
                                WHERE user_id = ? AND company_id = ? AND revoked_at IS NULL LIMIT 1 FOR UPDATE');
        $check->bind_param('ii', $userId, $companyId);
        $check->execute();
        $duplicate = (bool)$check->get_result()->fetch_assoc();
        $check->close();
        if ($duplicate) throw new Exception('This admin already has active access to that company.', 409);

        $stmt = $conn->prepare('INSERT INTO admin_company_access
          (user_id, company_id, access_level, staff_scope, receive_notifications, granted_by, reason)
          VALUES (?, ?, ?, ?, ?, ?, ?)');
        $actorId = (int)$actor['id'];
        $stmt->bind_param('iissiis', $userId, $companyId, $settings['access_level'],
                           $settings['staff_scope'], $settings['receive_notifications'], $actorId, $reason);
        $stmt->execute();
        $grantId = (int)$conn->insert_id;
        $stmt->close();
        $details = "granted company access #{$grantId} to {$recipient['fullname']} (#{$userId})"
            . " for {$company['name']} (#{$companyId}), level={$settings['access_level']},"
            . " scope={$settings['staff_scope']}, notifications={$settings['receive_notifications']}";
        acgAudit($conn, $actor, $companyId, $grantId, 'create_admin_company_access', $details, $reason);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        if ((int)$e->getCode() === 1062) {
            throw new Exception('This admin already has active access to that company.', 409);
        }
        throw $e;
    }
    return acgReadGrant($conn, $grantId);
}

function acgUpdateGrant(mysqli $conn, array $actor, array $input): array
{
    $grantId = acgPositiveId($input['grant_id'] ?? null, 'grant_id');
    $reason = acgReason($input['reason'] ?? null);
    $updates = acgGrantSettings($input, false);
    $conn->begin_transaction();
    try {
        $target = acgGrantTarget($conn, $grantId);
        [$recipient, $company] = acgLockRecipientAndCompany($conn, (int)$target['user_id'], (int)$target['company_id']);
        $grant = acgLockGrant($conn, $grantId);
        if ((int)$grant['user_id'] !== (int)$target['user_id']
            || (int)$grant['company_id'] !== (int)$target['company_id']) {
            throw new Exception('The company access grant changed. Please retry.', 409);
        }
        $delta = acgUpdateDelta($grant, $updates);
        $next = array_merge($grant, $delta['after']);
        $stmt = $conn->prepare('UPDATE admin_company_access
          SET access_level = ?, staff_scope = ?, receive_notifications = ?, updated_at = UTC_TIMESTAMP()
          WHERE id = ? AND revoked_at IS NULL');
        $stmt->bind_param('ssii', $next['access_level'], $next['staff_scope'], $next['receive_notifications'], $grantId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) throw new Exception('Grant update failed.', 409);
        $stmt->close();
        $detail = "updated company access #{$grantId} for {$recipient['fullname']}"
            . " (#{$recipient['id']}) in {$company['name']} (#{$company['id']}): "
            . json_encode(['before' => $delta['before'], 'after' => $delta['after']], JSON_UNESCAPED_UNICODE);
        acgAudit($conn, $actor, (int)$grant['company_id'], $grantId, 'update_admin_company_access', $detail, $reason);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    return acgReadGrant($conn, $grantId);
}

function acgRevokeGrant(mysqli $conn, array $actor, array $input): array
{
    $grantId = acgPositiveId($input['grant_id'] ?? null, 'grant_id');
    $reason = acgReason($input['reason'] ?? null);
    $conn->begin_transaction();
    try {
        $grant = acgLockGrant($conn, $grantId);
        $actorId = (int)$actor['id'];
        $stmt = $conn->prepare('UPDATE admin_company_access
           SET revoked_at = UTC_TIMESTAMP(), revoked_by = ?, revoke_reason = ?, updated_at = UTC_TIMESTAMP()
           WHERE id = ? AND revoked_at IS NULL');
        $stmt->bind_param('isi', $actorId, $reason, $grantId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) throw new Exception('Grant revocation failed.', 409);
        $stmt->close();
        acgAudit($conn, $actor, (int)$grant['company_id'], $grantId,
                 'revoke_admin_company_access',
                 "revoked company access #{$grantId} for user #{$grant['user_id']} in company #{$grant['company_id']}",
                 $reason);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    return acgReadGrant($conn, $grantId);
}
