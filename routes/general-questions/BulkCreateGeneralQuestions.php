<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/CompanySetupWrite.php';
require_once __DIR__ . '/GeneralQuestionFramework.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Bad Request: Only POST method is allowed", 400);
    }

    $userData          = requireRoles(['super_admin', 'admin']);
    $setupContext = companySetupWriteContext($userData);
    $loggedInUserId    = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $loggedInUserRole  = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) throw new Exception("Invalid request format.", 400);

    if (!isset($data['section_id']) || !is_numeric($data['section_id'])) {
        throw new Exception("Field 'section_id' is required.", 400);
    }

    if (!isset($data['questions']) || !is_array($data['questions']) || count($data['questions']) === 0) {
        throw new Exception("Field 'questions' must be a non-empty array.", 400);
    }

    $sectionId = (int) $data['section_id'];
    $isActive  = isset($data['is_active']) ? (int) $data['is_active'] : 1;

    $sectionStmt = $conn->prepare("
        SELECT s.id, s.company_id, s.cycle_id, s.type, s.code, s.label, ac.year AS cycle_year
        FROM appraisal_sections s
        INNER JOIN appraisal_cycles ac ON ac.id = s.cycle_id
        WHERE s.id = ?
        LIMIT 1
    ");
    if (!$sectionStmt) throw new Exception("Database error: " . $conn->error, 500);
    $sectionStmt->bind_param("i", $sectionId);
    $sectionStmt->execute();
    $section = $sectionStmt->get_result()->fetch_assoc();
    $sectionStmt->close();

    if (!$section) throw new Exception("Section not found.", 404);
    if ($section['type'] !== 'general') throw new Exception("Selected section is not a general section.", 400);

    $companyId = (int) $section['company_id'];

    companySetupAssertCompany($userData, $setupContext, $companyId);

    $cleanQuestions = [];
    foreach ($data['questions'] as $index => $row) {
        if (!is_array($row)) {
            throw new Exception("Question row " . ($index + 1) . " is invalid.", 400);
        }

        $questionText = isset($row['question_text']) ? trim($row['question_text']) : '';
        if ($questionText === '') {
            throw new Exception("Question text is required on row " . ($index + 1) . ".", 400);
        }

        $framework = gqFrameworkNormalize($row, $index);
        $cleanQuestions[] = array_merge($framework, [
            'question_text' => $questionText,
            'sort_order'   => isset($row['sort_order']) ? (int) $row['sort_order'] : $index,
        ]);
    }

    $conn->begin_transaction();

    $insertStmt = $conn->prepare("
        INSERT INTO general_questions
            (company_id, section_id, question_text, question_code, question_label, anchor_low_value, anchor_low_text, anchor_mid_value, anchor_mid_text, anchor_high_value, anchor_high_text, sort_order, is_active, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$insertStmt) throw new Exception("Database error: " . $conn->error, 500);

    $createdIds = [];
    foreach ($cleanQuestions as $row) {
        $questionText = $row['question_text'];
        $questionCode = $row['question_code'];
        $questionLabel = $row['question_label'];
        $lowValue = $row['anchor_low_value'];
        $lowText = $row['anchor_low_text'];
        $midValue = $row['anchor_mid_value'];
        $midText = $row['anchor_mid_text'];
        $highValue = $row['anchor_high_value'];
        $highText = $row['anchor_high_text'];
        $sortOrder = $row['sort_order'];

        $insertStmt->bind_param(
            "iisssdsdsdsiii",
            $companyId, $sectionId, $questionText, $questionCode, $questionLabel,
            $lowValue, $lowText, $midValue, $midText, $highValue, $highText,
            $sortOrder, $isActive, $loggedInUserId
        );

        if (!$insertStmt->execute()) {
            throw new Exception("Failed to create general question: " . $insertStmt->error, 500);
        }

        $questionId = (int)$insertStmt->insert_id;
        gqFrameworkSaveProfileRules($conn, $questionId, $companyId, (int)$section['cycle_id'], $row['profile_ids']);
        $createdIds[] = $questionId;
    }
    $insertStmt->close();

    $logStmt = $conn->prepare("
        INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if ($logStmt && !companyUserWriteIsGranted($setupContext)) {
        $action      = "bulk_create_general_questions";
        $targetTable = "general_questions";
        $targetId    = $createdIds[0] ?? 0;
        $count       = count($createdIds);
        $description = "{$loggedInUserEmail} created {$count} general question(s) for section {$section['code']}.";
        $logStmt->bind_param("iissis", $companyId, $loggedInUserId, $action, $targetTable, $targetId, $description);
        $logStmt->execute();
        $logStmt->close();
    }

    if ($logStmt && companyUserWriteIsGranted($setupContext)) $logStmt->close();
    companySetupAuditGranted($conn, $userData, $setupContext, $companyId,
        'bulk_create_general_questions', 'general_questions', (int)($createdIds[0] ?? 0),
        'created question IDs: ' . implode(',', $createdIds) . ' for section #' . $sectionId);
    $conn->commit();

    $placeholders = implode(',', array_fill(0, count($createdIds), '?'));
    $types = str_repeat('i', count($createdIds));
    $fetchStmt = $conn->prepare("
        SELECT gq.*, s.code AS section_code, s.label AS section_label, ac.year AS cycle_year
        FROM general_questions gq
        INNER JOIN appraisal_sections s ON s.id = gq.section_id
        INNER JOIN appraisal_cycles ac ON ac.id = s.cycle_id
        WHERE gq.id IN ($placeholders)
        ORDER BY gq.sort_order ASC, gq.id ASC
    ");
    $fetchStmt->bind_param($types, ...$createdIds);
    $fetchStmt->execute();
    $created = $fetchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $fetchStmt->close();
    gqFrameworkAttachProfileIds($conn, $created);

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => count($createdIds) . " general question(s) created successfully",
        "data"    => $created
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn instanceof mysqli) $conn->rollback();
    error_log("BulkCreateGeneralQuestions Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
