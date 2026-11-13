<?php
/**
 * Batch 5E-1. Manual email recipients and writes for an administrative grant.
 * Legacy home-company/Super Admin recipient selection remains in MailRecipients.php.
 * This helper is never used by personal or supervisor-owned appraisal actions.
 */
require_once __DIR__ . '/CompanyAccess.php';
require_once __DIR__ . '/AdminAppraisalVisibility.php';
require_once __DIR__ . '/CompanySupervisorWrite.php';

function companyMailContext(array $actor, bool $write = false, $requestedCompanyId = null, ?callable $reader = null): array
{
    $context = resolveActingCompanyContext($actor, $requestedCompanyId, $reader);
    if ($write) requireCompanyWriteAccess($context);
    return $context;
}

function companyMailIsGranted(array $context): bool
{
    return companyUserWriteIsGranted($context);
}

/** Arbitrary external addresses and CC cannot be verified against a staff-scope grant. */
function companyMailAssertAudience(array $context, string $audience, bool $expand, array $cc = []): void
{
    if (!companyMailIsGranted($context)) return;
    // An All-scope manager retains ordinary administrative email features.
    // For a Local/Expatriate grant an arbitrary address or CC cannot be proven
    // to be in scope, so that privilege is withheld rather than bypassed.
    $scoped = ($context['staff_scope'] ?? '') !== 'All';
    if ($scoped && ($audience === 'custom_emails' || $expand || $cc !== [])) {
        throw new Exception('Scoped company email is limited to authorized portal recipients without external CC or scope expansion.', 403);
    }
    if (!in_array($audience, ['custom_emails', 'pending_acknowledgements', 'pending_supervisors', 'cycle_supervisors', 'specific_users'], true)) {
        throw new Exception('Unsupported recipient type.', 400);
    }
}

function companyMailAssertCycle(array $context, array $cycle): void
{
    $companyId = (int)($context['company_id'] ?? 0);
    if ($companyId <= 0 || (int)($cycle['company_id'] ?? 0) !== $companyId) {
        throw new Exception('Selected appraisal cycle is outside your authorized company.', 403);
    }
}

function companyMailSelect(mysqli $db, string $sql, string $types, array $params): array
{
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new Exception('Unable to prepare email recipient lookup.', 500);
    try {
        if ($types !== '') $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) throw new Exception('Unable to fetch email recipients.', 500);
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } finally {
        $stmt->close();
    }
}

/** A grant must target an existing cycle belonging to its acting company. */
function companyMailGrantedCycle(mysqli $db, array $context, int $cycleId): array
{
    if ($cycleId <= 0) throw new Exception('Select an appraisal cycle first.', 400);
    $rows = companyMailSelect($db,
        'SELECT ac.id, ac.year, ac.title, ac.company_id, c.name AS company_name '
        . 'FROM appraisal_cycles ac INNER JOIN companies c ON c.id = ac.company_id AND c.is_active = 1 '
        . 'WHERE ac.id = ? LIMIT 1', 'i', [$cycleId]);
    $cycle = $rows[0] ?? null;
    if (!$cycle) throw new Exception('Selected appraisal cycle was not found.', 404);
    companyMailAssertCycle($context, $cycle);
    return $cycle;
}

/**
 * Build a parameterized staff-type condition; granted scope applies to appraisees
 * in ALL roles. For pending appraisals it uses the saved appraisal snapshot.
 */
function companyMailScopeCondition(array $context, string $column): array
{
    if (!preg_match('/^(?:ap|u|staff)\.staff_type$/D', $column)) {
        throw new InvalidArgumentException('Invalid staff scope column.');
    }
    $scope = (string)($context['staff_scope'] ?? '');
    if ($scope === 'All') return ['sql' => '', 'types' => '', 'params' => []];
    if (!in_array($scope, ['Local', 'Expatriate'], true)) throw companyAccessForbidden();
    return ['sql' => " AND {$column} = ?", 'types' => 's', 'params' => [$scope]];
}

/**
 * Use only the granted company's live-authorized recipient population.
 * In particular, no client-supplied IDs, cycles, search terms or role filters
 * are used as an authorization decision. All external values are bound.
 */
function companyMailGrantedRecipients(
    mysqli $db, array $actor, array $context, string $audience,
    int $cycleId = 0, string $search = '', bool $expand = false
): array {
    companyMailAssertAudience($context, $audience, $expand);
    if ($audience === 'custom_emails') throw new Exception('Use explicit custom addresses for this audience.', 400);
    if (!companyMailIsGranted($context)) throw new LogicException('Grant recipient lookup requires an active grant.');
    $companyId = (int)$context['company_id'];
    $viewerId = (int)$actor['id'];
    $cycleAudiences = ['pending_acknowledgements', 'pending_supervisors', 'cycle_supervisors'];
    $cycle = in_array($audience, $cycleAudiences, true) || $cycleId > 0
        ? companyMailGrantedCycle($db, $context, $cycleId) : null;
    $filters = [];
    $types = '';
    $params = [];

    if ($audience === 'pending_acknowledgements') {
        $scope = companyMailScopeCondition($context, 'ap.staff_type');
        $peer = adminAppraisalVisibilityWhereSql($viewerId, $companyId, 'ap', false);
        $sql = "SELECT u.id AS recipient_id, ap.staff_user_id, ap.id AS appraisal_id,
                    u.first_name, u.last_name, ap.staff_fullname,
                    COALESCE(NULLIF(ap.staff_fullname, ''), CONCAT(u.first_name, ' ', u.last_name)) AS full_name,
                    u.email AS email,
                    LOWER(REPLACE(TRIM(r.name), ' ', '_')) AS role,
                    ap.staff_type, ap.staff_department AS department, ap.staff_job_title AS job_title,
                    ap.status, CONCAT(COALESCE(ap.staff_department, 'No department'), ' • Pending acknowledgement') AS summary,
                    CONCAT(sup.first_name, ' ', sup.last_name) AS supervisor_name
                FROM appraisals ap
                INNER JOIN users u ON u.id = ap.staff_user_id AND u.company_id = ap.company_id
                INNER JOIN roles r ON r.id = u.role_id
                LEFT JOIN users sup ON sup.id = ap.supervisor_id
                WHERE ap.company_id = ? AND ap.cycle_id = ? AND ap.status = 'Pending' AND u.is_active = 1
                  AND LOWER(REPLACE(TRIM(r.name), ' ', '_')) <> 'super_admin'
                  AND " . mailDeliverableWhere('u.email')
                . " AND {$peer}" . $scope['sql'];
        $types = 'ii' . $scope['types'];
        $params = [$companyId, $cycleId, ...$scope['params']];
        if ($search !== '') {
            $sql .= " AND (ap.staff_fullname LIKE ? OR ap.staff_department LIKE ? OR u.email LIKE ?)";
            $searchLike = '%' . $search . '%';
            $types .= 'sss'; array_push($params, $searchLike, $searchLike, $searchLike);
        }
        $sql .= ' ORDER BY full_name ASC';
    } elseif ($audience === 'specific_users') {
        $scope = companyMailScopeCondition($context, 'u.staff_type');
        $peer = adminAppraiseeVisibilityWhereSql($viewerId, $companyId, 'u', 'r', 0, false);
        $sql = "SELECT u.id AS recipient_id, u.first_name, u.last_name,
                  CONCAT(u.first_name, ' ', u.last_name) AS full_name, u.email,
                  LOWER(REPLACE(TRIM(r.name), ' ', '_')) AS role, u.staff_type,
                  u.is_supervisor, CONCAT(COALESCE(u.department, 'No department'), ' • ', r.name) AS summary
                FROM users u INNER JOIN roles r ON r.id = u.role_id
                WHERE u.company_id = ? AND u.is_active = 1
                  AND LOWER(REPLACE(TRIM(r.name), ' ', '_')) <> 'super_admin'
                  AND " . mailDeliverableWhere('u.email') . " AND {$peer}" . $scope['sql'];
        $types = 'i' . $scope['types'];
        $params = [$companyId, ...$scope['params']];
        if ($search !== '') {
            $sql .= " AND (CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR u.email LIKE ? OR u.department LIKE ?)";
            $searchLike = '%' . $search . '%';
            $types .= 'sss'; array_push($params, $searchLike, $searchLike, $searchLike);
        }
        $sql .= ' ORDER BY full_name ASC';
    } elseif ($audience === 'cycle_supervisors' && $context['staff_scope'] === 'All') {
        // An All-scope manager retains the existing cycle-supervisor directory,
        // including supervisors without assignments. Assignment counts still
        // exclude peer-admin appraisees for whom no target-company policy exists.
        $staffPeer = adminAppraiseeVisibilityWhereSql($viewerId, $companyId, 'staff', 'staff_role', $cycleId, false);
        $sql = "SELECT sup.id AS recipient_id, sup.first_name, sup.last_name,
                   CONCAT(sup.first_name, ' ', sup.last_name) AS full_name, sup.email,
                   LOWER(REPLACE(TRIM(r.name), ' ', '_')) AS role, sup.staff_type,
                   COUNT(DISTINCT sa.staff_id) AS assigned_count,
                   CONCAT(COUNT(DISTINCT sa.staff_id), ' assigned employees for cycle') AS summary
                FROM users sup INNER JOIN roles r ON r.id = sup.role_id
                LEFT JOIN supervisor_assignments sa ON sa.supervisor_id = sup.id AND sa.cycle_id = ?
                    AND EXISTS (SELECT 1 FROM users staff
                        INNER JOIN roles staff_role ON staff_role.id = staff.role_id
                        WHERE staff.id = sa.staff_id AND staff.company_id = ? AND {$staffPeer})
                WHERE sup.company_id = ? AND sup.is_active = 1 AND "
                . appraiserRoleWhere('r', 'sup') . ' AND ' . mailDeliverableWhere('sup.email');
        $types = 'iii';
        $params = [$cycleId, $companyId, $companyId];
        if ($search !== '') {
            $sql .= " AND (CONCAT(sup.first_name, ' ', sup.last_name) LIKE ? OR sup.email LIKE ?)";
            $like = '%' . $search . '%';
            $types .= 'ss'; array_push($params, $like, $like);
        }
        $sql .= ' GROUP BY sup.id, sup.first_name, sup.last_name, sup.email, r.name, sup.staff_type ORDER BY full_name ASC';
    } else {
        // Supervisor audiences with a restricted grant reference only in-scope assigned employees.
        // The supervisor may be Local or Expatriate: the scope is attached to
        // the employee being appraised, not to the supervisor's HR type.
        $scope = companyMailScopeCondition($context, 'staff.staff_type');
        $staffPeer = adminAppraiseeVisibilityWhereSql($viewerId, $companyId, 'staff', 'staff_role', $cycleId, false);
        $pending = $audience === 'pending_supervisors';
        $sql = "SELECT sup.id AS recipient_id, sup.first_name, sup.last_name,
                 CONCAT(sup.first_name, ' ', sup.last_name) AS full_name, sup.email,
                 LOWER(REPLACE(TRIM(r.name), ' ', '_')) AS role, sup.staff_type,
                 COUNT(DISTINCT sa.staff_id) AS assigned_count,
                 COUNT(DISTINCT ap.staff_user_id) AS appraised_count,
                 (COUNT(DISTINCT sa.staff_id) - COUNT(DISTINCT ap.staff_user_id)) AS pending_count,
                 CONCAT((COUNT(DISTINCT sa.staff_id) - COUNT(DISTINCT ap.staff_user_id)),
                   ' outstanding of ', COUNT(DISTINCT sa.staff_id), ' assigned') AS summary
               FROM supervisor_assignments sa
               INNER JOIN users staff ON staff.id = sa.staff_id AND staff.company_id = ? AND staff.is_active = 1
               INNER JOIN roles staff_role ON staff_role.id = staff.role_id
               INNER JOIN users sup ON sup.id = sa.supervisor_id AND sup.company_id = ? AND sup.is_active = 1
               INNER JOIN roles r ON r.id = sup.role_id
               LEFT JOIN appraisals ap ON ap.cycle_id = sa.cycle_id
                    AND ap.staff_user_id = sa.staff_id AND ap.supervisor_id = sa.supervisor_id
                    AND ap.company_id = staff.company_id";
        // Do not count a saved appraisal outside the grant's historical snapshot scope.
        if (($context['staff_scope'] ?? 'All') !== 'All') $sql .= ' AND ap.staff_type = ?';
        $sql .= " WHERE sa.cycle_id = ? AND " . appraiserRoleWhere('r', 'sup')
            . ' AND ' . mailDeliverableWhere('sup.email')
            . " AND {$staffPeer}" . $scope['sql'];
        $types = 'ii' . (($context['staff_scope'] ?? 'All') === 'All' ? '' : 's') . 'i' . $scope['types'];
        $params = [$companyId, $companyId];
        if (($context['staff_scope'] ?? 'All') !== 'All') $params[] = $context['staff_scope'];
        $params[] = $cycleId;
        array_push($params, ...$scope['params']);
        if ($search !== '') {
            $sql .= " AND (CONCAT(sup.first_name, ' ', sup.last_name) LIKE ? OR sup.email LIKE ?)";
            $searchLike = '%' . $search . '%';
            $types .= 'ss'; array_push($params, $searchLike, $searchLike);
        }
        $sql .= ' GROUP BY sup.id, sup.first_name, sup.last_name, sup.email, r.name, sup.staff_type';
        if ($pending) $sql .= ' HAVING pending_count > 0';
        $sql .= $pending ? ' ORDER BY pending_count DESC, full_name ASC' : ' ORDER BY full_name ASC';
    }

    $rows = companyMailSelect($db, $sql, $types, $params);
    return [
        'rows' => $rows, 'cycle' => $cycle,
        'scope' => $context['staff_scope'], 'can_expand' => false, 'expanded' => false,
    ];
}

/** Record a verifiable send ATTEMPT before any irreversible external email. */
function companyMailAuditBeforeSend(
    mysqli $db, array $actor, array $context,
    string $audience, string $subject, int $recipientCount, int $cycleId
): void {
    if (!companyMailIsGranted($context)) return;
    if ($recipientCount < 1) throw new Exception('No eligible recipients were found.', 404);
    $companyId = (int)$context['company_id'];
    companySupervisorWriteAudit($db, $actor, $companyId, 'send_manual_email',
        'users', 0, 'Appraisal communication attempt; audience=' . $audience
        . '; cycle=' . $cycleId . '; authorized recipients=' . $recipientCount
        . '; subject=' . substr($subject, 0, 150));
}
