<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/CompanySupervisorWrite.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception("Bad Request: Only DELETE method is allowed", 400);
    }

    $userData          = requireRoles(['super_admin', 'admin']);
    $loggedInUserId    = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $loggedInRole      = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];
    $actingContext = companySupervisorWriteContext($userData);

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) throw new Exception("Invalid request format.", 400);

    foreach (['supervisor_id', 'staff_ids'] as $field) {
        if (!isset($data[$field])) {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    $supervisorId = (int) $data['supervisor_id'];
    $staffIds     = $data['staff_ids'];
    $cycleId      = isset($data['cycle_id']) ? (int) $data['cycle_id'] : null;

    if (!is_array($staffIds) || count($staffIds) === 0) {
        throw new Exception("'staff_ids' must be a non-empty array.", 400);
    }

    $staffIds = array_values(array_unique(array_filter(
        array_map('intval', $staffIds),
        fn($id) => $id > 0
    )));

    if (empty($staffIds)) throw new Exception("No valid staff IDs provided.", 400);

    // Validate supervisor
    $supStmt = $conn->prepare("
        SELECT u.id, u.company_id, u.staff_type, u.first_name, u.last_name, r.name AS role
        FROM users u INNER JOIN roles r ON r.id = u.role_id
        WHERE u.id = ? AND " . appraiserRoleWhere('r', 'u') . " LIMIT 1
    ");
    $supStmt->bind_param("i", $supervisorId);
    $supStmt->execute();
    $supervisor = $supStmt->get_result()->fetch_assoc();
    $supStmt->close();

    if (!$supervisor) throw new Exception("Supervisor not found.", 404);

    // The supervisor is the APPRAISER here, not the appraisee. Grant scope
    // restricts assigned employees below, not the supervisor directory.
    companySupervisorWriteAssertCompany($userData, $actingContext, (int)$supervisor['company_id']);

    // Resolve cycle
    if ($cycleId) {
        $cycleStmt = $conn->prepare("SELECT id, year, company_id FROM appraisal_cycles WHERE id = ? LIMIT 1");
        $cycleStmt->bind_param("i", $cycleId);
    } else {
        $cycleStmt = $conn->prepare("
            SELECT id, year, company_id FROM appraisal_cycles
            WHERE company_id = ? AND is_active = 1 LIMIT 1
        ");
        $cycleStmt->bind_param("i", $supervisor['company_id']);
    }
    $cycleStmt->execute();
    $cycle = $cycleStmt->get_result()->fetch_assoc();
    $cycleStmt->close();

    if (!$cycle) throw new Exception("No active appraisal cycle found.", 400);
    if ((int)$cycle['company_id'] !== (int)$supervisor['company_id']) {
        throw new Exception('Selected cycle does not belong to the supervisor company.', 403);
    }

    // For a granted company, verify EVERY target's type and peer-admin policy.
    // Also reject targets not actually assigned in the selected cycle.
    if (companyUserWriteIsGranted($actingContext)) {
        $targetPlaceholders = implode(',', array_fill(0, count($staffIds), '?'));
        $scopeStmt = $conn->prepare("SELECT u.id, u.company_id, u.staff_type
            FROM supervisor_assignments sa
            INNER JOIN users u ON u.id = sa.staff_id
            WHERE sa.supervisor_id = ? AND sa.cycle_id = ?
              AND u.company_id = ? AND u.id IN ({$targetPlaceholders})");
        if (!$scopeStmt) throw new Exception('Unable to validate assigned staff.', 500);
        $targetParams = array_merge([$supervisorId, (int)$cycle['id'], (int)$supervisor['company_id']], $staffIds);
        $scopeStmt->bind_param('iii' . str_repeat('i', count($staffIds)), ...$targetParams);
        $scopeStmt->execute();
        $targetRows = $scopeStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $scopeStmt->close();
        if (count($targetRows) !== count($staffIds)) {
            throw new Exception('One or more selected employees are not assigned in this company and cycle.', 403);
        }
        foreach ($targetRows as $target) {
            companySupervisorWriteAssertSubject($userData, $actingContext, $target);
            companySupervisorWriteAssertPeer($conn, $userData, $actingContext, (int)$target['id'], $supervisorId);
        }
    }

    /**
     * Block unassignment if any of the staff have already been appraised
     * in this cycle by this supervisor — unassigning would orphan the appraisal
     */
    $placeholders = implode(',', array_fill(0, count($staffIds), '?'));
    $appraisalCheckStmt = $conn->prepare("
        SELECT staff_user_id FROM appraisals
        WHERE supervisor_id = ?
          AND cycle_id      = ?
          AND staff_user_id IN ({$placeholders})
    ");
    $checkTypes  = "ii" . str_repeat('i', count($staffIds));
    $checkParams = array_merge([$supervisorId, $cycle['id']], $staffIds);
    $appraisalCheckStmt->bind_param($checkTypes, ...$checkParams);
    $appraisalCheckStmt->execute();
    $appraised = $appraisalCheckStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $appraisalCheckStmt->close();

    if (!empty($appraised)) {
        $appraisedIds = array_column($appraised, 'staff_user_id');
        throw new Exception(
            "Cannot unassign staff member(s) with ID(s) " . implode(', ', $appraisedIds) .
            " because they have already been appraised this cycle. " .
            "Delete the appraisal first if you need to reassign them.",
            400
        );
    }

    // Delete assignments
    $conn->begin_transaction();
    try {
        $deleteStmt = $conn->prepare("
            DELETE FROM supervisor_assignments
            WHERE supervisor_id = ? AND cycle_id = ? AND staff_id IN ({$placeholders})
        ");
        $deleteTypes  = "ii" . str_repeat('i', count($staffIds));
        $deleteParams = array_merge([$supervisorId, $cycle['id']], $staffIds);
        $deleteStmt->bind_param($deleteTypes, ...$deleteParams);
        if (!$deleteStmt->execute()) {
            throw new Exception('Unable to unassign subordinate.', 500);
        }
        $removedCount = $deleteStmt->affected_rows;
        $deleteStmt->close();

        $supName = $supervisor['first_name'] . ' ' . $supervisor['last_name'];
        companySupervisorWriteAudit($conn, $userData, (int)$supervisor['company_id'],
            'unassign_subordinates', 'supervisor_assignments', $supervisorId,
            "Removed {$removedCount} staff member(s) from {$supName} for cycle {$cycle['year']}");

        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status"  => "Success",
            "message" => "{$removedCount} staff member(s) unassigned successfully",
            "data"    => [
                "supervisor_id"   => $supervisorId,
                "supervisor_name" => $supervisor['first_name'] . " " . $supervisor['last_name'],
                "cycle_id"        => $cycle['id'],
                "cycle_year"      => $cycle['year'],
                "removed_ids"     => $staffIds,
                "removed_count"   => $removedCount,
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("UnassignSubordinates Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
