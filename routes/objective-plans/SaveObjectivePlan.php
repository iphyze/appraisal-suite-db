<?php
require_once __DIR__ . '/../../includes/ApiException.php';
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once __DIR__ . '/ObjectivePlanHelpers.php';
header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Bad Request: Only POST method is allowed', 405);
    $userData = requireRoles(['super_admin','admin','supervisor']);
    $loggedInUserId = (int)$userData['id'];
    $loggedInEmail = trim((string)($userData['email'] ?? 'User'));
    $data = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($data)) throw new Exception('Invalid request format.', 400);

    $staffUserId = (int)($data['staff_user_id'] ?? 0);
    $sectionId = (int)($data['section_id'] ?? 0);
    $objectives = $data['objectives'] ?? null;
    $lock = !empty($data['lock']);
    $profileId = isset($data['scoring_profile_id']) && $data['scoring_profile_id'] !== '' ? (int)$data['scoring_profile_id'] : 0;
    if (!is_array($objectives)) throw new Exception("Field 'objectives' must be an array.", 400);

    $ctx = opResolveContext($conn, $userData, $staffUserId, $sectionId, false, true);
    if (empty($ctx['actor']['can_prepare'])) {
        throw new Exception('You are not allowed to prepare this employee\'s objective plan.', 403);
    }
    if ($lock && empty($ctx['actor']['can_lock'])) {
        throw new Exception('Only the assigned supervisor/HOD can lock this employee\'s objective plan.', 403);
    }
    $config = $ctx['config'];
    if (empty($config['enabled'])) throw new Exception('Per-staff objective setup is not enabled for this KPI section.', 409);
    $companyId = (int)$ctx['section']['company_id'];
    $cycleId = (int)$ctx['section']['cycle_id'];
    $requiresExplicitProfile = opRequireExplicitProfile($conn, $companyId, $cycleId);
    $profile = null;
    if ($profileId > 0) {
        $profileStmt = $conn->prepare('SELECT id, code, label FROM appraisal_scoring_profiles WHERE id=? AND company_id=? AND cycle_id=? AND is_active=1 LIMIT 1');
        if (!$profileStmt) throw new Exception('Database error: ' . $conn->error, 500);
        $profileStmt->bind_param('iii', $profileId, $companyId, $cycleId);
        $profileStmt->execute();
        $profile = $profileStmt->get_result()->fetch_assoc();
        $profileStmt->close();
        if (!$profile) throw new Exception('Selected appraisal level/profile is not valid for this cycle.', 400);
    } elseif ($requiresExplicitProfile && $lock && !opHasAppraisal($conn, $cycleId, $staffUserId)) {
        throw new Exception('Select the employee appraisal level/profile before locking objectives.', 400);
    }
    $submittedAppraisal=opFindSubmittedAppraisal($conn,$cycleId,$staffUserId);
    if ($submittedAppraisal) {
        apAssertSupervisorUpdateEligible(apCanSupervisorUpdateAppraisal($conn,$userData,null,$submittedAppraisal));
        $assignedProfile=opAssignedScoringProfile($conn,$cycleId,$staffUserId);
        if ($profileId > 0 && $profileId !== (int)($assignedProfile['id'] ?? 0)) {
            throw new Exception('The appraisal level cannot be changed after submission.',409);
        }
    }

    $existingPlan = opGetPlan($conn, $sectionId, $staffUserId);
    if ($existingPlan && $existingPlan['status'] === 'locked') throw new Exception('This objective plan is locked. Unlock it before making changes.', 409);

    $count = count($objectives);
    if ($lock) {
        if ($count < (int)$config['min_objectives']) throw new ApiException('objective.min', ['count' => (int)$config['min_objectives']], 400, 'Select at least ' . (int)$config['min_objectives'] . ' objective(s) before locking the plan.');
        if ($config['max_objectives'] !== null && $count > (int)$config['max_objectives']) throw new ApiException('objective.max', ['count' => (int)$config['max_objectives']], 400, 'Select no more than ' . (int)$config['max_objectives'] . ' objective(s) for this plan.');
    }

    $available = opAvailableObjectives($conn, $ctx);
    $availableMap = [];
    foreach ($available as $row) $availableMap[(int)$row['id']] = $row;

    $normalized = [];
    $weightTotal = 0.0;
    $seenQuestionIds = [];
    foreach ($objectives as $index => $row) {
        if (!is_array($row)) throw new Exception('Each objective must be an object.', 400);
        $questionId = (int)($row['question_id'] ?? $row['id'] ?? 0);
        if ($questionId > 0) {
            if (isset($seenQuestionIds[$questionId])) throw new Exception('The same objective cannot be selected more than once.', 400);
            $seenQuestionIds[$questionId] = true;
        }
        $question = $questionId > 0 ? ($availableMap[$questionId] ?? null) : null;

        if (!$question) {
            if ($questionId > 0) throw new Exception('One selected objective is inactive or not available for this employee.', 400);
            if (empty($config['allow_individual_objectives'])) throw new Exception('Individual objectives are disabled for this section.', 403);
            $objectiveText = opText($row['objective_text'] ?? $row['question_text'] ?? null);
            if (!$objectiveText) throw new Exception('Individual objective text is required.', 400);
            $question = [
                'id' => 0,
                'question_text' => $objectiveText,
                'measure_target' => null,
                'evidence_source' => null,
                'weight_percent' => null,
                'scope' => 'individual',
            ];
        }

        $target = opText(array_key_exists('target', $row) ? $row['target'] : ($question['measure_target'] ?? null));
        $evidence = opText(array_key_exists('evidence_source', $row) ? $row['evidence_source'] : ($question['evidence_source'] ?? null), 500);
        $weight = null;
        if (array_key_exists('weight_percent', $row) && $row['weight_percent'] !== '' && $row['weight_percent'] !== null) {
            if (!is_numeric($row['weight_percent'])) throw new Exception('Objective weight must be numeric.', 400);
            $weight = round((float)$row['weight_percent'], 2);
            if ($weight <= 0 || $weight > 100) throw new Exception('Each objective weight must be greater than 0 and no more than 100%.', 400);
        } elseif (!empty($config['require_weights']) && !$lock && isset($question['weight_percent']) && $question['weight_percent'] !== null) {
            $weight = round((float)$question['weight_percent'], 2);
        }

        if ($lock && !empty($config['require_target']) && !$target) throw new Exception('A target/measure is required for every selected objective before locking.', 400);
        if ($lock && !empty($config['require_evidence']) && !$evidence) throw new Exception('An evidence source is required for every selected objective before locking.', 400);
        if ($lock && !empty($config['require_weights']) && $weight === null) throw new Exception('A weight is required for every selected objective before locking.', 400);
        if ($weight !== null) $weightTotal += $weight;

        $normalized[] = [
            'question_id' => $questionId,
            'question_text' => $question['question_text'],
            'target' => $target,
            'evidence_source' => $evidence,
            'weight_percent' => $weight,
            'sort_order' => $index + 1,
        ];
    }

    if ($lock && !empty($config['require_weights'])) {
        $requiredTotal = (float)$config['required_weight_total'];
        if (abs($weightTotal - $requiredTotal) > 0.01) {
            throw new ApiException('objective.totalWrong', ['required' => $requiredTotal, 'actual' => $weightTotal], 400, 'Objective weights must total exactly ' . rtrim(rtrim(number_format($requiredTotal,2,'.',''),'0'),'.') . '%. Current total: ' . rtrim(rtrim(number_format($weightTotal,2,'.',''),'0'),'.') . '%.');
        }
    }

    $supervisorId = (int)$ctx['supervisor_id'];
    $department = (string)($ctx['staff']['department'] ?? '');

    $conn->begin_transaction();
    try {
        // Consistent write lock order: appraisal row before plan row.
        $lockedAppraisal=opFindSubmittedAppraisal($conn,$cycleId,$staffUserId,true);
        if ($lockedAppraisal) {
            apAssertSupervisorUpdateEligible(apCanSupervisorUpdateAppraisal($conn,$userData,null,$lockedAppraisal),true);
        }
        $lockedPlan=opGetPlanForUpdate($conn,$sectionId,$staffUserId);
        if ($lockedAppraisal && (!$lockedPlan || $lockedPlan['status'] !== 'draft')) {
            throw new Exception('Unlock the submitted objective plan before making changes.',409);
        }
        if (!$lockedAppraisal && $lockedPlan && $lockedPlan['status'] === 'locked') {
            throw new Exception('This objective plan is locked. Unlock it before making changes.',409);
        }
        if ($profileId > 0 && !$lockedAppraisal) {
            $profileAssign = $conn->prepare('INSERT INTO appraisal_staff_scoring_profiles (cycle_id, staff_user_id, profile_id, assigned_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE profile_id=VALUES(profile_id), assigned_by=VALUES(assigned_by), updated_at=CURRENT_TIMESTAMP');
            if (!$profileAssign) throw new Exception('Database error: ' . $conn->error, 500);
            $profileAssign->bind_param('iiii', $cycleId, $staffUserId, $profileId, $loggedInUserId);
            if (!$profileAssign->execute()) throw new Exception('Unable to save employee appraisal level/profile: ' . $profileAssign->error, 500);
            $profileAssign->close();
        }

        foreach ($normalized as &$row) {
            if ($row['question_id'] > 0) continue;
            $insertQ = $conn->prepare('INSERT INTO kpi_questions (company_id, section_id, department, supervisor_id, staff_user_id, question_text, objective_code, measure_target, evidence_source, review_status, review_note, weight_percent, sort_order, is_active, created_by, updated_by) VALUES (?, ?, ?, NULL, ?, ?, NULL, ?, ?, \'ready\', NULL, ?, ?, 1, ?, ?)');
            if (!$insertQ) throw new Exception('Database error: ' . $conn->error, 500);
            $insertQ->bind_param('iisisssdiii', $companyId, $sectionId, $department, $staffUserId, $row['question_text'], $row['target'], $row['evidence_source'], $row['weight_percent'], $row['sort_order'], $loggedInUserId, $loggedInUserId);
            if (!$insertQ->execute()) throw new Exception('Unable to save individual objective: ' . $insertQ->error, 500);
            $row['question_id'] = (int)$insertQ->insert_id;
            $insertQ->close();
        }
        unset($row);

        $delete = $conn->prepare('DELETE FROM staff_kpi_assignments WHERE section_id=? AND staff_user_id=?');
        $delete->bind_param('ii', $sectionId, $staffUserId);
        if (!$delete->execute()) throw new Exception('Unable to replace objective assignments: ' . $delete->error, 500);
        $delete->close();

        $insert = $conn->prepare('INSERT INTO staff_kpi_assignments (section_id, staff_user_id, kpi_question_id, weight_percent, target_override, evidence_override, assigned_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        if (!$insert) throw new Exception('Database error: ' . $conn->error, 500);
        foreach ($normalized as $row) {
            $insert->bind_param('iiidssi', $sectionId, $staffUserId, $row['question_id'], $row['weight_percent'], $row['target'], $row['evidence_source'], $loggedInUserId);
            if (!$insert->execute()) throw new Exception('Unable to save objective assignment: ' . $insert->error, 500);
        }
        $insert->close();

        $status = $lock ? 'locked' : 'draft';
        $lockedAtSql = $lock ? 'NOW()' : 'NULL';
        $lockedBySql = $lock ? (string)$loggedInUserId : 'NULL';
        $planSql = "INSERT INTO staff_objective_plans (company_id, cycle_id, section_id, staff_user_id, supervisor_id, status, locked_at, locked_by, created_by, updated_by) VALUES ({$companyId},{$cycleId},{$sectionId},{$staffUserId},{$supervisorId},'{$status}',{$lockedAtSql},{$lockedBySql},{$loggedInUserId},{$loggedInUserId}) ON DUPLICATE KEY UPDATE supervisor_id=VALUES(supervisor_id), status=VALUES(status), locked_at=VALUES(locked_at), locked_by=VALUES(locked_by), updated_by=VALUES(updated_by)";
        if (!$conn->query($planSql)) throw new Exception('Unable to save objective plan: ' . $conn->error, 500);
        $planId = (int)($existingPlan['id'] ?? $conn->insert_id);
        if ($planId <= 0) {
            $savedPlan = opGetPlan($conn, $sectionId, $staffUserId);
            $planId = (int)($savedPlan['id'] ?? 0);
        }

        if ($lockedAppraisal && $lock) {
            $submittedSnapshot=opSubmittedObjectiveSnapshot($conn,(int)$lockedAppraisal['id'],$sectionId);
            $lockedItems=opSelectedObjectives($conn,$sectionId,$staffUserId);
            $changed=opObjectiveItemsDiffer($submittedSnapshot,$lockedItems);
            $flag=$conn->prepare('UPDATE appraisals SET objectives_changed_at=' . ($changed ? 'NOW()' : 'NULL') . ' WHERE id=?');
            if (!$flag) throw new Exception('Unable to record the objective-change state.',500);
            $appId=(int)$lockedAppraisal['id'];
            $flag->bind_param('i',$appId);
            if (!$flag->execute()) throw new Exception('Unable to record the objective-change state.',500);
            $flag->close();
            opMarkObjectiveRelocked($conn,$appId,$planId,$lockedItems);
        }
        $staffName = trim((string)($ctx['staff']['fullname'] ?: (($ctx['staff']['first_name'] ?? '') . ' ' . ($ctx['staff']['last_name'] ?? ''))));
        $auditDescription = "{$loggedInEmail} " . ($lock ? 'locked' : 'saved') . " {$count} objective(s) for {$staffName} in {$ctx['section']['cycle_year']}.";
        if (!empty($ctx['write_context']) && companyUserWriteIsGranted($ctx['write_context'])) {
            companySupervisorWriteAudit($conn, $userData, $companyId,
                $lock ? 'lock_objective_plan' : 'save_objective_plan', 'staff_objective_plans',
                $planId, $auditDescription);
        } else {
            opAudit($conn, $companyId, $loggedInUserId,
                $lock ? 'lock_objective_plan' : 'save_objective_plan', $planId, $auditDescription);
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    http_response_code(200);
    echo json_encode(apiMessagePayload('Success', $lock ? 'Objectives locked successfully.' : 'Objective draft saved successfully.', ['data' => opPayload($conn, $ctx)]));
} catch (Throwable $e) {
    $code=(int)$e->getCode(); $code=($code>=400&&$code<=599)?$code:500;
    http_response_code($code);
    echo json_encode(apiErrorPayload($e));
}
