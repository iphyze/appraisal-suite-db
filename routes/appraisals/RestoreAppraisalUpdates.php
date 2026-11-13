<?php
require_once __DIR__ . '/../../includes/ApiException.php';

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/AdminAppraisalVisibility.php';
require_once 'includes/CompanyAppraisalWrite.php';
require_once __DIR__ . '/../utils/Notifications.php';
require_once __DIR__ . '/AppraisalHelpers.php';

header('Content-Type: application/json');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Only POST requests are allowed.', 405);
    }
    $user = requireRoles(['super_admin', 'admin']);
    $role = authRoleKey($user['role'] ?? '');
    $adminId = (int)$user['id'];
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) throw new Exception('Invalid JSON payload.', 400);

    $appraisalId = (int)($data['appraisal_id'] ?? 0);
    $reason = trim((string)($data['reason'] ?? ''));
    if ($appraisalId < 1) throw new Exception('A valid appraisal_id is required.', 400);
    $reasonLength = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
    if ($reasonLength < 10 || $reasonLength > 1000) {
        throw new Exception('Provide an administrative reason between 10 and 1,000 characters.', 400);
    }

    $conn->begin_transaction();
    try {
        $row = apFetchOne($conn, "SELECT id, company_id, cycle_id, staff_user_id, supervisor_id, staff_fullname, update_count, extra_update_allowance FROM appraisals WHERE id = {$appraisalId} FOR UPDATE");
        if (!$row) throw new Exception('Appraisal not found.', 404);
        $companyId = (int)$row['company_id'];

        $writeContext = companyAppraisalWriteContext($user);
        companyAppraisalWriteAssertRecord($conn, $user, $writeContext, $row);
        if ((int)$row['staff_user_id'] === $adminId || (int)$row['supervisor_id'] === $adminId) {
            throw new Exception('An administrator cannot grant additional updates to an appraisal they conduct or receive.', 403);
        }

        $consumed = (int)$row['update_count'];
        $previousExtra = (int)$row['extra_update_allowance'];
        if ($consumed < 2 + $previousExtra) {
            throw new Exception('This appraisal still has update attempts remaining.', 409);
        }

        // Restore exactly two new attempts while preserving the consumed count
        // (which is also used to determine revision numbering).
        $newExtra = $consumed;
        $stmt = $conn->prepare('UPDATE appraisals SET extra_update_allowance = ? WHERE id = ?');
        if (!$stmt) throw new Exception('Could not prepare allowance update.', 500);
        $stmt->bind_param('ii', $newExtra, $appraisalId);
        if (!$stmt->execute()) throw new Exception('Could not restore update attempts.', 500);
        $stmt->close();

        $grant = $conn->prepare('INSERT INTO appraisal_update_allowance_grants (appraisal_id, company_id, supervisor_id, granted_by, previous_allowance, new_allowance, reason) VALUES (?, ?, ?, ?, ?, ?, ?)');
        if (!$grant) throw new Exception('Could not prepare update grant audit record.', 500);
        $supervisorId = (int)$row['supervisor_id'];
        $grant->bind_param('iiiiiis', $appraisalId, $companyId, $supervisorId, $adminId, $previousExtra, $newExtra, $reason);
        if (!$grant->execute()) throw new Exception('Could not record update grant.', 500);
        $grant->close();

        companyAppraisalWriteAudit($conn, $user, $writeContext, $companyId, 'restore_appraisal_updates', 'appraisals', $appraisalId, 'Granted two more supervisor appraisal edits. Reason: ' . $reason);
        companyAppraisalWriteNotify($conn, $writeContext, $companyId, $supervisorId, 'appraisal_update_restored', 'Appraisal updates restored', 'Management restored two update attempts for ' . $row['staff_fullname'] . '. You can now update this appraisal.', '/appraisals/view/' . $appraisalId, 'notification.updateRestored', ['name' => $row['staff_fullname']]);
        $conn->commit();
        http_response_code(200);
        echo json_encode(['status' => 'Success', 'message' => 'Two update attempts restored for the supervisor.', 'message_key' => 'appraisal.restoreSuccess', 'message_params' => new stdClass(), 'data' => ['appraisal_id' => $appraisalId, 'remaining_updates' => 2, 'update_count' => $consumed]]);
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
} catch (Throwable $e) {
    $status = (int)$e->getCode();
    if ($status < 400 || $status > 599) $status = 500;
    error_log('[Appraisal update reset] ' . $e->getMessage());
    http_response_code($status);
    echo json_encode(apiErrorPayload($e, $status >= 500 ? 'Unable to restore update attempts. Please contact support.' : null));
}
