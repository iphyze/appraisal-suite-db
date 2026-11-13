<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/CompanyUserWrite.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception("Bad Request: Only DELETE method is allowed", 400);
    }

    // Auth — super_admin, admin only
    $userData          = requireRoles(['super_admin', 'admin']);
    $loggedInUserId    = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $loggedInUserRole  = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];
    $actingContext = companyUserWriteContext($userData);
    $grantedCompanyWrite = companyUserWriteIsGranted($actingContext);

    // ── Parse body ────────────────────────────────────────────────────────────
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    if (!isset($data['ids']) || !is_array($data['ids']) || count($data['ids']) === 0) {
        throw new Exception("Field 'ids' is required and must be a non-empty array.", 400);
    }

    // Sanitise — ensure all IDs are positive integers
    $targetIds = array_values(array_unique(array_filter(
        array_map('intval', $data['ids']),
        fn($id) => $id > 0
    )));

    if (empty($targetIds)) {
        throw new Exception("No valid user IDs provided.", 400);
    }

    // Prevent self-deletion
    if (in_array($loggedInUserId, $targetIds, true)) {
        throw new Exception("You cannot delete your own account.", 400);
    }

    // ── Validate each target user ─────────────────────────────────────────────
    $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
    $types        = str_repeat('i', count($targetIds));

    $checkStmt = $conn->prepare("
        SELECT u.id, u.identity_id, u.email, u.company_id, u.staff_type, r.name AS role
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        WHERE u.id IN ({$placeholders})
    ");
    if (!$checkStmt) {
        throw new Exception("Database error: " . $conn->error, 500);
    }
    $checkStmt->bind_param($types, ...$targetIds);
    $checkStmt->execute();
    $foundUsers = $checkStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $checkStmt->close();

    if (count($foundUsers) === 0) {
        throw new Exception("No matching users found.", 404);
    }

    // ── Per-user authorization checks ─────────────────────────────────────────
    foreach ($foundUsers as $target) {
        // Every record must belong to the acting company and match the grant scope.
        companyUserWriteAssertTarget($userData, $actingContext, $target);

        $targetRoleKey = authRoleKey($target['role'] ?? '');

        // Staff scope limits ordinary staff records only. It must not prevent
        // an admin from seeing peer administrator/appraiser records.
        if (authRoleKey($loggedInUserRole) === 'admin' && !$grantedCompanyWrite) {
            $adminScope = $userData['staff_scope'] ?? 'All';
            if (
                $adminScope !== 'All' &&
                $targetRoleKey === 'staff' &&
                $target['staff_type'] !== null &&
                $target['staff_type'] !== $adminScope
            ) {
                throw new Exception(
                    "Unauthorized: You do not have permission to delete user ID {$target['id']}.",
                    403
                );
            }
        }

        // Admin cannot delete another admin or super_admin
        if (
            authRoleKey($loggedInUserRole) === 'admin' &&
            in_array($targetRoleKey, ['admin', 'super_admin'], true)
        ) {
            throw new Exception(
                "Unauthorized: You cannot delete an admin account (ID: {$target['id']}).",
                403
            );
        }

        // Nobody can delete a super_admin except another super_admin
        if (
            $targetRoleKey === 'super_admin' &&
            authRoleKey($loggedInUserRole) !== 'super_admin'
        ) {
            throw new Exception(
                "Unauthorized: Only a super admin can delete another super admin account.",
                403
            );
        }
    }

    // ── Resolve which IDs actually exist (guard against partial mismatches) ───
    $validIds = array_column($foundUsers, 'id');

    // ── Transaction ───────────────────────────────────────────────────────────
    // Identity rows are locked before membership rows, matching password
    // changes and CreateUsers. Otherwise deleting a person's final membership
    // while another request adds a membership could orphan their credentials.
    $conn->begin_transaction();

    try {
        $delPlaceholders = implode(',', array_fill(0, count($validIds), '?'));
        $delTypes        = str_repeat('i', count($validIds));
        $identityIds = array_values(array_unique(array_filter(array_map(
            'intval', array_column($foundUsers, 'identity_id')
        ), static fn(int $id): bool => $id > 0)));
        sort($identityIds, SORT_NUMERIC);

        foreach ($identityIds as $identityId) {
            $lockIdentity = $conn->prepare('SELECT id FROM user_identities WHERE id = ? FOR UPDATE');
            if (!$lockIdentity) throw new Exception('Database error while locking login identity.', 500);
            $lockIdentity->bind_param('i', $identityId);
            $lockIdentity->execute();
            $lockedIdentity = $lockIdentity->get_result()->fetch_assoc();
            $lockIdentity->close();
            if (!$lockedIdentity) throw new Exception('The login identity changed. Reload users and try again.', 409);
        }

        // The authorization checks above use the membership snapshot. Re-check
        // that the same rows still belong to the same identities after locking,
        // rather than deleting an unexpectedly re-linked account.
        $expectedIdentityByUser = [];
        foreach ($foundUsers as $target) {
            $expectedIdentityByUser[(int)$target['id']] = (int)($target['identity_id'] ?? 0);
        }
        $lockMembers = $conn->prepare("SELECT id, identity_id FROM users
            WHERE id IN ({$delPlaceholders}) FOR UPDATE");
        if (!$lockMembers) throw new Exception('Database error while locking memberships.', 500);
        $lockMembers->bind_param($delTypes, ...$validIds);
        $lockMembers->execute();
        $lockedMembers = $lockMembers->get_result()->fetch_all(MYSQLI_ASSOC);
        $lockMembers->close();
        if (count($lockedMembers) !== count($validIds)) {
            throw new Exception('A membership changed. Reload users and try again.', 409);
        }
        foreach ($lockedMembers as $lockedMember) {
            $id = (int)$lockedMember['id'];
            if (!array_key_exists($id, $expectedIdentityByUser)
                || (int)($lockedMember['identity_id'] ?? 0) !== $expectedIdentityByUser[$id]) {
                throw new Exception('A login link changed. Reload users and try again.', 409);
            }
        }

        $deleteStmt = $conn->prepare("
            DELETE FROM users WHERE id IN ({$delPlaceholders})
        ");
        if (!$deleteStmt) {
            throw new Exception("Database error: " . $conn->error, 500);
        }

        $deleteStmt->bind_param($delTypes, ...$validIds);
        if (!$deleteStmt->execute()) {
            throw new Exception("Delete failed: " . $deleteStmt->error, 500);
        }

        $deletedCount = $deleteStmt->affected_rows;
        $deleteStmt->close();

        // The identity belongs to the person, not to the deleted company
        // membership. Keep it if any other company membership still refers to
        // it. If the LAST membership was deleted, remove the now-unused login
        // too: otherwise a future person assigned the same email would
        // silently inherit an old, password-bearing identity.
        foreach ($identityIds as $identityId) {
            $remainingStmt = $conn->prepare('SELECT COUNT(*) AS n FROM users WHERE identity_id = ?');
            if (!$remainingStmt) throw new Exception('Database error checking identity memberships.', 500);
            $remainingStmt->bind_param('i', $identityId);
            $remainingStmt->execute();
            $remaining = (int)($remainingStmt->get_result()->fetch_assoc()['n'] ?? 0);
            $remainingStmt->close();
            if ($remaining !== 0) continue;

            $deleteIdentity = $conn->prepare('DELETE FROM user_identities WHERE id = ?');
            if (!$deleteIdentity) throw new Exception('Database error deleting unused login identity.', 500);
            $deleteIdentity->bind_param('i', $identityId);
            $deleteIdentity->execute();
            if ($deleteIdentity->affected_rows !== 1) {
                throw new Exception('Login identity changed during membership deletion.', 409);
            }
            $deleteIdentity->close();
        }

        // ── Log action ────────────────────────────────────────────────────────
        if ($grantedCompanyWrite) {
            foreach ($foundUsers as $deleted) {
                $targetId = (int)$deleted['id'];
                companyUserWriteAudit($conn, $userData, (int)$deleted['company_id'],
                    'delete_user', $targetId,
                    "deleted user account: {$deleted['email']} (ID: {$targetId})");
            }
        } else {
        $logStmt = $conn->prepare("
            INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        if (!$logStmt) {
            throw new Exception("Failed to prepare audit log: " . $conn->error, 500);
        }

        // Log one entry per deleted user for a clean audit trail
        foreach ($foundUsers as $deleted) {
            $action      = "delete_user";
            $targetTable = "users";
            $targetId    = (int) $deleted['id'];
            $description = "{$loggedInUserEmail} deleted user account: {$deleted['email']} (ID: {$deleted['id']})";

            $logStmt->bind_param(
                "iissis",
                $loggedInCompanyId, $loggedInUserId,
                $action, $targetTable, $targetId,
                $description
            );
            $logStmt->execute();
        }
        $logStmt->close();
        }

        $conn->commit();

        http_response_code(200);
        echo json_encode([
            "status"  => "Success",
            "message" => "{$deletedCount} user account(s) deleted successfully.",
            "data"    => [
                "deleted_count" => $deletedCount,
                "deleted_ids"   => $validIds,
            ]
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("DeleteUsers Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage()
    ]);
}