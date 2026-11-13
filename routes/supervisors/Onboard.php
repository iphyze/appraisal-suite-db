<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/CompanySupervisorWrite.php';
require_once __DIR__ . '/../utils/Notifications.php';

header('Content-Type: application/json');

function onboardResponse($status, $message, $data = [], $code = 200)
{
    http_response_code($code);
    echo json_encode([
        'status'  => $status,
        'message' => $message,
        'data'    => $data,
    ]);
    exit;
}

function onboardRoleKeySql($alias)
{
    return "LOWER(REPLACE(TRIM({$alias}.name), ' ', '_'))";
}

function onboardColumnExists($conn, $table, $column)
{
    $safeTable  = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $safeColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $result && $result->num_rows > 0;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Bad Request: Only POST method is allowed', 405);
    }

    /**
     * Onboarding can be triggered by:
     *   a) The supervisor for themselves
     *   b) Admin / super_admin for a supervisor
     *
     * cycle_id is optional for backward compatibility.
     * If cycle_id is not supplied, the active cycle for the supervisor's company is used.
     */
    $userData = authenticateUser();

    $loggedInUserId    = (int) ($userData['id'] ?? 0);
    $loggedInRole      = strtolower(str_replace(' ', '_', trim((string) ($userData['role'] ?? ''))));
    $loggedInCompanyId = isset($userData['company_id']) ? (int) $userData['company_id'] : 0;
    $grantWriteTransactionOpen = false;

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        $data = [];
    }

    if ($loggedInRole === 'supervisor') {
        $supervisorId = $loggedInUserId;
    } elseif ($loggedInRole === 'admin' && userCanConductAppraisals($userData) && (!isset($data['supervisor_id']) || !is_numeric($data['supervisor_id']))) {
        // Only administrators explicitly marked as supervisors may onboard themselves.
        $supervisorId = $loggedInUserId;
    } elseif (in_array($loggedInRole, ['super_admin', 'admin'], true)) {
        if (!isset($data['supervisor_id']) || !is_numeric($data['supervisor_id'])) {
            throw new Exception("Field 'supervisor_id' is required.", 400);
        }
        $supervisorId = (int) $data['supervisor_id'];
    } else {
        throw new Exception('Unauthorized: Only supervisors or admins can trigger onboarding.', 403);
    }

    // Onboarding your own team is a personal supervisor action and must not
    // redirect to a selected foreign company. Every other admin onboarding
    // request is an administrative company write.
    $ownSupervisorAction = ($supervisorId === $loggedInUserId)
        && ($loggedInRole === 'supervisor'
            || ($loggedInRole === 'admin' && userCanConductAppraisals($userData)));
    $actingContext = (!$ownSupervisorAction && in_array($loggedInRole, ['admin', 'super_admin'], true))
        ? companySupervisorWriteContext($userData) : null;
    $grantedCompanyWrite = $actingContext !== null && companyUserWriteIsGranted($actingContext);

    $requestedCycleId = isset($data['cycle_id']) && is_numeric($data['cycle_id'])
        ? (int) $data['cycle_id']
        : 0;

    /**
     * Validate supervisor.
     */
    $supStmt = $conn->prepare("
        SELECT
            u.id,
            u.first_name,
            u.last_name,
            u.company_id,
            u.staff_type,
            r.name AS role_name
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        WHERE u.id = ?
          AND " . appraiserRoleWhere('r', 'u') . "
        LIMIT 1
    ");

    if (!$supStmt) {
        throw new Exception('Database error: ' . $conn->error, 500);
    }

    $supStmt->bind_param('i', $supervisorId);
    $supStmt->execute();
    $supervisor = $supStmt->get_result()->fetch_assoc();
    $supStmt->close();

    if (!$supervisor) {
        throw new Exception('Supervisor not found.', 404);
    }

    if ($ownSupervisorAction) {
        if ((int)$supervisor['company_id'] !== $loggedInCompanyId) {
            throw new Exception('Your supervisor record does not belong to your home company.', 403);
        }
    } elseif ($actingContext !== null) {
        companySupervisorWriteAssertSubject($userData, $actingContext, $supervisor);
    }

    /**
     * Resolve selected cycle or active cycle.
     */
    if ($requestedCycleId > 0) {
        $cycleStmt = $conn->prepare("
            SELECT id, year, title, company_id, is_active
            FROM appraisal_cycles
            WHERE id = ?
              AND company_id = ?
            LIMIT 1
        ");

        if (!$cycleStmt) {
            throw new Exception('Database error: ' . $conn->error, 500);
        }

        $companyId = (int) $supervisor['company_id'];
        $cycleStmt->bind_param('ii', $requestedCycleId, $companyId);
    } else {
        $cycleStmt = $conn->prepare("
            SELECT id, year, title, company_id, is_active
            FROM appraisal_cycles
            WHERE company_id = ?
              AND is_active = 1
            ORDER BY year DESC, id DESC
            LIMIT 1
        ");

        if (!$cycleStmt) {
            throw new Exception('Database error: ' . $conn->error, 500);
        }

        $companyId = (int) $supervisor['company_id'];
        $cycleStmt->bind_param('i', $companyId);
    }

    $cycleStmt->execute();
    $cycle = $cycleStmt->get_result()->fetch_assoc();
    $cycleStmt->close();

    if (!$cycle) {
        throw new Exception('No appraisal cycle found for this company. Please contact an administrator.', 400);
    }

    /**
     * Ensure supervisor has assigned staff for the selected cycle.
     * supervisor_assignments.staff_id references users.id.
     */
    $subStmt = $conn->prepare("
        SELECT COUNT(*) AS cnt
        FROM supervisor_assignments
        WHERE supervisor_id = ?
          AND cycle_id = ?
    ");

    if (!$subStmt) {
        throw new Exception('Database error: ' . $conn->error, 500);
    }

    $cycleId = (int) $cycle['id'];
    $subStmt->bind_param('ii', $supervisorId, $cycleId);
    $subStmt->execute();
    $subCount = (int) $subStmt->get_result()->fetch_assoc()['cnt'];
    $subStmt->close();

    if ($subCount === 0) {
        throw new Exception(
            "No staff members are assigned to you for the {$cycle['year']} appraisal cycle. Please contact an administrator.",
            400
        );
    }

    if ($grantedCompanyWrite) {
        // createNotification() has an idempotent DDL guard; perform it before
        // the transaction to avoid an implicit commit from CREATE TABLE.
        ensureNotificationsTable($conn);
        $conn->begin_transaction();
        $grantWriteTransactionOpen = true;
    }

    /**
     * Upsert supervisor_onboarding.
     */
    $checkStmt = $conn->prepare("
        SELECT id, onboarded_at
        FROM supervisor_onboarding
        WHERE supervisor_id = ?
          AND cycle_id = ?
        LIMIT 1
    ");

    if (!$checkStmt) {
        throw new Exception('Database error: ' . $conn->error, 500);
    }

    $checkStmt->bind_param('ii', $supervisorId, $cycleId);
    $checkStmt->execute();
    $existing = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    $now = date('Y-m-d H:i:s');

    if ($existing) {
        $updateStmt = $conn->prepare("
            UPDATE supervisor_onboarding
            SET onboarded_at = ?
            WHERE id = ?
        ");

        if (!$updateStmt) {
            throw new Exception('Database error: ' . $conn->error, 500);
        }

        $existingId = (int) $existing['id'];
        $updateStmt->bind_param('si', $now, $existingId);
        if (!$updateStmt->execute()) {
            throw new Exception('Unable to update supervisor onboarding.', 500);
        }
        $updateStmt->close();
        $message = "Re-onboarded successfully for the {$cycle['year']} appraisal cycle.";
    } else {
        $insertStmt = $conn->prepare("
            INSERT INTO supervisor_onboarding (cycle_id, supervisor_id, onboarded_at)
            VALUES (?, ?, ?)
        ");

        if (!$insertStmt) {
            throw new Exception('Database error: ' . $conn->error, 500);
        }

        $insertStmt->bind_param('iis', $cycleId, $supervisorId, $now);
        if (!$insertStmt->execute()) {
            throw new Exception('Unable to save supervisor onboarding.', 500);
        }
        $insertStmt->close();
        $message = "Onboarded successfully for the {$cycle['year']} appraisal cycle.";
    }

    /**
     * Optional compatibility update for dashboards that read users.onboarded_at.
     */
    if (onboardColumnExists($conn, 'users', 'onboarded_at')) {
        $userUpdateStmt = $conn->prepare('UPDATE users SET onboarded_at = ? WHERE id = ?');
        if ($userUpdateStmt) {
            $userUpdateStmt->bind_param('si', $now, $supervisorId);
            $userUpdateStmt->execute();
            $userUpdateStmt->close();
        }
    }


    // Notify admins and super admins when a supervisor completes onboarding.
    $adminRows = $conn->query("SELECT u.id FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE u.company_id = " . (int)$supervisor['company_id'] . " AND LOWER(REPLACE(TRIM(r.name), ' ', '_')) IN ('admin','super_admin') AND u.is_active = 1");
    if ($adminRows) {
        $fullname = trim(($supervisor['first_name'] ?? '') . ' ' . ($supervisor['last_name'] ?? ''));
        while ($admin = $adminRows->fetch_assoc()) {
            if ($grantedCompanyWrite) {
                companySupervisorWriteNotification($conn, (int)$supervisor['company_id'], (int)$admin['id'],
                    'supervisor_onboarded', 'Supervisor onboarding completed',
                    "{$fullname} completed onboarding for the {$cycle['year']} appraisal cycle.",
                    '/supervisors/assignments?supervisor_id=' . $supervisorId,
                    'notification.supervisorOnboarded', ['name' => $fullname, 'year' => (string)$cycle['year']]);
            } else {
                createNotification($conn, (int)$supervisor['company_id'], (int)$admin['id'],
                    'supervisor_onboarded', 'Supervisor onboarding completed',
                    "{$fullname} completed onboarding for the {$cycle['year']} appraisal cycle.",
                    '/supervisors/assignments?supervisor_id=' . $supervisorId,
                    0, 0, 'notification.supervisorOnboarded', ['name' => $fullname, 'year' => (string)$cycle['year']]);
            }
        }
    }

    $fullname = trim(($supervisor['first_name'] ?? '') . ' ' . ($supervisor['last_name'] ?? ''));
    $companyId = (int)$supervisor['company_id'];
    // Home/personal onboarding is nontransactional: notification fan-out is
    // best-effort there. Granted-company administration remains transactional.
    $grantBroadcast = $grantedCompanyWrite
        ? 'createGrantNotificationsForSubject' : 'tryCreateGrantNotificationsForSubject';
    $onboardGrantRecipients = $grantBroadcast($conn, $companyId, $supervisorId, 0,
        'supervisor_onboarded', 'Supervisor onboarding completed',
        "{$fullname} completed onboarding for the {$cycle['year']} appraisal cycle.",
        '/supervisors/view/' . $supervisorId, [],
        'notification.supervisorOnboarded', ['name' => $fullname, 'year' => (string)$cycle['year']]);
    if ($grantedCompanyWrite) {
        companySupervisorWriteAudit($conn, $userData, $companyId, 'supervisor_onboard',
            'supervisor_onboarding', $supervisorId,
            "{$fullname} onboarded for the {$cycle['year']} appraisal cycle");
        $conn->commit();
        $grantWriteTransactionOpen = false;
    } else {
        // Preserve the original audit handling for home/personal onboarding.
        $logStmt = $conn->prepare('INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description) VALUES (?, ?, ?, ?, ?, ?)');
        if ($logStmt) {
            $action = 'supervisor_onboard';
            $targetTable = 'supervisor_onboarding';
            $description = "{$fullname} onboarded for the {$cycle['year']} appraisal cycle";
            $logStmt->bind_param('iissis', $companyId, $loggedInUserId, $action, $targetTable, $supervisorId, $description);
            $logStmt->execute();
            $logStmt->close();
        }
    }

    emailGrantNotificationRecipients($conn, $companyId, $supervisorId, 0,
        $onboardGrantRecipients, 'Supervisor onboarding completed',
        "{$fullname} completed onboarding for the {$cycle['year']} appraisal cycle.",
        '/supervisors/view/' . $supervisorId);

    onboardResponse('Success', $message, [
        'supervisor_id'      => $supervisorId,
        'supervisor_name'    => trim(($supervisor['first_name'] ?? '') . ' ' . ($supervisor['last_name'] ?? '')),
        'cycle_id'           => $cycleId,
        'cycle_year'         => $cycle['year'],
        'cycle_title'        => $cycle['title'],
        'onboarded_at'       => $now,
        'subordinate_count'  => $subCount,
    ]);

} catch (Throwable $e) {
    if (!empty($grantWriteTransactionOpen)) $conn->rollback();
    error_log('Onboard Error: ' . $e->getMessage());
    $code = $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    onboardResponse('Failed', $e->getMessage(), [], $code);
}
