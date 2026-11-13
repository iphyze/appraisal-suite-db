<?php
/**
 * Member-workspace selection, separate from Company Access / View Context.
 * A workspace can only be a current, active membership of the signed-in identity.
 */
declare(strict_types=1);

/** Pure authorization guard, also used by database-independent tests. */
function identityWorkspaceFindTarget(array $actor, array $memberships, int $targetUserId): ?array
{
    $identityId = (int)($actor['identity_id'] ?? 0);
    $sourceUserId = (int)($actor['id'] ?? 0);
    if ($identityId <= 0 || $sourceUserId <= 0 || $targetUserId <= 0
        || $targetUserId === $sourceUserId) {
        return null;
    }

    $sourceActive = false;
    $target = null;
    foreach ($memberships as $membership) {
        if ((int)($membership['identity_id'] ?? 0) !== $identityId
            || (int)($membership['is_active'] ?? 0) !== 1
            || (int)($membership['company_is_active'] ?? 0) !== 1) {
            continue;
        }
        if ((int)($membership['user_id'] ?? 0) === $sourceUserId) $sourceActive = true;
        if ((int)($membership['user_id'] ?? 0) === $targetUserId) $target = $membership;
    }
    return $sourceActive ? $target : null;
}

/** Never expose role IDs, emails, identity IDs or credentials in the switcher. */
function identityWorkspacePublicList(array $actor, array $memberships): array
{
    $identityId = (int)($actor['identity_id'] ?? 0);
    if ($identityId <= 0) return [];
    $rows = [];
    foreach ($memberships as $membership) {
        if ((int)($membership['identity_id'] ?? 0) !== $identityId
            || (int)($membership['is_active'] ?? 0) !== 1
            || (int)($membership['company_is_active'] ?? 0) !== 1) continue;
        $rows[] = [
            'user_id' => (int)$membership['user_id'],
            'company_id' => (int)$membership['company_id'],
            'company_name' => (string)$membership['company_name'],
            'company_code' => (string)($membership['company_code'] ?? ''),
            'role' => (string)$membership['role'],
            'is_current' => (int)$membership['user_id'] === (int)$actor['id'],
        ];
    }
    return $rows;
}

/** Both companies get a durable entry, in the transaction that validates the switch. */
function identityWorkspaceAuditSwitch(mysqli $db, array $source, array $target): void
{
    $statement = userIdentityPrepare($db, 'INSERT INTO audit_log
        (company_id, user_id, action, target_table, target_id, description)
        VALUES (?, ?, ?, ?, ?, ?)');
    try {
        $action = 'switch_workspace';
        $table = 'users';
        $sourceId = (int)$source['user_id'];
        $targetId = (int)$target['user_id'];
        $sourceCompany = (int)$source['company_id'];
        $targetCompany = (int)$target['company_id'];
        $sourceName = (string)$source['company_name'];
        $targetName = (string)$target['company_name'];
        $description = "Switched own workspace from {$sourceName} (member {$sourceId}) to {$targetName} (member {$targetId}).";
        $statement->bind_param('iissis', $sourceCompany, $sourceId, $action, $table, $targetId, $description);
        userIdentityExecute($statement);
        $statement->bind_param('iissis', $targetCompany, $targetId, $action, $table, $sourceId, $description);
        userIdentityExecute($statement);
    } finally {
        $statement->close();
    }
}
