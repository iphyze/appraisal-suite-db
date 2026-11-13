<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/CompanySetupWrite.php';
require_once __DIR__ . '/ObjectiveSetupConfig.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Bad Request: Only PUT method is allowed", 400);
    }

    $userData          = requireRoles(['super_admin', 'admin']);
    $setupContext = companySetupWriteContext($userData);
    $loggedInUserId    = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $loggedInUserRole  = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) throw new Exception("Invalid request format.", 400);
    if (!isset($data['id']) || !is_numeric($data['id'])) {
        throw new Exception("Field 'id' is required.", 400);
    }

    $sectionId = (int) $data['id'];

    // Fetch existing
    $checkStmt = $conn->prepare("
        SELECT * FROM appraisal_sections WHERE id = ? LIMIT 1
    ");
    $checkStmt->bind_param("i", $sectionId);
    $checkStmt->execute();
    $existing = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    if (!$existing) throw new Exception("Section not found.", 404);

    // Company check
    companySetupAssertCompany($userData, $setupContext, (int)$existing['company_id']);

    $objectiveSetupConfig = null;
    if ($existing['type'] === 'kpi' && isset($data['objective_setup_config']) && is_array($data['objective_setup_config'])) {
        $objectiveSetupConfig = objectiveSetupNormalize($data['objective_setup_config']);
    }

    $updateFields = [];
    $params       = [];
    $types        = "";

    if (isset($data['label']) && trim($data['label']) !== '') {
        $updateFields[] = "label = ?";
        $params[]       = trim($data['label']);
        $types         .= "s";
    }

    if (isset($data['description'])) {
        $updateFields[] = "description = ?";
        $params[]       = trim($data['description']) ?: null;
        $types         .= "s";
    }

    if (isset($data['code'])) {
        $code = strtoupper(trim($data['code']));
        // Check duplicate code (exclude self)
        $dupStmt = $conn->prepare("
            SELECT id FROM appraisal_sections
            WHERE cycle_id = ? AND company_id = ? AND code = ? AND id != ?
            LIMIT 1
        ");
        $dupStmt->bind_param("iisi", $existing['cycle_id'], $existing['company_id'], $code, $sectionId);
        $dupStmt->execute();
        if ($dupStmt->get_result()->num_rows > 0) {
            throw new Exception("Section code '{$code}' already exists for this cycle.", 400);
        }
        $dupStmt->close();
        $updateFields[] = "code = ?";
        $params[]       = $code;
        $types         .= "s";
    }

    if (isset($data['weight'])) {
        $newWeight = (float) $data['weight'];
        if ($newWeight <= 0 || $newWeight > 100) {
            throw new Exception("Weight must be between 0.01 and 100.", 400);
        }

        // Recalculate total excluding self
        $weightStmt = $conn->prepare("
            SELECT COALESCE(SUM(weight), 0) AS total_weight
            FROM appraisal_sections
            WHERE cycle_id = ? AND company_id = ? AND is_active = 1 AND id != ?
        ");
        $weightStmt->bind_param("iii", $existing['cycle_id'], $existing['company_id'], $sectionId);
        $weightStmt->execute();
        $otherTotal = (float) $weightStmt->get_result()->fetch_assoc()['total_weight'];
        $weightStmt->close();

        $newTotal = $otherTotal + $newWeight;
        if ($newTotal > 100) {
            $remaining = 100 - $otherTotal;
            throw new Exception(
                "This weight would bring the total to {$newTotal}%. " .
                "Maximum is 100%. Available: {$remaining}%.",
                400
            );
        }

        $updateFields[] = "weight = ?";
        $params[]       = $newWeight;
        $types         .= "d";
    }

    if (isset($data['default_rating_mode'])) {
        $defaultRatingMode = strtolower(trim((string)$data['default_rating_mode']));
        if (!in_array($defaultRatingMode, ['per_question', 'overall'], true)) {
            throw new Exception('Invalid default rating mode. Allowed: per_question, overall.', 400);
        }
        $updateFields[] = 'default_rating_mode = ?';
        $params[] = $defaultRatingMode;
        $types .= 's';
    }

    if (isset($data['lock_rating_mode'])) {
        $updateFields[] = 'lock_rating_mode = ?';
        $params[] = !empty($data['lock_rating_mode']) ? 1 : 0;
        $types .= 'i';
    }

    if (isset($data['sort_order'])) {
        $updateFields[] = "sort_order = ?";
        $params[]       = (int) $data['sort_order'];
        $types         .= "i";
    }

    if (isset($data['is_active'])) {
        $updateFields[] = "is_active = ?";
        $params[]       = (int) $data['is_active'];
        $types         .= "i";
    }

    if (empty($updateFields) && $objectiveSetupConfig === null) {
        throw new Exception("No valid fields provided for update.", 400);
    }

    $grantWrite = companyUserWriteIsGranted($setupContext);
    if ($grantWrite) $conn->begin_transaction();
    try {
    if (!empty($updateFields)) {
        $sql      = "UPDATE appraisal_sections SET " . implode(", ", $updateFields) . " WHERE id = ?";
        $params[] = $sectionId;
        $types   .= "i";

        $updateStmt = $conn->prepare($sql);
        if (!$updateStmt) throw new Exception("Database error: " . $conn->error, 500);
        $updateStmt->bind_param($types, ...$params);
        if (!$updateStmt->execute()) throw new Exception("Update failed: " . $updateStmt->error, 500);
        $updateStmt->close();
    }

    if ($existing['type'] === 'kpi' && $objectiveSetupConfig !== null) {
        objectiveSetupSave($conn, $sectionId, (int)$existing['company_id'], (int)$existing['cycle_id'], $objectiveSetupConfig, $loggedInUserId);
    }

    // Log
    $logStmt = $conn->prepare("
        INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if ($logStmt && !companyUserWriteIsGranted($setupContext)) {
        $action      = "update_section";
        $targetTable = "appraisal_sections";
        $description = "{$loggedInUserEmail} updated section ID: {$sectionId}";
        $logStmt->bind_param("iissis", $loggedInCompanyId, $loggedInUserId, $action, $targetTable, $sectionId, $description);
        $logStmt->execute();
        $logStmt->close();
    } elseif ($logStmt) {
        $logStmt->close();
    }

    companySetupAuditGranted($conn, $userData, $setupContext,
        (int)$existing['company_id'], 'update_section', 'appraisal_sections',
        $sectionId, 'updated section ' . $sectionId);
    if ($grantWrite) $conn->commit();
    } catch (Throwable $mutationError) {
        if ($grantWrite) $conn->rollback();
        throw $mutationError;
    }

    // Fetch updated
    $fetchStmt = $conn->prepare("
        SELECT s.*, ac.year AS cycle_year, ac.title AS cycle_title,
               c.code AS company_code, c.name AS company_name,
               (SELECT COALESCE(SUM(s2.weight),0) FROM appraisal_sections s2
                WHERE s2.cycle_id = s.cycle_id AND s2.company_id = s.company_id AND s2.is_active = 1
               ) AS total_weight_used
        FROM appraisal_sections s
        INNER JOIN appraisal_cycles ac ON ac.id = s.cycle_id
        INNER JOIN companies c ON c.id = s.company_id
        WHERE s.id = ? LIMIT 1
    ");
    $fetchStmt->bind_param("i", $sectionId);
    $fetchStmt->execute();
    $updated = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Section updated successfully",
        "data"    => $updated
    ]);

} catch (Exception $e) {
    error_log("UpdateSection Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
