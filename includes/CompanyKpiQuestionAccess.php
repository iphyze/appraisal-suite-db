<?php
/**
 * Batch 5D-2B: company-aware KPI question library.
 * Unlike company-wide question defaults, individual KPI questions contain
 * appraisee-specific data. Granted-company access must check those subjects.
 * Supervisor-owned question edits remain on the supervisor's own company.
 */
require_once __DIR__ . '/CompanySetupWrite.php';

function companyKpiQuestionContext(array $actor, bool $write): ?array
{
    $role = companyAccessRoleKey($actor['role_key'] ?? $actor['role'] ?? '');
    // KPI questions can be staff-specific, so the company-wide rule is applied per
    // question in companyKpiQuestionAssertSubject() rather than here.
    if ($role === 'admin') {
        return $write ? companySetupWriteContext($actor, false) : resolveActingCompanyContext($actor);
    }
    if ($role === 'super_admin') {
        return $write ? companySetupWriteContext($actor, false) : resolveActingCompanyContext($actor);
    }
    // Supervisor and staff never inherit View Context permission for personal work.
    return null;
}

function companyKpiQuestionAssertCompany(array $actor, ?array $context, int $companyId): void
{
    $role = companyAccessRoleKey($actor['role_key'] ?? $actor['role'] ?? '');
    if ($role === 'super_admin') return;
    if ($companyId < 1 || $companyId !== (int)($context['company_id'] ?? $actor['company_id'] ?? 0)) {
        throw new Exception('This KPI question is outside your authorized company.', 403);
    }
}

/** Strict grant scope and default-deny peer-admin policy on individual questions. */
function companyKpiQuestionAssertSubject(
    mysqli $db, array $actor, ?array $context, int $companyId, ?int $staffId
): void {
    if (!$context || !companyUserWriteIsGranted($context)) return;
    if (!$staffId) {
        // A question with no specific employee is a company-wide default.
        companySetupRequireCompanyWide($context);
        return;
    }
    $stmt = $db->prepare('SELECT id, company_id, staff_type FROM users WHERE id = ? AND company_id = ? LIMIT 1');
    if (!$stmt) throw new Exception('Unable to check KPI subject.', 500);
    $stmt->bind_param('ii', $staffId, $companyId);
    $stmt->execute();
    $subject = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$subject) throw new Exception('The KPI subject is outside your authorized company.', 403);
    companyUserWriteAssertStaffType($context, $subject['staff_type'] ?? null);
    companySupervisorWriteAssertPeer($db, $actor, $context, $staffId, 0);
}

/** SQL predicate for list, search, aggregation and filter-option queries. */
function companyKpiQuestionReadWhere(array $actor, ?array $context, string $alias = 'kq'): string
{
    if (!$context || !companyUserWriteIsGranted($context)) return '';
    $alias = aavValidateAlias($alias);
    $companyId = (int)$context['company_id'];
    $scope = (string)($context['staff_scope'] ?? '');
    if (!in_array($scope, ['All', 'Local', 'Expatriate'], true)) throw companyAccessForbidden();
    $staffFilter = $scope === 'All' ? '' : " AND cq_subject.staff_type = '" . $scope . "'";
    $peer = adminAppraisalVisibilityWhereSql((int)$actor['id'], $companyId, $alias, false);
    return " AND ({$alias}.staff_user_id IS NULL OR (EXISTS (
        SELECT 1 FROM users cq_subject
        WHERE cq_subject.id = {$alias}.staff_user_id
          AND cq_subject.company_id = {$companyId}{$staffFilter}
    ) AND {$peer}))";
}

/** Use on existing question sets before any write or read of their details. */
function companyKpiQuestionAssertRow(mysqli $db, array $actor, ?array $context, array $row): void
{
    $companyId = (int)($row['company_id'] ?? 0);
    companyKpiQuestionAssertCompany($actor, $context, $companyId);
    companyKpiQuestionAssertSubject($db, $actor, $context, $companyId,
        isset($row['staff_user_id']) ? (int)$row['staff_user_id'] : null);
}

function companyKpiQuestionAuditGranted(
    mysqli $db, array $actor, ?array $context, int $companyId,
    string $action, int $recordId, string $description
): void {
    if ($context) {
        companySetupAuditGranted($db, $actor, $context, $companyId,
            $action, 'kpi_questions', $recordId, $description);
    }
}
