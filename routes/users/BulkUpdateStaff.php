<?php
/** Atomic bulk changes for employee records. Never trusts browser-side company IDs. */
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/CompanyUserWrite.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception('Only PUT is allowed.', 405);
    }
    $actor = requireRoles(['super_admin', 'admin']);
    $context = companyUserWriteContext($actor); // Live grant and view-only guard.
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) throw new Exception('Expected a JSON object.', 400);

    $providedIds = $input['ids'] ?? null;
    if (!is_array($providedIds) || !$providedIds || count($providedIds) > 200) {
        throw new Exception('Select between 1 and 200 staff accounts.', 400);
    }
    $ids = [];
    foreach ($providedIds as $raw) {
        if (!is_int($raw) && !(is_string($raw) && ctype_digit($raw))) {
            throw new Exception('Invalid staff ID in selection.', 400);
        }
        $id = (int)$raw;
        if ($id <= 0) throw new Exception('Invalid staff ID in selection.', 400);
        $ids[] = $id;
    }
    $ids = array_values(array_unique($ids));
    $changeDepartment = array_key_exists('department', $input);
    $changeStatus = array_key_exists('is_active', $input);
    if (!$changeDepartment && !$changeStatus) {
        throw new Exception('Select a department and/or account status to update.', 400);
    }
    $department = null;
    if ($changeDepartment) {
        if (!is_string($input['department']) || trim($input['department']) === '' || mb_strlen(trim($input['department'])) > 150) {
            throw new Exception('Choose a valid department.', 400);
        }
        $department = trim($input['department']);
    }
    $active = null;
    if ($changeStatus) {
        if (!in_array($input['is_active'], [0, 1, '0', '1'], true)) {
            throw new Exception('Account status must be Active or Inactive.', 400);
        }
        $active = (int)$input['is_active'];
    }

    $conn->begin_transaction();
    try {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("SELECT u.id, u.company_id, u.staff_type, u.department, u.is_active, r.name AS role
            FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE u.id IN ($marks) FOR UPDATE");
        if (!$stmt) throw new Exception('Cannot lock selected staff accounts.', 500);
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        $targets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (count($targets) !== count($ids)) throw new Exception('Some selected staff accounts no longer exist. Refresh and retry.', 409);

        $companyId = (int)$targets[0]['company_id'];
        foreach ($targets as $target) {
            if ((int)$target['company_id'] !== $companyId) {
                throw new Exception('Bulk updates must contain staff from one company. Filter by company and retry.', 400);
            }
            if (authRoleKey($target['role']) !== 'staff') {
                throw new Exception('Bulk department/status changes are limited to Staff accounts. Edit administrators and supervisors individually.', 403);
            }
            if ((int)$target['id'] === (int)$actor['id']) {
                throw new Exception('You cannot bulk-edit your own account.', 403);
            }
            companyUserWriteAssertTarget($actor, $context, $target);
            // Existing home-company staff scope must remain enforced as on UpdateUser.
            if (authRoleKey($actor['role_key'] ?? $actor['role']) === 'admin'
                && !companyUserWriteIsGranted($context)
                && ($actor['staff_scope'] ?? 'All') !== 'All'
                && $target['staff_type'] !== null
                && (string)$target['staff_type'] !== (string)$actor['staff_scope']) {
                throw new Exception('A selected employee is outside your home-company staff scope.', 403);
            }
        }
        if ($changeDepartment) {
            $check = $conn->prepare('SELECT id FROM departments WHERE company_id = ? AND name = ? AND is_active = 1 LIMIT 1');
            if (!$check) throw new Exception('Cannot validate target department.', 500);
            $check->bind_param('is', $companyId, $department);
            $check->execute();
            $found = $check->get_result()->fetch_assoc();
            $check->close();
            if (!$found) throw new Exception('Choose an active department belonging to the selected company.', 400);
        }

        $set = [];
        $types = '';
        $values = [];
        if ($changeDepartment) { $set[] = 'department = ?'; $types .= 's'; $values[] = $department; }
        if ($changeStatus) { $set[] = 'is_active = ?'; $types .= 'i'; $values[] = $active; }
        $sql = 'UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ? AND company_id = ?';
        $update = $conn->prepare($sql);
        if (!$update) throw new Exception('Cannot prepare staff changes.', 500);
        foreach ($targets as $target) {
            $targetId = (int)$target['id'];
            $valuesForRow = array_merge($values, [$targetId, $companyId]);
            $update->bind_param($types . 'ii', ...$valuesForRow);
            if (!$update->execute()) throw new Exception('Could not update a selected staff account.', 500);
            companyUserWriteAudit($conn, $actor, $companyId, 'bulk_update_staff', $targetId,
                'updated staff account #' . $targetId
                . ($changeDepartment ? ' department to ' . $department : '')
                . ($changeStatus ? ' status to ' . ($active ? 'Active' : 'Inactive') : ''));
        }
        $update->close();
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    http_response_code(200);
    echo json_encode(['status' => 'Success', 'message' => count($targets) . ' staff accounts updated successfully.',
        'data' => ['updated_count' => count($targets)]]);
} catch (Throwable $error) {
    error_log('BulkUpdateStaff: ' . $error->getMessage());
    $code = (int)$error->getCode();
    http_response_code($code >= 400 && $code <= 599 ? $code : 500);
    echo json_encode(['status' => 'Failed', 'message' => $error->getMessage()]);
}
