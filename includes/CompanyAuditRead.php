<?php
/**
 * Audit Log tenant isolation. Grant-scoped records may include free-text
 * descriptions of other employees' appraisals, so a foreign-company grantee
 * can read their OWN audit actions only. Home-company and Super Admin
 * visibility remain exactly as before.
 */
require_once __DIR__ . '/CompanyAccess.php';

function companyAuditReadWhere(array $actor, array $context): array
{
    $companyId = $context['company_id'] ?? null;
    if ($companyId === null) {
        if (companyAccessRoleKey($actor['role_key'] ?? $actor['role'] ?? '') !== 'super_admin') {
            throw companyAccessForbidden();
        }
        return ['sql' => '', 'types' => '', 'params' => []];
    }
    $companyId = (int)$companyId;
    if ($companyId < 1) throw companyAccessForbidden();
    $parts = ['a.company_id = ?'];
    $params = [$companyId];
    $types = 'i';
    if (empty($context['is_home']) && ($context['access_level'] ?? null) !== 'full') {
        if (!in_array($context['access_level'] ?? null, ['view', 'manage'], true)) {
            throw companyAccessForbidden();
        }
        $actorId = (int)($actor['id'] ?? 0);
        if ($actorId < 1) throw companyAccessForbidden();
        // Free-text audit rows have no trustworthy staff_type or appraisee key.
        // Never expose other actors' notes to a restricted/peer-blind grant.
        $parts[] = 'a.user_id = ?';
        $params[] = $actorId;
        $types .= 'i';
    }
    return ['sql' => implode(' AND ', $parts), 'types' => $types, 'params' => $params];
}
