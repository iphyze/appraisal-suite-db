<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/AdminAppraisalVisibility.php';
require_once 'includes/CompanySupervisorWrite.php';

header('Content-Type: application/json; charset=UTF-8');

function bulkProfileResponse($status, $message, $data = [], $code = 200) {
    http_response_code($code);
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]);
    exit;
}

function bulkProfileRoleKey($value) {
    return strtolower(str_replace(' ', '_', trim((string)$value)));
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Bad Request: Only POST method is allowed.', 405);
    }

    $userData = requireRoles(['super_admin', 'admin', 'supervisor']);
    $loggedInUserId = (int)($userData['id'] ?? 0);
    $loggedInRoleKey = bulkProfileRoleKey($userData['role'] ?? '');
    $loggedInCompanyId = (int)($userData['company_id'] ?? 0);
    $loggedInCanAppraise = userCanConductAppraisals($userData);

    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) throw new Exception('Invalid request format.', 400);

    $cycleId = (int)($body['cycle_id'] ?? 0);
    $profileId = (int)($body['profile_id'] ?? 0);
    $rawStaffIds = is_array($body['staff_user_ids'] ?? null) ? $body['staff_user_ids'] : [];
    $staffIds = array_values(array_unique(array_filter(array_map('intval', $rawStaffIds), static fn($id) => $id > 0)));

    if ($cycleId <= 0 || $profileId <= 0 || empty($staffIds)) {
        throw new Exception('Cycle, appraisal level/profile and at least one employee are required.', 400);
    }
    if (count($staffIds) > 500) {
        throw new Exception('You can assign an appraisal level/profile to a maximum of 500 employees at a time.', 400);
    }

    $cycleStmt = $conn->prepare('SELECT id, company_id, title, year FROM appraisal_cycles WHERE id=? LIMIT 1');
    $cycleStmt->bind_param('i', $cycleId);
    $cycleStmt->execute();
    $cycle = $cycleStmt->get_result()->fetch_assoc();
    $cycleStmt->close();
    if (!$cycle) throw new Exception('Selected appraisal cycle was not found.', 404);

    $companyId = (int)$cycle['company_id'];
    // Reject unauthorized foreign-cycle requests before loading profile or
    // appraisee data. Home-cycle own-team actions are resolved after loading
    // assignment ownership and ignore an unrelated View Context selection.
    $precheckedActingContext = null;
    if ($loggedInRoleKey === 'admin' && $companyId !== $loggedInCompanyId) {
        $precheckedActingContext = companySupervisorWriteContext($userData);
        companySupervisorWriteAssertCompany($userData, $precheckedActingContext, $companyId);
    }

    $profileStmt = $conn->prepare('SELECT id, code, label, description FROM appraisal_scoring_profiles WHERE id=? AND company_id=? AND cycle_id=? AND is_active=1 LIMIT 1');
    $profileStmt->bind_param('iii', $profileId, $companyId, $cycleId);
    $profileStmt->execute();
    $profile = $profileStmt->get_result()->fetch_assoc();
    $profileStmt->close();
    if (!$profile) throw new Exception('Selected appraisal level/profile is not valid for this cycle.', 400);

    $staffPlaceholders = implode(',', array_fill(0, count($staffIds), '?'));
    $sql = "SELECT s.id, s.company_id, s.fullname, s.first_name, s.last_name,
                   s.staff_type, sa.supervisor_id, ap.id AS appraisal_id
            FROM users s
            INNER JOIN supervisor_assignments sa ON sa.staff_id=s.id AND sa.cycle_id=?
            LEFT JOIN appraisals ap ON ap.staff_user_id=s.id AND ap.cycle_id=sa.cycle_id
            WHERE s.company_id=? AND s.is_active=1 AND s.id IN ({$staffPlaceholders})";
    $staffStmt = $conn->prepare($sql);
    if (!$staffStmt) throw new Exception('Database error: ' . $conn->error, 500);
    $staffParams = array_merge([$cycleId, $companyId], $staffIds);
    $staffStmt->bind_param('ii' . str_repeat('i', count($staffIds)), ...$staffParams);
    $staffStmt->execute();
    $result = $staffStmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) $rows[(int)$row['id']] = $row;
    $staffStmt->close();

    if (count($rows) !== count($staffIds)) {
        throw new Exception('One or more selected employees are not assigned to this appraisal cycle.', 400);
    }

    $ownTeamAction = $loggedInRoleKey === 'admin'
        && $companyId === $loggedInCompanyId
        && $loggedInCanAppraise;
    foreach ($rows as $row) {
        if ((int)$row['supervisor_id'] !== $loggedInUserId) $ownTeamAction = false;
    }
    $actingContext = null;
    $grantedCompanyWrite = false;
    if ($loggedInRoleKey === 'admin') {
        $actingContext = $ownTeamAction
            ? companyAccessHomeContext($userData, $loggedInCompanyId)
            : ($precheckedActingContext ?? companySupervisorWriteContext($userData));
        companySupervisorWriteAssertCompany($userData, $actingContext, $companyId);
        $grantedCompanyWrite = companyUserWriteIsGranted($actingContext);
    }
    if ($loggedInRoleKey === 'supervisor' && $companyId !== $loggedInCompanyId) {
        throw new Exception('Selected appraisal cycle is outside your home company.', 403);
    }

    foreach ($staffIds as $staffId) {
        $row = $rows[$staffId];
        if (!empty($row['appraisal_id'])) {
            $name = trim((string)($row['fullname'] ?: (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))));
            throw new Exception(($name ?: 'A selected employee') . ' already has a submitted appraisal. The appraisal level/profile can no longer be changed.', 409);
        }

        if ($loggedInRoleKey === 'supervisor') {
            if (!$loggedInCanAppraise || (int)$row['supervisor_id'] !== $loggedInUserId) {
                throw new Exception('Supervisors/HODs can only assign appraisal levels to employees assigned to them.', 403);
            }
        } elseif ($loggedInRoleKey === 'admin') {
            companySupervisorWriteAssertSubject($userData, $actingContext, $row);
            companySupervisorWriteAssertHomeProfileScope($userData, $actingContext, $row['staff_type'], (int)$row['supervisor_id']);
            companySupervisorWriteAssertPeer($conn, $userData, $actingContext,
                $staffId, (int)$row['supervisor_id']);
        }
    }

    $conn->begin_transaction();
    try {
        $saveStmt = $conn->prepare('INSERT INTO appraisal_staff_scoring_profiles (cycle_id, staff_user_id, profile_id, assigned_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE profile_id=VALUES(profile_id), assigned_by=VALUES(assigned_by), updated_at=CURRENT_TIMESTAMP');
        if (!$saveStmt) throw new Exception('Database error: ' . $conn->error, 500);

        $auditStmt = $grantedCompanyWrite ? null : $conn->prepare("INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description) VALUES (?, ?, 'bulk_assign_staff_scoring_profile', 'users', ?, ?)");

        foreach ($staffIds as $staffId) {
            $saveStmt->bind_param('iiii', $cycleId, $staffId, $profileId, $loggedInUserId);
            if (!$saveStmt->execute()) throw new Exception('Unable to save appraisal level/profile: ' . $saveStmt->error, 500);

            if ($grantedCompanyWrite) {
                $row = $rows[$staffId];
                $name = trim((string)($row['fullname'] ?: (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))));
                companySupervisorWriteAudit($conn, $userData, $companyId,
                    'bulk_assign_staff_scoring_profile', 'users', $staffId,
                    'Assigned appraisal level/profile ' . $profile['label'] . ' to ' . ($name ?: ('staff #' . $staffId)) . ' via bulk assignment.');
            } elseif ($auditStmt) {
                $row = $rows[$staffId];
                $staffName = trim((string)($row['fullname'] ?: (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))));
                $description = 'Assigned appraisal level/profile ' . $profile['label'] . ' to ' . ($staffName ?: ('staff #' . $staffId)) . ' via bulk assignment.';
                $auditStmt->bind_param('iiis', $companyId, $loggedInUserId, $staffId, $description);
                if (!$auditStmt->execute()) throw new Exception('Unable to write appraisal level/profile audit log.', 500);
            }
        }

        $saveStmt->close();
        if ($auditStmt) $auditStmt->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    $profile['id'] = (int)$profile['id'];
    bulkProfileResponse('Success', count($staffIds) . ' employee appraisal level/profile assignment' . (count($staffIds) === 1 ? '' : 's') . ' saved.', [
        'assigned_count' => count($staffIds),
        'staff_user_ids' => $staffIds,
        'profile' => $profile,
    ]);
} catch (Throwable $e) {
    $code = (int)$e->getCode();
    if ($code < 400 || $code > 599) $code = 500;
    bulkProfileResponse('Failed', $e->getMessage(), [], $code);
}
