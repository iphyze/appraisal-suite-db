<?php
/**
 * Batch 5D-2C: staff-specific KPI assignment access.
 * Existing personal supervisor work always belongs to the employee's record
 * company, even if an admin supervising their own team has selected another
 * company in View Context. Administrative cross-company requests use a live
 * grant and strict appraisee/peer-admin permissions.
 */
require_once __DIR__ . '/CompanyKpiQuestionAccess.php';

/** Check the real section/cycle assignment, not a client-supplied supervisor ID. */
function companyKpiAssignmentIsOwnTeam(mysqli $db, array $actor, int $staffId, int $sectionId): bool
{
    if (!function_exists('userCanConductAppraisals') || !userCanConductAppraisals($actor)) return false;
    $stmt = $db->prepare('SELECT sa.id FROM supervisor_assignments sa INNER JOIN appraisal_sections s ON s.cycle_id=sa.cycle_id WHERE sa.supervisor_id=? AND sa.staff_id=? AND s.id=? LIMIT 1');
    if (!$stmt) throw new Exception('Unable to verify supervisor assignment.', 500);
    $actorId = (int)($actor['id'] ?? 0);
    $stmt->bind_param('iii', $actorId, $staffId, $sectionId);
    if (!$stmt->execute()) throw new Exception('Unable to verify supervisor assignment.', 500);
    $owned = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $owned;
}

/** Resolve a subject/section company BEFORE honoring View Context. */
function companyKpiAssignmentAssertSection(array $staff, array $section): void
{
    if ((int)($staff['company_id'] ?? 0) < 1 ||
        (int)($section['company_id'] ?? 0) !== (int)$staff['company_id']) {
        throw new Exception('KPI section does not belong to the employee company.', 403);
    }
    if (($section['type'] ?? null) !== 'kpi') {
        throw new Exception("KPI assignments can only be set for sections of type 'kpi'.", 400);
    }
}

/**
 * Optional reader/selector are used only by pure tests. Production callers
 * use the ordinary GET view_company_id and the live database grant lookup.
 */
function companyKpiAssignmentContext(
    array $actor, array $staff, bool $isOwnTeam, bool $write,
    $requestedCompanyId = null, ?callable $grantReader = null
): ?array {
    $role = companyAccessRoleKey($actor['role_key'] ?? $actor['role'] ?? '');
    $homeId = (int)($actor['company_id'] ?? 0);
    $staffCompanyId = (int)($staff['company_id'] ?? 0);

    // Super Admin and actual supervisors retain the legacy record-company path.
    if ($role === 'super_admin') return null;
    if ($role === 'supervisor') {
        if ($homeId <= 0 || $staffCompanyId !== $homeId || !$isOwnTeam) {
            throw new Exception('This employee is not assigned to you in your company.', 403);
        }
        return null;
    }
    if ($role !== 'admin') throw companyAccessForbidden();

    // An admin supervising their OWN home team is acting personally, not
    // exercising foreign-company management permission.
    if ($isOwnTeam && $staffCompanyId === $homeId
        && function_exists('userCanConductAppraisals') && userCanConductAppraisals($actor)) {
        return companyAccessHomeContext($actor, $homeId);
    }

    $context = resolveActingCompanyContext($actor, $requestedCompanyId, $grantReader);
    if ((int)($context['company_id'] ?? 0) !== $staffCompanyId) {
        throw new Exception('This employee is outside your selected company.', 403);
    }
    if ($write) requireCompanyWriteAccess($context);
    companyUserWriteAssertStaffType($context, $staff['staff_type'] ?? null);
    return $context;
}

/** Strict grant scope and no foreign admin-as-supervisor peer exception. */
function companyKpiAssignmentAssertPeer(mysqli $db, array $actor, ?array $context, int $staffId): void
{
    if ($context && companyUserWriteIsGranted($context)) {
        companySupervisorWriteAssertPeer($db, $actor, $context, $staffId, 0);
    }
}

/** Do not assign one employee another employee's individually scoped KPI. */
function companyKpiAssignmentAssertQuestion(?array $context, int $staffId, array $question): void
{
    if (!$context || !companyUserWriteIsGranted($context)) return;
    if (isset($question['staff_user_id']) && (int)$question['staff_user_id'] !== $staffId) {
        throw new Exception('This KPI question belongs to another employee.', 403);
    }
}

/** Applied to BOTH custom-selection rows and departmental fallbacks. */
function companyKpiAssignmentReadWhere(array $actor, ?array $context, int $staffId, int $sectionId): string
{
    if (!$context || !companyUserWriteIsGranted($context)) return '';
    $companyId = (int)$context['company_id'];
    if ($staffId <= 0 || $sectionId <= 0 || $companyId <= 0) throw companyAccessForbidden();
    return " AND kq.company_id = {$companyId} AND kq.section_id = {$sectionId}"
        . " AND (kq.staff_user_id IS NULL OR kq.staff_user_id = {$staffId})"
        . companyKpiQuestionReadWhere($actor, $context, 'kq');
}

/** The caller must be inside a transaction. A failed audit must roll it back. */
function companyKpiAssignmentAudit(
    mysqli $db, array $actor, ?array $context, int $companyId,
    int $staffId, int $sectionId, int $questionCount, bool $clearing
): void {
    if (!$context || !companyUserWriteIsGranted($context)) return;
    $operation = $clearing ? 'clear_kpi_assignments' : 'set_kpi_assignments';
    $details = $clearing
        ? "Cleared KPI assignments for employee #{$staffId} in section #{$sectionId}"
        : "Assigned {$questionCount} KPI questions for employee #{$staffId} in section #{$sectionId}";
    companySupervisorWriteAudit($db, $actor, $companyId, $operation,
        'staff_kpi_assignments', $staffId, $details);
}
