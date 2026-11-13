<?php
/**
 * Cross-company administrator directory reads, not appraisal-data reads.
 * Directory behavior intentionally retains home-company treatment: a restricted
 * admin can still look up supervisor/admin records, while regular staff rows
 * use the user's home or granted staff_scope.
 */
require_once __DIR__ . '/CompanyAccess.php';

function companyDirectoryContext(array $actor): array
{
    return resolveActingCompanyContext($actor);
}

function companyDirectoryStaffScope(array $actor, array $context): ?string
{
    if (companyAccessRoleKey($actor['role_key'] ?? $actor['role'] ?? '') !== 'admin') {
        return null;
    }
    $scope = (string)($context['staff_scope'] ?? 'All');
    if (!in_array($scope, ['All', 'Local', 'Expatriate'], true)) {
        throw companyAccessForbidden();
    }
    return $scope === 'All' ? null : $scope;
}

/** Prepared SQL condition; supervisor/admin directory rows remain visible. */
function companyDirectoryStaffWhere(?string $scope, string $userAlias = 'u', string $roleAlias = 'r'): array
{
    if ($scope === null) {
        return ['sql' => '', 'types' => '', 'params' => []];
    }
    foreach ([$userAlias, $roleAlias] as $alias) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $alias)) {
            throw new InvalidArgumentException('Invalid directory query alias.');
        }
    }
    if (!in_array($scope, ['Local', 'Expatriate'], true)) {
        throw companyAccessForbidden();
    }
    return [
        'sql' => " AND (LOWER(REPLACE(TRIM({$roleAlias}.name), ' ', '_')) <> 'staff' OR {$userAlias}.staff_type = ?)",
        'types' => 's',
        'params' => [$scope],
    ];
}

/** Preserve existing single-directory profile semantics (unknown staff type). */
function companyDirectoryTargetVisible(?string $scope, array $target): bool
{
    if ($scope === null || companyAccessRoleKey($target['role'] ?? '') !== 'staff') {
        return true;
    }
    return ($target['staff_type'] ?? null) === null || (string)$target['staff_type'] === $scope;
}
