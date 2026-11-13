<?php
/**
 * Staff scope governs an Admin's company-wide authority, not the appraisal work
 * they personally perform as an assigned supervisor in their HOME company.
 *
 * NEVER apply the assignment/recorded-supervisor exemptions to Company Access
 * grants. Existing peer-admin visibility and company guards remain additional
 * independent checks at every caller.
 */

function staffScopeRestrictedValue(array $actor): ?string
{
    $scope = trim((string) ($actor['staff_scope'] ?? 'All'));
    return in_array($scope, ['Local', 'Expatriate'], true) ? $scope : null;
}

function staffScopeIsHomeAdmin(array $actor, ?array $context, int $subjectCompanyId): bool
{
    $role = strtolower(str_replace(' ', '_', trim((string) ($actor['role_key'] ?? $actor['role'] ?? ''))));
    return $role === 'admin'
        && $subjectCompanyId > 0
        && $subjectCompanyId === (int) ($actor['company_id'] ?? 0)
        && ($context === null || !empty($context['is_home']));
}

/**
 * This is for appraisal subjects ONLY, not user editing or company setup.
 * $recordedSupervisorId is supplied only when authorizing an existing appraisal.
 */
function staffScopeAllowsSubject(
    mysqli $conn,
    array $actor,
    ?array $context,
    array $subject,
    int $cycleId,
    int $recordedSupervisorId = 0
): bool {
    $role = strtolower(str_replace(' ', '_', trim((string) ($actor['role_key'] ?? $actor['role'] ?? ''))));
    if ($role !== 'admin') return true;

    $subjectCompanyId = (int) ($subject['company_id'] ?? 0);
    if (!staffScopeIsHomeAdmin($actor, $context, $subjectCompanyId)) {
        // Granted-company scope applies to ALL appraisee roles and saved types.
        $scope = trim((string) ($context['staff_scope'] ?? ''));
        return $context !== null && $subjectCompanyId === (int) ($context['company_id'] ?? 0)
            && ($scope === 'All' || (in_array($scope, ['Local','Expatriate'], true)
                && (string) ($subject['staff_type'] ?? '') === $scope));
    }

    $scope = staffScopeRestrictedValue($actor);
    $subjectRole = strtolower(str_replace(' ', '_', trim((string) ($subject['role_name'] ?? $subject['role'] ?? ''))));
    if ($scope === null || $subjectRole !== 'staff'
        || (string) ($subject['staff_type'] ?? '') === $scope) return true;

    $actorId = (int) ($actor['id'] ?? 0);
    if ($actorId <= 0) return false;
    if ($recordedSupervisorId === $actorId) return true;
    if ($cycleId <= 0 || (int) ($subject['id'] ?? 0) <= 0) return false;

    $staffId = (int) $subject['id'];
    $stmt = $conn->prepare('SELECT 1 FROM supervisor_assignments WHERE supervisor_id = ? AND staff_id = ? AND cycle_id = ? LIMIT 1');
    if (!$stmt) throw new Exception('Unable to check supervisor assignment.', 500);
    $stmt->bind_param('iii', $actorId, $staffId, $cycleId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception('Unable to check supervisor assignment.', 500);
    }
    $found = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $found;
}

/** Validate SQL identifiers; never accept a client-provided alias. */
function staffScopeAlias(string $alias): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $alias)) {
        throw new InvalidArgumentException('Invalid SQL alias provided.');
    }
    return $alias;
}

/**
 * Cycle-specific staff candidate / team / objective predicates. All inputs
 * except trusted aliases are server-derived; scopes are enum validated.
 * When $bindScope=true the caller MUST bind one scope string parameter.
 */
function staffScopeHomeUserSql(
    array $actor, int $cycleId, string $userAlias, string $roleAlias, bool $bindScope = false
): string {
    $userAlias = staffScopeAlias($userAlias);
    $roleAlias = staffScopeAlias($roleAlias);
    $scope = staffScopeRestrictedValue($actor);
    if ($scope === null) return '1=1';
    $scopeSql = $bindScope ? '?' : "'{$scope}'";
    $actorId = (int) ($actor['id'] ?? 0);
    $homeId = (int) ($actor['company_id'] ?? 0);
    // A missing cycle cannot confer new access. Only an explicit cycle qualifies.
    $assigned = $cycleId > 0 && $actorId > 0 && $homeId > 0
        ? " OR ({$userAlias}.company_id = {$homeId} AND EXISTS ("
          . "SELECT 1 FROM supervisor_assignments staff_scope_assignment "
          . "WHERE staff_scope_assignment.staff_id = {$userAlias}.id "
          . "AND staff_scope_assignment.cycle_id = {$cycleId} "
          . "AND staff_scope_assignment.supervisor_id = {$actorId}))"
        : '';
    return "(LOWER(REPLACE(TRIM({$roleAlias}.name), ' ', '_')) <> 'staff' "
        . "OR {$userAlias}.staff_type = {$scopeSql}{$assigned})";
}

/** One admin's own recorded or cycle-assigned appraisals, home company only. */
function staffScopeHomeAssignedAppraisalSql(array $actor, string $appraisalAlias): string
{
    $appraisalAlias = staffScopeAlias($appraisalAlias);
    $actorId = (int) ($actor['id'] ?? 0);
    $homeId = (int) ($actor['company_id'] ?? 0);
    if ($actorId <= 0 || $homeId <= 0) return '0=1';
    return "({$appraisalAlias}.company_id = {$homeId} AND ("
        . "{$appraisalAlias}.supervisor_id = {$actorId} OR EXISTS ("
        . "SELECT 1 FROM supervisor_assignments staff_scope_assignment "
        . "WHERE staff_scope_assignment.staff_id = {$appraisalAlias}.staff_user_id "
        . "AND staff_scope_assignment.cycle_id = {$appraisalAlias}.cycle_id "
        . "AND staff_scope_assignment.supervisor_id = {$actorId})))";
}

/**
 * Historical appraisal snapshots retain the original staff_type comparison.
 * The recorded supervisor is sufficient even if the assignment later changes.
 * The assignment exception is always restricted to the appraisal's own cycle.
 */
function staffScopeHomeAppraisalSql(array $actor, string $appraisalAlias, bool $bindScope = false): string
{
    $appraisalAlias = staffScopeAlias($appraisalAlias);
    $scope = staffScopeRestrictedValue($actor);
    if ($scope === null) return '1=1';
    $scopeSql = $bindScope ? '?' : "'{$scope}'";
    $own = ' OR ' . staffScopeHomeAssignedAppraisalSql($actor, $appraisalAlias);
    return "(NOT EXISTS (SELECT 1 FROM users staff_scope_user "
        . "INNER JOIN roles staff_scope_role ON staff_scope_role.id = staff_scope_user.role_id "
        . "WHERE staff_scope_user.id = {$appraisalAlias}.staff_user_id "
        . "AND LOWER(REPLACE(TRIM(staff_scope_role.name), ' ', '_')) = 'staff') "
        . "OR {$appraisalAlias}.staff_type = {$scopeSql}{$own})";
}
