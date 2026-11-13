<?php
/** Batch 5D-1: company-wide setup changes, separate from appraisee writes. */
require_once __DIR__ . '/CompanySupervisorWrite.php';

function companySetupReadScope(array $actor): ?int
{
    $role = companyAccessRoleKey($actor['role_key'] ?? $actor['role'] ?? '');
    if ($role !== 'admin') return resolveCompanyScope($actor);
    return (int)resolveActingCompanyContext($actor)['company_id'];
}

/**
 * Company-wide settings (cycles, scoring, sections, general questions, departments,
 * KPI defaults) affect every employee, so under a grant they need Manage access for
 * ALL staff. A grant limited to Local or Expatriate staff keeps its staff-specific
 * rights only. Home-company admins and the Super Admin are not affected.
 */
function companySetupRequireCompanyWide(array $context): void
{
    if (!companyUserWriteIsGranted($context)) return;
    $scope = (string)($context['staff_scope'] ?? 'All');
    if ($scope === 'All') return;
    $name = trim((string)($context['company_name'] ?? '')) ?: 'this company';
    throw new Exception("Changing {$name}'s company-wide settings needs Manage access for all staff. Your access covers {$scope} staff only.", 403);
}

function companySetupWriteContext(array $actor, bool $companyWide = true): array
{
    $context = resolveActingCompanyContext($actor);
    requireCompanyWriteAccess($context);
    if ($companyWide) companySetupRequireCompanyWide($context);
    return $context;
}

function companySetupCreateCompanyId(array $actor, array $context, array $data): int
{
    return companyUserWriteCreateCompanyId($actor, $context, $data);
}

function companySetupAssertCompany(array $actor, array $context, int $recordCompanyId): void
{
    companySupervisorWriteAssertCompany($actor, $context, $recordCompanyId);
}

/** Required within a transaction for granted-company setup writes. */
function companySetupAuditGranted(
    mysqli $db, array $actor, array $context, int $companyId,
    string $action, string $table, int $recordId, string $description
): void {
    if (companyUserWriteIsGranted($context)) {
        companySupervisorWriteAudit($db, $actor, $companyId, $action, $table, $recordId, $description);
    }
}
