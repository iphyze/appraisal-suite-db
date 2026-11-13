<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/AdminAppraisalVisibility.php';
require_once 'includes/CompanySupervisorWrite.php';

header('Content-Type: application/json; charset=UTF-8');

function profileResponse($status, $message, $data = [], $code = 200) {
    http_response_code($code);
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Bad Request: Only POST method is allowed.', 405);
    }

    $userData = requireRoles(['super_admin', 'admin', 'supervisor']);
    $loggedInUserId = (int)($userData['id'] ?? 0);
    $loggedInRoleKey = strtolower(str_replace(' ', '_', trim((string)($userData['role'] ?? ''))));
    $loggedInCompanyId = (int)($userData['company_id'] ?? 0);
    $grantWriteTransactionOpen = false;
    $isAdminLike = in_array($loggedInRoleKey, ['super_admin', 'admin'], true);
    if (!$isAdminLike && !userCanConductAppraisals($userData)) {
        throw new Exception('Only an eligible supervisor/HOD, Admin or Super Admin can assign an appraisal level/profile.', 403);
    }

    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) throw new Exception('Invalid request format.', 400);

    $cycleId = (int)($body['cycle_id'] ?? 0);
    $staffUserId = (int)($body['staff_user_id'] ?? 0);
    $profileId = (int)($body['profile_id'] ?? 0);
    if ($cycleId <= 0 || $staffUserId <= 0) throw new Exception('Cycle and staff member are required.', 400);

    $ctxStmt = $conn->prepare("SELECT c.company_id, sa.supervisor_id, s.fullname, s.first_name, s.last_name, s.staff_type
        FROM appraisal_cycles c
        INNER JOIN supervisor_assignments sa ON sa.cycle_id=c.id AND sa.staff_id=?
        INNER JOIN users s ON s.id=sa.staff_id AND s.company_id=c.company_id AND s.is_active=1
        WHERE c.id=? LIMIT 1");
    if (!$ctxStmt) throw new Exception('Database error: ' . $conn->error, 500);
    $ctxStmt->bind_param('ii', $staffUserId, $cycleId);
    $ctxStmt->execute();
    $ctx = $ctxStmt->get_result()->fetch_assoc();
    $ctxStmt->close();
    if (!$ctx) throw new Exception('The employee is not assigned for this appraisal cycle.', 404);
    $companyId = (int)$ctx['company_id'];
    if ($loggedInRoleKey === 'supervisor' && (int)$ctx['supervisor_id'] !== $loggedInUserId) {
        throw new Exception('Supervisors/HODs can only set the appraisal level/profile for employees assigned to them.', 403);
    }
    $ownTeamAction = companySupervisorWriteIsOwnTeamAction(
        $userData, $companyId, (int)$ctx['supervisor_id']);
    $actingContext = null;
    $grantedCompanyWrite = false;
    if ($loggedInRoleKey === 'admin') {
        if ($ownTeamAction) {
            // Retain home-company admin-as-supervisor permissions, regardless of
            // a selected View Context company.
            $actingContext = companyAccessHomeContext($userData, $loggedInCompanyId);
        } else {
            $actingContext = companySupervisorWriteContext($userData);
            companySupervisorWriteAssertSubject($userData, $actingContext, [
                'company_id' => $companyId, 'staff_type' => $ctx['staff_type'],
            ]);
            $grantedCompanyWrite = companyUserWriteIsGranted($actingContext);
        }
        companySupervisorWriteAssertHomeProfileScope($userData, $actingContext, $ctx['staff_type'], (int)$ctx['supervisor_id']);
        companySupervisorWriteAssertPeer($conn, $userData, $actingContext,
            $staffUserId, (int)$ctx['supervisor_id']);
    }
    if ($loggedInRoleKey === 'supervisor' && $companyId !== $loggedInCompanyId) {
        throw new Exception('This employee is outside your home company.', 403);
    }

    $appraisalStmt = $conn->prepare('SELECT id FROM appraisals WHERE cycle_id=? AND staff_user_id=? LIMIT 1');
    $appraisalStmt->bind_param('ii', $cycleId, $staffUserId);
    $appraisalStmt->execute();
    $hasAppraisal = $appraisalStmt->get_result()->num_rows > 0;
    $appraisalStmt->close();
    if ($hasAppraisal) throw new Exception('The appraisal level/profile cannot be changed after the appraisal has been submitted.', 409);

    $settingsStmt = $conn->prepare('SELECT require_explicit_profile FROM appraisal_cycle_settings WHERE company_id=? AND cycle_id=? LIMIT 1');
    $settingsStmt->bind_param('ii', $companyId, $cycleId);
    $settingsStmt->execute();
    $settings = $settingsStmt->get_result()->fetch_assoc();
    $settingsStmt->close();
    $required = (int)($settings['require_explicit_profile'] ?? 0) === 1;

    if ($grantedCompanyWrite) {
        $conn->begin_transaction();
        $grantWriteTransactionOpen = true;
    }

    if ($profileId <= 0) {
        if ($required) throw new Exception('This cycle requires an employee appraisal level/profile.', 400);
        $deleteStmt = $conn->prepare('DELETE FROM appraisal_staff_scoring_profiles WHERE cycle_id=? AND staff_user_id=?');
        $deleteStmt->bind_param('ii', $cycleId, $staffUserId);
        if (!$deleteStmt->execute()) throw new Exception('Unable to clear appraisal level/profile: ' . $deleteStmt->error, 500);
        $deleteStmt->close();
        if ($grantedCompanyWrite) {
            companySupervisorWriteAudit($conn, $userData, $companyId, 'clear_staff_scoring_profile',
                'users', $staffUserId, "Cleared appraisal level/profile for staff #{$staffUserId}");
            $conn->commit();
            $grantWriteTransactionOpen = false;
        }
        profileResponse('Success', 'Employee appraisal level/profile cleared.', ['profile' => null]);
    }

    $profileStmt = $conn->prepare('SELECT id, code, label, description FROM appraisal_scoring_profiles WHERE id=? AND company_id=? AND cycle_id=? AND is_active=1 LIMIT 1');
    $profileStmt->bind_param('iii', $profileId, $companyId, $cycleId);
    $profileStmt->execute();
    $profile = $profileStmt->get_result()->fetch_assoc();
    $profileStmt->close();
    if (!$profile) throw new Exception('Selected appraisal level/profile is not valid for this cycle.', 400);

    $saveStmt = $conn->prepare('INSERT INTO appraisal_staff_scoring_profiles (cycle_id, staff_user_id, profile_id, assigned_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE profile_id=VALUES(profile_id), assigned_by=VALUES(assigned_by), updated_at=CURRENT_TIMESTAMP');
    if (!$saveStmt) throw new Exception('Database error: ' . $conn->error, 500);
    $saveStmt->bind_param('iiii', $cycleId, $staffUserId, $profileId, $loggedInUserId);
    if (!$saveStmt->execute()) throw new Exception('Unable to save appraisal level/profile: ' . $saveStmt->error, 500);
    $saveStmt->close();

    $staffName = trim((string)($ctx['fullname'] ?: (($ctx['first_name'] ?? '') . ' ' . ($ctx['last_name'] ?? ''))));
    $description = 'Assigned appraisal level/profile ' . $profile['label'] . ' to ' . ($staffName ?: ('staff #' . $staffUserId)) . '.';
    if ($grantedCompanyWrite) {
        companySupervisorWriteAudit($conn, $userData, $companyId, 'assign_staff_scoring_profile',
            'users', $staffUserId, $description);
        $conn->commit();
        $grantWriteTransactionOpen = false;
    } else {
        $auditStmt = $conn->prepare("INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description) VALUES (?, ?, 'assign_staff_scoring_profile', 'users', ?, ?)");
        if ($auditStmt) {
            $auditStmt->bind_param('iiis', $companyId, $loggedInUserId, $staffUserId, $description);
            $auditStmt->execute();
            $auditStmt->close();
        }
    
    }

    $profile['id'] = (int)$profile['id'];
    profileResponse('Success', 'Employee appraisal level/profile saved.', ['profile' => $profile]);
} catch (Throwable $e) {
    if (!empty($grantWriteTransactionOpen)) $conn->rollback();
    $code = (int)$e->getCode();
    if ($code < 400 || $code > 599) $code = 500;
    profileResponse('Failed', $e->getMessage(), [], $code);
}
