<?php
/**
 * Appraisal candidate access is appraisal-data access, not a staff directory.
 * For grants, all appraisee roles are scope-filtered and the saved appraisal
 * staff_type is authoritative when there is an existing appraisal.
 */
require_once __DIR__ . '/CompanyAccess.php';
require_once __DIR__ . '/AdminAppraisalVisibility.php';
require_once __DIR__ . '/AdminStaffScope.php';

function companyCandidateActingContext(array $actor, string $scope): ?array
{
    if (companyAccessRoleKey($actor['role_key'] ?? $actor['role'] ?? '') !== 'admin'
        || $scope === 'my_team') {
        return null; // personal supervisor/team operations use their own records
    }
    return resolveActingCompanyContext($actor);
}

function companyCandidateAppraiseeWhere(array $actor, ?array $context, int $cycleId): array
{
    if (companyAccessRoleKey($actor['role_key'] ?? $actor['role'] ?? '') !== 'admin') {
        return ['sql' => '', 'types' => '', 'params' => []];
    }
    $companyId = $context === null
        ? (int)($actor['company_id'] ?? 0)
        : (int)($context['company_id'] ?? 0);
    $isGranted = $context !== null && empty($context['is_home']);
    $allowAssignment = !$isGranted;
    $parts = [adminAppraiseeVisibilityWhereSql(
        (int)($actor['id'] ?? 0), $companyId, 's', 'sr', $cycleId, $allowAssignment
    )];
    $types = '';
    $params = [];
    $scope = $isGranted ? (string)($context['staff_scope'] ?? '') : (string)($actor['staff_scope'] ?? 'All');
    if (in_array($scope, ['Local', 'Expatriate'], true)) {
        if ($isGranted) {
            $parts[] = '(CASE WHEN ap.id IS NOT NULL THEN ap.staff_type ELSE s.staff_type END) = ?';
            $types = 's';
            $params[] = $scope;
        } else {
            // Home admin scope cannot hide the admin's own cycle-specific appraisees.
            $parts[] = staffScopeHomeUserSql($actor, $cycleId, 's', 'sr', true);
            $types = 's';
            $params[] = $scope;
        }
    } elseif ($scope !== 'All') {
        throw companyAccessForbidden();
    }
    return ['sql' => implode(' AND ', $parts), 'types' => $types, 'params' => $params];
}

/** View grants cannot manage company profiles/objectives from candidates UI. */
function companyCandidateAdminCanManage(?array $context): bool
{
    if ($context === null || !empty($context['is_home'])) {
        return true;
    }
    return ($context['access_level'] ?? null) === 'manage';
}
