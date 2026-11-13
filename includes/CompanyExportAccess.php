<?php
/**
 * Server-side filters and audit for conventional XLSX exports.
 *
 * Does not alter legacy home-company staff_scope or supervisor exports.
 * Grant checks occur in resolveActingCompanyContext() on EVERY request.
 * Export creation is a read, including for view-only grantees, but is audited.
 */
require_once __DIR__ . '/CompanyAccess.php';
require_once __DIR__ . '/AdminAppraisalVisibility.php';

/** Super Admin may retain the historic all-company/no-selected-company context. */
function companyExportAssertCycleScope(?array $context, int $cycleCompanyId): void
{
    if ($cycleCompanyId <= 0) throw companyAccessForbidden();
    if ($context !== null && $context['company_id'] !== null
        && (int) $context['company_id'] !== $cycleCompanyId) {
        throw new Exception('Unauthorized: This cycle is outside the selected company scope.', 403);
    }
}

/**
 * For non-home admin grants, combine a per-company peer policy (no supervisor
 * exception) with the appraisal's historical staff_type snapshot.
 */
function companyExportGrantAppraisalFilter(array $context, int $viewerId, string $alias = 'ap'): array
{
    aavValidateAlias($alias);
    if (!empty($context['is_home']) || ($context['access_level'] ?? null) === 'full') {
        return ['sql' => '', 'types' => '', 'params' => []];
    }
    if (!in_array($context['access_level'] ?? '', ['view', 'manage'], true)
        || (int)($context['company_id'] ?? 0) <= 0) {
        throw companyAccessForbidden();
    }
    $companyId = (int) $context['company_id'];
    $peerWhere = adminAppraisalVisibilityWhereSql($viewerId, $companyId, $alias, false);
    $scopeWhere = companyAccessAppraiseeScopeWhere($context, $alias);
    return [
        'sql' => ' AND ' . $peerWhere . $scopeWhere['sql'],
        'types' => $scopeWhere['type'],
        'params' => $scopeWhere['type'] === '' ? [] : [$scopeWhere['value']],
    ];
}

/**
 * For objective-plan denominators and assignment exports: strict current
 * staff_type for EVERY user role, including supervisor/admin appraisees.
 * Peer policy applies to appraisal-related exports, not directory downloads.
 */
function companyExportGrantUserFilter(
    array $context, int $viewerId, string $userAlias = 'u', string $roleAlias = 'r', bool $appraisalRelated = true
): array {
    aavValidateAlias($userAlias);
    aavValidateAlias($roleAlias);
    if (!empty($context['is_home']) || ($context['access_level'] ?? null) === 'full') {
        return ['sql' => '', 'types' => '', 'params' => []];
    }
    if (!in_array($context['access_level'] ?? '', ['view', 'manage'], true)
        || (int)($context['company_id'] ?? 0) <= 0) {
        throw companyAccessForbidden();
    }
    $scopeWhere = companyAccessAppraiseeScopeWhere($context, $userAlias);
    $peerSql = $appraisalRelated
        ? ' AND ' . adminAppraiseeVisibilityWhereSql(
            $viewerId, (int)$context['company_id'], $userAlias, $roleAlias, 0, false
        ) : '';
    return [
        'sql' => $scopeWhere['sql'] . $peerSql,
        'types' => $scopeWhere['type'],
        'params' => $scopeWhere['type'] === '' ? [] : [$scopeWhere['value']],
    ];
}

/**
 * Fail closed if the export event cannot be saved. Write to the company whose
 * data was exported, include actor ID and home company in the description.
 * $companyId may be null only for an all-company Super Admin directory export.
 */
function companyExportAudit(
    mysqli $database, array $actor, ?array $context, ?int $companyId,
    string $action, string $targetTable, ?int $targetId, int $rowCount
): void {
    if (!preg_match('/^export_[a-z_]+_excel$/D', $action)) {
        throw new InvalidArgumentException('Invalid export audit action.');
    }
    if (!in_array($targetTable, ['users', 'appraisal_cycles'], true) || $rowCount < 0) {
        throw new InvalidArgumentException('Invalid export audit target.');
    }
    if ($context !== null && ($context['access_level'] ?? '') !== 'full'
        && (int)($context['company_id'] ?? 0) !== (int)$companyId) {
        throw companyAccessForbidden();
    }
    $actorId = (int)($actor['id'] ?? 0);
    $homeId = (int)($actor['company_id'] ?? 0);
    $homeName = trim((string)($actor['company_name'] ?? ''));
    if ($homeName === '') $homeName = 'company #' . $homeId;
    $scope = (string)($context['staff_scope'] ?? $actor['staff_scope'] ?? 'All');
    $accessLevel = (string)($context['access_level'] ?? 'full');
    $companyText = $companyId === null ? 'all companies' : 'company #' . $companyId;
    $description = "XLSX export generated for {$companyText}; rows={$rowCount}; "
        . "access={$accessLevel}; staff_scope={$scope}; actor home company={$homeName} (#{$homeId})";
    $stmt = $database->prepare('INSERT INTO audit_log '
        . '(company_id, user_id, action, target_table, target_id, description) VALUES (?, ?, ?, ?, ?, ?)');
    if (!$stmt) throw new Exception('Unable to audit export.', 500);
    $stmt->bind_param('iissis', $companyId, $actorId, $action, $targetTable, $targetId, $description);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) throw new Exception('Unable to audit export.', 500);
}
