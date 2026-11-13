<?php
require_once __DIR__ . '/CompanyAccess.php';
require_once __DIR__ . '/AdminAppraisalVisibility.php';

/**
 * Appraisee-only predicates for granted-company supervisor/team reads.
 * Names-only directory records are intentionally not filtered by this helper.
 * All grants receive company-local peer-Admin protection, even staff_scope=All.
 * The extra predicate excludes supervisor assignment shortcuts for foreign peers.
 */
function companySupervisorAppraiseeWhere(array $context, int $viewerAdminId, string $userAlias, string $roleAlias): array
{
    aavValidateAlias($userAlias);
    aavValidateAlias($roleAlias);
    if (!empty($context['is_home']) || ($context['access_level'] ?? null) === 'full') {
        return ['sql' => '', 'types' => '', 'params' => []];
    }
    if (!in_array($context['access_level'] ?? null, ['view', 'manage'], true)
        || (int)($context['company_id'] ?? 0) <= 0) {
        throw companyAccessForbidden();
    }
    $staffScope = companyAccessAppraiseeScopeWhere($context, $userAlias);
    $peerScope = adminAppraiseeVisibilityWhereSql(
        $viewerAdminId, (int)$context['company_id'], $userAlias, $roleAlias, 0, false
    );
    return [
        'sql' => $staffScope['sql'] . ' AND ' . $peerScope,
        'types' => $staffScope['type'],
        'params' => $staffScope['type'] !== '' ? [$staffScope['value']] : [],
    ];
}

/** Appraisal aggregates use the appraisal's stored appraisee staff_type snapshot. */
function companySupervisorAppraisalWhere(
    array $context,
    int $viewerAdminId,
    string $appraisalAlias,
    string $subjectAlias,
    string $subjectRoleAlias
): array {
    aavValidateAlias($appraisalAlias);
    aavValidateAlias($subjectAlias);
    aavValidateAlias($subjectRoleAlias);
    if (!empty($context['is_home']) || ($context['access_level'] ?? null) === 'full') {
        return ['sql' => '', 'types' => '', 'params' => []];
    }
    if (!in_array($context['access_level'] ?? null, ['view','manage'], true)
        || (int)($context['company_id'] ?? 0) <= 0) {
        throw companyAccessForbidden();
    }
    $scope = companyAccessAppraiseeScopeWhere($context, $appraisalAlias);
    $peer = adminAppraiseeVisibilityWhereSql(
        $viewerAdminId, (int)$context['company_id'], $subjectAlias, $subjectRoleAlias, 0, false
    );
    return [
        'sql' => $scope['sql'] . ' AND ' . $peer,
        'types' => $scope['type'],
        'params' => $scope['type'] !== '' ? [$scope['value']] : [],
    ];
}
