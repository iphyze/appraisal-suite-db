<?php
require_once __DIR__ . '/../sections/ObjectiveSetupConfig.php';
require_once __DIR__ . '/../../includes/AdminAppraisalVisibility.php';
require_once __DIR__ . '/../../includes/CompanyAccess.php';
require_once __DIR__ . '/../../includes/AdminStaffScope.php';
require_once __DIR__ . '/../../includes/CompanySupervisorWrite.php';
require_once __DIR__ . '/../../includes/AppraisalUpdateEligibility.php';

function opRoleKey($value): string {
    return strtolower(str_replace(' ', '_', trim((string)$value)));
}

function opText($value, int $max = 0): ?string {
    if ($value === null) return null;
    $value = trim((string)$value);
    if ($value === '') return null;
    if ($max > 0) {
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        if ($length > $max) throw new Exception("Text value exceeds the {$max} character limit.", 400);
    }
    return $value;
}

function opAudit(mysqli $conn, int $companyId, int $userId, string $action, int $targetId, string $description): void {
    $targetTable = 'staff_objective_plans';
    $stmt = $conn->prepare('INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description) VALUES (?, ?, ?, ?, ?, ?)');
    if (!$stmt) return;
    $stmt->bind_param('iissis', $companyId, $userId, $action, $targetTable, $targetId, $description);
    $stmt->execute();
    $stmt->close();
}

/** The GET route never advertises admin editing on a foreign view grant. */
function opActorPermissions(string $role, ?array $actingContext, bool $grantWrite, bool $isAssignedAppraiser): array {
    return [
        'can_prepare' => ($actingContext === null || ($grantWrite && ($actingContext['access_level'] ?? null) === 'manage'))
            && (in_array($role, ['super_admin', 'admin'], true) || $isAssignedAppraiser),
        'can_lock' => $actingContext === null && $isAssignedAppraiser,
        'can_unlock' => $actingContext === null && $isAssignedAppraiser,
    ];
}

function opResolveContext(mysqli $conn, array $userData, int $staffUserId, int $sectionId, bool $grantReadOnly = false, bool $grantWrite = false): array {
    if ($staffUserId <= 0 || $sectionId <= 0) throw new Exception('A valid staff member and KPI section are required.', 400);

    $sectionStmt = $conn->prepare("SELECT s.*, c.year AS cycle_year, c.title AS cycle_title FROM appraisal_sections s INNER JOIN appraisal_cycles c ON c.id=s.cycle_id WHERE s.id=? AND s.type='kpi' LIMIT 1");
    $sectionStmt->bind_param('i', $sectionId);
    $sectionStmt->execute();
    $section = $sectionStmt->get_result()->fetch_assoc();
    $sectionStmt->close();
    if (!$section) throw new Exception('KPI section not found.', 404);

    $staffStmt = $conn->prepare('SELECT u.id, u.company_id, u.first_name, u.last_name, u.fullname, u.email, u.department, u.job_title, u.staff_type, u.is_active, r.name AS role_name FROM users u INNER JOIN roles r ON r.id=u.role_id WHERE u.id=? LIMIT 1');
    $staffStmt->bind_param('i', $staffUserId);
    $staffStmt->execute();
    $staff = $staffStmt->get_result()->fetch_assoc();
    $staffStmt->close();
    if (!$staff || (int)$staff['is_active'] !== 1) throw new Exception('Staff member not found or inactive.', 404);
    if ((int)$staff['company_id'] !== (int)$section['company_id']) throw new Exception('Staff member does not belong to this cycle company.', 400);

    $role = opRoleKey($userData['role'] ?? '');
    $loggedInUserId = (int)($userData['id'] ?? 0);
    $loggedInCompanyId = (int)($userData['company_id'] ?? 0);
    // Foreign-company read/write access is explicitly enabled by each route.
    // Assigned-supervisor actions at home never follow a foreign View Context.
    $actingContext = null;
    $adminWriteContext = null;
    if (($grantReadOnly || $grantWrite) && $role === 'admin' && (int)$section['company_id'] !== $loggedInCompanyId) {
        $actingContext = resolveActingCompanyContext($userData);
        if ((int)($actingContext['company_id'] ?? 0) !== (int)$section['company_id']) {
            throw companyAccessForbidden();
        }
        if ($grantWrite) {
            requireCompanyWriteAccess($actingContext);
            $adminWriteContext = $actingContext;
        }
        // Strict appraisee staff_type for every role, including Admin/Supervisor.
        $grantScope = (string)$actingContext['staff_scope'];
        if ($grantScope !== 'All' && (string)($staff['staff_type'] ?? '') !== $grantScope) {
            throw new Exception('Unauthorized: This employee is outside your staff scope.', 403);
        }
    } elseif ($role !== 'super_admin' && $loggedInCompanyId !== (int)$section['company_id']) {
        throw new Exception('Unauthorized: Objective plan is outside your company.', 403);
    }

    $assignmentStmt = $conn->prepare('SELECT id, supervisor_id FROM supervisor_assignments WHERE cycle_id=? AND staff_id=? ORDER BY id ASC LIMIT 1');
    $assignmentStmt->bind_param('ii', $section['cycle_id'], $staffUserId);
    $assignmentStmt->execute();
    $assignment = $assignmentStmt->get_result()->fetch_assoc();
    $assignmentStmt->close();
    if (!$assignment) throw new Exception('Assign this employee to a supervisor before setting objectives.', 400);

    $supervisorId = (int)$assignment['supervisor_id'];
    $canConductAppraisals = userCanConductAppraisals($userData);
    $isAssignedAppraiser = $supervisorId === $loggedInUserId && $canConductAppraisals;
    if ($grantWrite && $role === 'admin' && !$isAssignedAppraiser && $adminWriteContext === null) {
        // This is an administrative write, not the actor's own supervisor work.
        $adminWriteContext = resolveActingCompanyContext($userData);
        requireCompanyWriteAccess($adminWriteContext);
        if ((int)($adminWriteContext['company_id'] ?? 0) !== (int)$section['company_id']) {
            throw companyAccessForbidden();
        }
    }

    if ($role === 'supervisor' && !$isAssignedAppraiser) {
        throw new Exception('This employee is not assigned to you for this cycle.', 403);
    }

    if ($role === 'admin') {
        if ($actingContext === null) {
            // A home admin's own cycle-assigned team is never hidden by admin scope.
            if (!staffScopeAllowsSubject($conn, $userData, null, $staff, (int)$section['cycle_id'])) {
                throw new Exception('Unauthorized: This employee is outside your staff scope.', 403);
            }
        }
        // No supervisor-assignment shortcut for foreign-company peer Admins.
        $privacySupervisor = $actingContext === null ? $supervisorId : 0;
        $privacyCompany = $actingContext === null ? $loggedInCompanyId : (int)$actingContext['company_id'];
        if (!adminCanViewAppraisalSubject($conn, $loggedInUserId, $privacyCompany, $staffUserId, $privacySupervisor)) {
            throw new Exception("Unauthorized: You do not have access to this employee's appraisal setup.", 403);
        }
    }

    return [
        'section' => $section,
        'user_data' => $userData,
        'staff' => $staff,
        'supervisor_id' => $supervisorId,
        'config' => objectiveSetupFetch($conn, $sectionId),
        'write_context' => $adminWriteContext,
        'actor' => array_merge(
            ['user_id' => $loggedInUserId, 'role' => $role],
            opActorPermissions($role, $actingContext, $grantWrite, $isAssignedAppraiser)
        ),
    ];
}

function opGetPlan(mysqli $conn, int $sectionId, int $staffUserId): ?array {
    $stmt = $conn->prepare('SELECT * FROM staff_objective_plans WHERE section_id=? AND staff_user_id=? LIMIT 1');
    $stmt->bind_param('ii', $sectionId, $staffUserId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function opHasAppraisal(mysqli $conn, int $cycleId, int $staffUserId): bool {
    $stmt = $conn->prepare('SELECT id FROM appraisals WHERE cycle_id=? AND staff_user_id=? LIMIT 1');
    $stmt->bind_param('ii', $cycleId, $staffUserId);
    $stmt->execute();
    $found = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $found;
}

/** Appraisal lock order for every post-submission objective write: appraisal, then plan. */
function opFindSubmittedAppraisal(mysqli $conn, int $cycleId, int $staffUserId, bool $lock = false): ?array {
    $sql = 'SELECT * FROM appraisals WHERE cycle_id=? AND staff_user_id=? ORDER BY id DESC LIMIT 1';
    if ($lock) $sql .= ' FOR UPDATE';
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Unable to read submitted appraisal.', 500);
    $stmt->bind_param('ii', $cycleId, $staffUserId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result ?: null;
}

function opGetPlanForUpdate(mysqli $conn, int $sectionId, int $staffUserId): ?array {
    $stmt = $conn->prepare('SELECT * FROM staff_objective_plans WHERE section_id=? AND staff_user_id=? LIMIT 1 FOR UPDATE');
    if (!$stmt) throw new Exception('Unable to lock objective plan.', 500);
    $stmt->bind_param('ii', $sectionId, $staffUserId);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $plan ?: null;
}

function opObjectiveUnlockReason($value): string {
    if (!is_string($value) || !preg_match('//u', $value)) {
        throw new Exception('Enter a valid UTF-8 reason for unlocking submitted objectives.', 400);
    }
    $reason = trim($value);
    $length = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : preg_match_all('/./us', $reason);
    if ($length < 5 || $length > 500) {
        throw new Exception('Explain the change in 5 to 500 characters.', 400);
    }
    return $reason;
}

/** Canonical comparator ignores rating/actual result and objective ordering.
 * Never infer a plan change from timestamps alone or from a changing library description.
 */
function opCanonicalObjectiveItems(array $items): array {
    $out = [];
    foreach ($items as $row) {
        $id = (int)($row['kpi_question_id'] ?? $row['id'] ?? $row['question_id'] ?? 0);
        $target = $row['objective_target'] ?? $row['target'] ?? null;
        $evidence = $row['evidence_source'] ?? null;
        $weight = $row['question_weight'] ?? $row['weight_percent'] ?? null;
        $out[$id] = [
            'question_id' => $id,
            'target' => $target === null ? null : trim((string)$target),
            'evidence_source' => $evidence === null ? null : trim((string)$evidence),
            'weight_percent' => $weight === null ? null : number_format((float)$weight, 2, '.', ''),
        ];
    }
    ksort($out, SORT_NUMERIC);
    return array_values($out);
}

function opSubmittedObjectiveSnapshot(mysqli $conn, int $appraisalId, int $sectionId): array {
    $stmt = $conn->prepare('SELECT kpi_question_id, question_text, objective_target, evidence_source, question_weight, actual_result, rating, rating_comment FROM appraisal_kpi_responses WHERE appraisal_id=? AND section_id=? ORDER BY kpi_question_id ASC');
    if (!$stmt) throw new Exception('Unable to load the submitted KPI snapshot.', 500);
    $stmt->bind_param('ii', $appraisalId, $sectionId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function opObjectiveItemsDiffer(array $submitted, array $locked): bool {
    return opCanonicalObjectiveItems($submitted) !== opCanonicalObjectiveItems($locked);
}

function opStoreObjectiveUnlockEvent(mysqli $conn, array $appraisal, array $plan, int $actorId, string $reason, array $snapshot): void {
    $appraisalId = (int)$appraisal['id'];
    $planId = (int)$plan['id'];
    $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $stmt = $conn->prepare('INSERT INTO appraisal_objective_change_events (appraisal_id, objective_plan_id, actor_user_id, unlock_reason, submitted_snapshot_json) VALUES (?,?,?,?,?)');
    if (!$stmt) throw new Exception('Unable to record the objective unlock reason.', 500);
    $stmt->bind_param('iiiss', $appraisalId, $planId, $actorId, $reason, $json);
    if (!$stmt->execute()) throw new Exception('Unable to audit the objective unlock reason.', 500);
    $stmt->close();
}

/** Link the latest pending unlock to its final locked objective set.
 * Earlier unlock reasons remain in the append-only event table for the next revision.
 */
function opMarkObjectiveRelocked(mysqli $conn, int $appraisalId, int $planId, array $lockedItems): void {
    $json = json_encode($lockedItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $stmt = $conn->prepare('UPDATE appraisal_objective_change_events SET relocked_at=NOW(), locked_snapshot_json=? WHERE appraisal_id=? AND objective_plan_id=? AND relocked_at IS NULL AND consumed_revision_no IS NULL ORDER BY id DESC LIMIT 1');
    if (!$stmt) throw new Exception('Unable to record relocked objective details.', 500);
    $stmt->bind_param('sii', $json, $appraisalId, $planId);
    if (!$stmt->execute()) throw new Exception('Unable to store relocked objectives.', 500);
    $stmt->close();
}

function opDepartmentDefaultObjectives(mysqli $conn, array $ctx): array {
    $sectionId = (int)$ctx['section']['id'];
    $companyId = (int)$ctx['section']['company_id'];
    $department = (string)($ctx['staff']['department'] ?? '');
    if ($department === '') return [];

    $stmt = $conn->prepare("SELECT kq.id, kq.objective_code, kq.question_text, kq.measure_target, kq.evidence_source, kq.review_status, kq.review_note, kq.weight_percent, kq.sort_order, kq.department, 'department' AS scope FROM kpi_questions kq WHERE kq.company_id=? AND kq.section_id=? AND kq.department=? AND kq.supervisor_id IS NULL AND kq.staff_user_id IS NULL AND kq.is_active=1 ORDER BY kq.sort_order ASC, kq.id ASC");
    if (!$stmt) throw new Exception('Database error: ' . $conn->error, 500);
    $stmt->bind_param('iis', $companyId, $sectionId, $department);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function opAvailableObjectives(mysqli $conn, array $ctx): array {
    $sectionId = (int)$ctx['section']['id'];
    $companyId = (int)$ctx['section']['company_id'];
    $staffId = (int)$ctx['staff']['id'];
    $supervisorId = (int)$ctx['supervisor_id'];
    $department = (string)($ctx['staff']['department'] ?? '');

    $stmt = $conn->prepare("SELECT kq.id, kq.objective_code, kq.question_text, kq.measure_target, kq.evidence_source, kq.review_status, kq.review_note, kq.weight_percent, kq.sort_order, kq.department, kq.supervisor_id, kq.staff_user_id, CASE WHEN kq.staff_user_id IS NOT NULL THEN 'individual' WHEN kq.supervisor_id IS NOT NULL THEN 'supervisor' ELSE 'department' END AS scope FROM kpi_questions kq WHERE kq.company_id=? AND kq.section_id=? AND kq.is_active=1 AND (kq.staff_user_id=? OR (kq.supervisor_id=? AND kq.staff_user_id IS NULL) OR (kq.department=? AND kq.supervisor_id IS NULL AND kq.staff_user_id IS NULL)) ORDER BY CASE WHEN kq.staff_user_id IS NOT NULL THEN 1 WHEN kq.supervisor_id IS NOT NULL THEN 2 ELSE 3 END, kq.sort_order ASC, kq.id ASC");
    $stmt->bind_param('iiiis', $companyId, $sectionId, $staffId, $supervisorId, $department);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function opSelectedObjectives(mysqli $conn, int $sectionId, int $staffUserId): array {
    $stmt = $conn->prepare("SELECT ska.id AS assignment_id, ska.kpi_question_id AS id, kq.objective_code, kq.question_text, COALESCE(ska.target_override,kq.measure_target) AS target, COALESCE(ska.evidence_override,kq.evidence_source) AS evidence_source, ska.target_override, ska.evidence_override, COALESCE(ska.weight_percent,kq.weight_percent) AS weight_percent, kq.is_active, kq.review_status, kq.review_note, CASE WHEN kq.staff_user_id IS NOT NULL THEN 'individual' WHEN kq.supervisor_id IS NOT NULL THEN 'supervisor' ELSE 'department' END AS scope FROM staff_kpi_assignments ska INNER JOIN kpi_questions kq ON kq.id=ska.kpi_question_id WHERE ska.section_id=? AND ska.staff_user_id=? ORDER BY kq.sort_order ASC, ska.id ASC");
    $stmt->bind_param('ii', $sectionId, $staffUserId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function opScoringProfiles(mysqli $conn, int $companyId, int $cycleId): array {
    $stmt = $conn->prepare('SELECT id, code, label, description, is_default, sort_order FROM appraisal_scoring_profiles WHERE company_id=? AND cycle_id=? AND is_active=1 ORDER BY sort_order ASC, id ASC');
    if (!$stmt) throw new Exception('Database error: ' . $conn->error, 500);
    $stmt->bind_param('ii', $companyId, $cycleId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map(function ($row) {
        return [
            'id' => (int)$row['id'],
            'code' => (string)$row['code'],
            'label' => (string)$row['label'],
            'description' => $row['description'] ?? null,
            'is_default' => (int)($row['is_default'] ?? 0),
            'sort_order' => (int)($row['sort_order'] ?? 0),
        ];
    }, $rows);
}

function opAssignedScoringProfile(mysqli $conn, int $cycleId, int $staffUserId): ?array {
    $stmt = $conn->prepare('SELECT p.id, p.code, p.label, p.description FROM appraisal_staff_scoring_profiles a INNER JOIN appraisal_scoring_profiles p ON p.id=a.profile_id WHERE a.cycle_id=? AND a.staff_user_id=? LIMIT 1');
    if (!$stmt) throw new Exception('Database error: ' . $conn->error, 500);
    $stmt->bind_param('ii', $cycleId, $staffUserId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    return ['id'=>(int)$row['id'],'code'=>(string)$row['code'],'label'=>(string)$row['label'],'description'=>$row['description'] ?? null];
}

function opRequireExplicitProfile(mysqli $conn, int $companyId, int $cycleId): bool {
    $stmt = $conn->prepare('SELECT require_explicit_profile FROM appraisal_cycle_settings WHERE company_id=? AND cycle_id=? LIMIT 1');
    if (!$stmt) throw new Exception('Database error: ' . $conn->error, 500);
    $stmt->bind_param('ii', $companyId, $cycleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['require_explicit_profile'] ?? 0) === 1;
}

function opPayload(mysqli $conn, array $ctx): array {
    $sectionId = (int)$ctx['section']['id'];
    $staffId = (int)$ctx['staff']['id'];
    $plan = opGetPlan($conn, $sectionId, $staffId);
    $selected = opSelectedObjectives($conn, $sectionId, $staffId);
    $weightTotal = 0.0;
    foreach ($selected as $row) $weightTotal += (float)($row['weight_percent'] ?? 0);
    $companyId = (int)$ctx['section']['company_id'];
    $cycleId = (int)$ctx['section']['cycle_id'];
    $scoringProfiles = opScoringProfiles($conn, $companyId, $cycleId);
    $assignedProfile = opAssignedScoringProfile($conn, $cycleId, $staffId);
    $submittedAppraisal = opFindSubmittedAppraisal($conn, $cycleId, $staffId);
    $editEligibility = $submittedAppraisal ? apCanSupervisorUpdateAppraisal($conn, $ctx['user_data'] ?? [], null, $submittedAppraisal) : null;

    return [
        'staff' => [
            'id' => $staffId,
            'name' => trim((string)($ctx['staff']['fullname'] ?: (($ctx['staff']['first_name'] ?? '') . ' ' . ($ctx['staff']['last_name'] ?? '')))),
            'email' => $ctx['staff']['email'] ?? null,
            'department' => $ctx['staff']['department'] ?? null,
            'job_title' => $ctx['staff']['job_title'] ?? null,
        ],
        'section' => [
            'id' => $sectionId,
            'code' => $ctx['section']['code'],
            'label' => $ctx['section']['label'],
            'cycle_id' => (int)$ctx['section']['cycle_id'],
            'cycle_title' => $ctx['section']['cycle_title'],
            'cycle_year' => $ctx['section']['cycle_year'],
        ],
        'config' => $ctx['config'],
        'require_explicit_profile' => opRequireExplicitProfile($conn, $companyId, $cycleId) ? 1 : 0,
        'scoring_profiles' => $scoringProfiles,
        'scoring_profile' => $assignedProfile,
        'plan' => $plan ? [
            'id' => (int)$plan['id'],
            'status' => $plan['status'],
            'locked_at' => $plan['locked_at'],
            'locked_by' => $plan['locked_by'] ? (int)$plan['locked_by'] : null,
            'updated_at' => $plan['updated_at'],
        ] : null,
        'has_appraisal' => $submittedAppraisal !== null,
        'appraisal_id' => $submittedAppraisal ? (int)$submittedAppraisal['id'] : null,
        'can_adjust_objectives' => $editEligibility && $editEligibility['allowed'] ? 1 : 0,
        'updates_left' => $editEligibility['updates_left'] ?? null,
        'reason_key' => $editEligibility['reason_key'] ?? null,
        'objectives_changed_since_submission' => $submittedAppraisal && !empty($submittedAppraisal['objectives_changed_at']) ? 1 : 0,
        'permissions' => [
            'can_prepare' => !empty($ctx['actor']['can_prepare']) ? 1 : 0,
            'can_lock' => !empty($ctx['actor']['can_lock']) ? 1 : 0,
            'can_unlock' => !empty($ctx['actor']['can_unlock']) ? 1 : 0,
        ],
        'selected' => $selected,
        'defaults' => opDepartmentDefaultObjectives($conn, $ctx),
        'available' => opAvailableObjectives($conn, $ctx),
        'summary' => [
            'selected_count' => count($selected),
            'weight_total' => round($weightTotal, 2),
        ],
    ];
}
