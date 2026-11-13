<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/CompanyKpiAssignmentAccess.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Bad Request: Only POST method is allowed", 400);
    }

    $userData          = requireRoles(['super_admin', 'admin', 'supervisor']);
    $loggedInUserId    = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $loggedInUserRole  = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) throw new Exception("Invalid request format.", 400);

    foreach (['staff_user_id', 'section_id', 'question_ids'] as $field) {
        if (!isset($data[$field])) {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    $staffUserId = (int) $data['staff_user_id'];
    $sectionId   = (int) $data['section_id'];
    $questionIds = $data['question_ids'];
    $questionWeightsInput = $data['question_weights'] ?? null;

    if (!is_array($questionIds)) {
        throw new Exception("'question_ids' must be an array. Pass an empty array [] to clear all assignments and revert to departmental default.", 400);
    }

    // Sanitise question IDs
    $questionIds = array_values(array_unique(array_filter(
        array_map('intval', $questionIds),
        fn($id) => $id > 0
    )));

    $questionWeights = [];
    if ($questionWeightsInput !== null) {
        if (!is_array($questionWeightsInput)) {
            throw new Exception("'question_weights' must be an object keyed by question ID when provided.", 400);
        }

        foreach ($questionIds as $questionId) {
            $rawWeight = $questionWeightsInput[(string)$questionId] ?? $questionWeightsInput[$questionId] ?? null;
            if ($rawWeight === null || $rawWeight === '') {
                throw new Exception("Every selected KPI question needs a weight when custom assignment weights are provided.", 400);
            }
            if (!is_numeric($rawWeight)) {
                throw new Exception("KPI question weight must be numeric.", 400);
            }
            $weight = round((float)$rawWeight, 2);
            if ($weight <= 0 || $weight > 100) {
                throw new Exception("Each KPI question weight must be greater than 0 and no more than 100.", 400);
            }
            $questionWeights[$questionId] = $weight;
        }

        $weightTotal = round(array_sum($questionWeights), 2);
        if (abs($weightTotal - 100.0) > 0.01) {
            throw new Exception("Custom KPI assignment weights must total exactly 100%. Current total: {$weightTotal}%.", 400);
        }
    }

    // Validate staff exists and belongs to same company
    $staffStmt = $conn->prepare("
        SELECT u.id, u.first_name, u.last_name, u.department, u.company_id, u.staff_type
        FROM users u WHERE u.id = ? LIMIT 1
    ");
    $staffStmt->bind_param("i", $staffUserId);
    $staffStmt->execute();
    $staffMember = $staffStmt->get_result()->fetch_assoc();
    $staffStmt->close();

    if (!$staffMember) throw new Exception("Staff member not found.", 404);

    // Resolve the section against its actual company before selecting the
    // write context. A client-supplied section or staff ID is never authority.
    $sectionStmt = $conn->prepare('SELECT id, type, company_id FROM appraisal_sections WHERE id=? LIMIT 1');
    $sectionStmt->bind_param('i', $sectionId);
    $sectionStmt->execute();
    $section = $sectionStmt->get_result()->fetch_assoc();
    $sectionStmt->close();
    if (!$section) throw new Exception('KPI section not found.', 404);
    companyKpiAssignmentAssertSection($staffMember, $section);

    $ownTeam = in_array($loggedInUserRole, ['admin', 'supervisor'], true)
        && (int)$staffMember['company_id'] === $loggedInCompanyId
        && companyKpiAssignmentIsOwnTeam($conn, $userData, $staffUserId, $sectionId);
    $assignmentContext = companyKpiAssignmentContext($userData, $staffMember, $ownTeam, true);
    companyKpiAssignmentAssertPeer($conn, $userData, $assignmentContext, $staffUserId);
    $grantWrite = $assignmentContext && companyUserWriteIsGranted($assignmentContext);

    $objectiveConfigStmt = $conn->prepare("SELECT enabled FROM appraisal_section_objective_settings WHERE section_id = ? LIMIT 1");
    if ($objectiveConfigStmt) {
        $objectiveConfigStmt->bind_param('i', $sectionId);
        $objectiveConfigStmt->execute();
        $objectiveConfig = $objectiveConfigStmt->get_result()->fetch_assoc();
        $objectiveConfigStmt->close();
        if ($objectiveConfig && (int)$objectiveConfig['enabled'] === 1) {
            throw new Exception('Use the per-staff Objective Setup workflow for this section. Direct KPI assignment changes are disabled.', 409);
        }
    }

    /**
     * If question_ids is empty → clear all assignments for this staff + section.
     * This reverts them to the departmental default (fallback logic in GetKpiAssignments).
     */
    if (empty($questionIds)) {
        // Clearing assignments is also a mutation. A granted-company clear
        // must be atomic with its required audit entry.
        if ($grantWrite) $conn->begin_transaction();
        try {
            $clearStmt = $conn->prepare("
                DELETE FROM staff_kpi_assignments
                WHERE section_id = ? AND staff_user_id = ?
            ");
            if (!$clearStmt) throw new Exception('Unable to prepare KPI clear operation.', 500);
            $clearStmt->bind_param("ii", $sectionId, $staffUserId);
            if (!$clearStmt->execute()) throw new Exception('Unable to clear KPI assignments.', 500);
            $clearStmt->close();
            companyKpiAssignmentAudit($conn, $userData, $assignmentContext,
                (int)$staffMember['company_id'], $staffUserId, $sectionId, 0, true);
            if ($grantWrite) $conn->commit();
        } catch (Throwable $e) {
            if ($grantWrite) $conn->rollback();
            throw $e;
        }

        http_response_code(200);
        echo json_encode([
            "status"  => "Success",
            "message" => "KPI assignments cleared. Staff will now use the departmental default questions.",
            "data"    => [
                "staff_user_id" => $staffUserId,
                "section_id"    => $sectionId,
                "question_ids"  => [],
                "source"        => "departmental_default"
            ]
        ]);
        exit;
    }

    // Validate all provided question IDs belong to this section and company
    $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
    $validStmt    = $conn->prepare("
        SELECT id, staff_user_id FROM kpi_questions
        WHERE id IN ({$placeholders})
          AND section_id  = ?
          AND company_id  = ?
          AND is_active   = 1
    ");
    $validTypes  = str_repeat('i', count($questionIds)) . "ii";
    $validParams = array_merge($questionIds, [$sectionId, (int) $staffMember['company_id']]);
    $validStmt->bind_param($validTypes, ...$validParams);
    $validStmt->execute();
    $validQuestions = $validStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $validStmt->close();

    // A staff-specific KPI may never be assigned to a different employee via
    // a foreign-company grant. Home-company behavior is unchanged.
    foreach ($validQuestions as $question) {
        companyKpiAssignmentAssertQuestion($assignmentContext, $staffUserId, $question);
    }
    $validIds    = array_column($validQuestions, 'id');
    $invalidIds  = array_diff($questionIds, $validIds);

    if (!empty($invalidIds)) {
        throw new Exception(
            "The following question IDs are invalid, inactive, or do not belong to this section: " .
            implode(', ', $invalidIds),
            400
        );
    }

    /**
     * Full replace strategy:
     * Delete all existing assignments for this staff + section,
     * then re-insert the new selection.
     * This makes it idempotent — call it multiple times with the same data safely.
     */
    $conn->begin_transaction();
    try {
        // Clear existing
        $deleteStmt = $conn->prepare("
            DELETE FROM staff_kpi_assignments
            WHERE section_id = ? AND staff_user_id = ?
        ");
        $deleteStmt->bind_param("ii", $sectionId, $staffUserId);
        $deleteStmt->execute();
        $deleteStmt->close();

        // Insert new selection
        $insertStmt = $conn->prepare("
            INSERT INTO staff_kpi_assignments (section_id, staff_user_id, kpi_question_id, weight_percent, assigned_by)
            VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($validIds as $qId) {
            $assignmentWeight = $questionWeights[$qId] ?? null;
            $insertStmt->bind_param("iiidi", $sectionId, $staffUserId, $qId, $assignmentWeight, $loggedInUserId);
            $insertStmt->execute();
        }
        $insertStmt->close();

        if ($grantWrite) {
            companyKpiAssignmentAudit($conn, $userData, $assignmentContext,
                (int)$staffMember['company_id'], $staffUserId, $sectionId, count($validIds), false);
        } else {
            // Preserve existing home-company/Super Admin audit conventions.
            $logStmt = $conn->prepare("
                INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            if ($logStmt) {
                $action      = "set_kpi_assignments";
                $targetTable = "staff_kpi_assignments";
                $targetId    = $staffUserId;
                $staffName   = $staffMember['first_name'] . " " . $staffMember['last_name'];
                $description = "{$loggedInUserEmail} set " . count($validIds) .
                               " KPI question(s) for {$staffName} in section ID {$sectionId}";
                $logStmt->bind_param(
                    "iissis",
                    $loggedInCompanyId, $loggedInUserId,
                    $action, $targetTable, $targetId,
                    $description
                );
                $logStmt->execute();
                $logStmt->close();
            }

        }

        $conn->commit();

        // Return the resolved question set
        $resolveStmt = $conn->prepare("
            SELECT
                kq.id,
                kq.question_text,
                COALESCE(ska.weight_percent, kq.weight_percent) AS weight_percent,
                ska.weight_percent AS assignment_weight_percent,
                kq.weight_percent AS configured_weight_percent,
                kq.sort_order,
                kq.department,
                CASE
                    WHEN kq.staff_user_id IS NOT NULL THEN 'individual'
                    WHEN kq.supervisor_id IS NOT NULL THEN 'supervisor'
                    ELSE 'department'
                END AS scope
            FROM staff_kpi_assignments ska
            INNER JOIN kpi_questions kq ON kq.id = ska.kpi_question_id
            WHERE ska.section_id    = ?
              AND ska.staff_user_id = ?
            ORDER BY kq.sort_order ASC
        ");
        $resolveStmt->bind_param("ii", $sectionId, $staffUserId);
        $resolveStmt->execute();
        $assignedQuestions = $resolveStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $resolveStmt->close();

        http_response_code(200);
        echo json_encode([
            "status"  => "Success",
            "message" => count($validIds) . " KPI question(s) assigned successfully",
            "data"    => [
                "staff"        => [
                    "id"   => $staffMember['id'],
                    "name" => $staffMember['first_name'] . " " . $staffMember['last_name'],
                ],
                "section_id"   => $sectionId,
                "is_custom"    => true,
                "source"       => "custom_selection",
                "has_custom_weights" => !empty($questionWeights),
                "questions"    => $assignedQuestions,
                "count"        => count($assignedQuestions),
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("SetKpiAssignments Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}