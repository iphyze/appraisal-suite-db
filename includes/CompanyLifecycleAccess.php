<?php
/**
 * Transactional revocation of active cross-company grants when a company is
 * deactivated or deleted. The caller owns the transaction and must roll back
 * both the company mutation and all grant/audit updates on any failure.
 */
function companyLifecycleRevokeGrants(
    mysqli $db, array $actor, int $companyId, string $reason
): int {
    if ($companyId <= 0 || !in_array($reason, [
        'System revocation: company deactivated.',
        'System revocation: company deleted.',
    ], true)) {
        throw new InvalidArgumentException('Invalid company revocation request.');
    }

    $actorId = (int)($actor['id'] ?? 0);
    $actorHomeId = (int)($actor['company_id'] ?? 0);
    $actorHomeName = trim((string)($actor['company_name'] ?? '')) ?: 'company #' . $actorHomeId;
    $email = (string)($actor['email'] ?? 'Super Admin');

    $find = $db->prepare('SELECT id, user_id FROM admin_company_access WHERE company_id = ? AND revoked_at IS NULL FOR UPDATE');
    if (!$find) throw new Exception('Unable to lock active company grants.', 500);
    $find->bind_param('i', $companyId);
    $find->execute();
    $rows = $find->get_result()->fetch_all(MYSQLI_ASSOC);
    $find->close();

    $change = $db->prepare('UPDATE admin_company_access SET revoked_at = CURRENT_TIMESTAMP, revoked_by = ?, revoke_reason = ? WHERE id = ? AND revoked_at IS NULL');
    $audit = $db->prepare('INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description) VALUES (?, ?, ?, ?, ?, ?)');
    if (!$change || !$audit) throw new Exception('Unable to revoke/audit company grants.', 500);

    $action = 'system_revoke_company_access';
    $table = 'admin_company_access';
    foreach ($rows as $row) {
        $grantId = (int)$row['id'];
        $recipientId = (int)$row['user_id'];
        $change->bind_param('isi', $actorId, $reason, $grantId);
        if (!$change->execute() || $change->affected_rows !== 1) {
            throw new Exception('Unable to revoke an active company grant.', 500);
        }
        // audit_log.target_id is INT UNSIGNED while grant IDs are BIGINT.
        $auditTargetId = $grantId <= 4294967295 ? $grantId : null;
        $description = "{$email} system-revoked grant #{$grantId} for admin #{$recipientId}. "
            . "Reason: {$reason} Actor home company: {$actorHomeName} (#{$actorHomeId})";
        $audit->bind_param('iissis', $companyId, $actorId, $action, $table, $auditTargetId, $description);
        if (!$audit->execute()) throw new Exception('Unable to audit company grant revocation.', 500);
    }
    $change->close();
    $audit->close();
    return count($rows);
}
