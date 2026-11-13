<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/IdentityPasswords.php';
require_once 'includes/AuthSecurity.php';

use Respect\Validation\Validator as v;

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Bad Request: Only PUT method is allowed", 400);
    }

    // Any authenticated user can update their own profile
    $userData          = authenticateUser();
    $loggedInUserId    = (int) $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $loggedInUserRole  = $userData['role'];
    $loggedInCompanyId = (int) $userData['company_id'];

    // ── Parse body ────────────────────────────────────────────────────────────
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        throw new Exception("Invalid request format. Expected JSON object.", 400);
    }

    // ── Build dynamic update ──────────────────────────────────────────────────
    $updateFields = [];
    $params       = [];
    $types        = "";

    // first_name
    if (isset($data['first_name']) && trim($data['first_name']) !== '') {
        $updateFields[] = "first_name = ?";
        $params[]       = trim($data['first_name']);
        $types         .= "s";
    }

    // last_name
    if (isset($data['last_name']) && trim($data['last_name']) !== '') {
        $updateFields[] = "last_name = ?";
        $params[]       = trim($data['last_name']);
        $types         .= "s";
    }

    // username
    if (isset($data['username']) && trim($data['username']) !== '') {
        $updateFields[] = "username = ?";
        $params[]       = trim($data['username']);
        $types         .= "s";
    }


    // Password changes use the same person-level identity write as the forced
    // change page, not a second unaudited users.password_hash writer.
    $newPasswordHash = null;
    $currentPassword = '';
    if (array_key_exists('new_password', $data)) {
        $currentPassword = trim((string)($data['current_password'] ?? ''));
        $newPassword = trim((string)$data['new_password']);
        $confirmation = trim((string)($data['confirm_password'] ?? ''));
        if ($currentPassword === '') throw new Exception('Current password is required to set a new password.', 400);
        if ($confirmation === '') throw new Exception('Please confirm your new password.', 400);
        if ($newPassword !== $confirmation) throw new Exception('New password and confirmation do not match.', 400);
        if (!v::stringType()->length(8, null)->validate($newPassword)) {
            throw new Exception('New password must be at least 8 characters long.', 400);
        }
        if ($currentPassword === $newPassword) throw new Exception('New password must differ from your current password.', 400);
        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    }

    if (!$updateFields && $newPasswordHash === null) {
        throw new Exception('No valid fields provided for update.', 400);
    }

    // Always stamp updated_by
    $updateFields[] = "updated_by = ?";
    $params[]       = $loggedInUserId;
    $types         .= "i";

    // Execute profile writes, shared password update and one audit per company
    // as a single unit. If current-password verification fails, no names change.
    $identityId = (int)($userData['identity_id'] ?? 0);
    userIdentityTransaction($conn, static function (mysqli $db) use (
        $updateFields, $params, $types, $loggedInUserId, $loggedInUserEmail,
        $loggedInCompanyId, $identityId, $newPasswordHash, $currentPassword
    ): void {
        if ($newPasswordHash !== null) {
            $storedHash = identityPasswordCurrentHash($db, $loggedInUserId, $identityId);
            if ($storedHash === null || !password_verify($currentPassword, $storedHash)) {
                throw new Exception('Current password is incorrect.', 401);
            }
            identityPasswordWriteInTransaction(
                $db, $loggedInUserId, $identityId, $newPasswordHash, false, $loggedInUserId
            );
        }
        if ($updateFields) {
            $params[] = $loggedInUserId;
            $types .= 'i';
            $stmt = userIdentityPrepare($db, 'UPDATE users SET ' . implode(', ', $updateFields) . ' WHERE id = ?');
            try {
                $stmt->bind_param($types, ...$params);
                userIdentityExecute($stmt);
            } finally {
                $stmt->close();
            }
        }
        if ($newPasswordHash !== null) {
            $members = identityPasswordMembershipRecords($db, $loggedInUserId);
            identityPasswordAuditAllInTransaction(
                $db, $members, $loggedInUserId, 'update_profile',
                'Member updated profile and changed shared sign-in password'
            );
        } else {
            $members = identityPasswordMembershipRecords($db, $loggedInUserId);
            $current = array_values(array_filter($members, static fn($m) => (int)$m['id'] === $loggedInUserId));
            identityPasswordAuditAllInTransaction(
                $db, $current, $loggedInUserId, 'update_profile',
                $loggedInUserEmail . ' updated their own profile'
            );
        }
    });

    // ── Return updated profile ────────────────────────────────────────────────
    $fetchStmt = $conn->prepare("
        SELECT
            u.id,
            u.identity_id,
            u.staff_id,
            u.first_name,
            u.last_name,
            u.email,
            u.username,
            u.department,
            u.job_title,
            u.staff_type,
            u.staff_scope,
            u.location,
            u.unique_ref,
            u.date_of_joining,
            u.is_active,
            u.last_login_at,
            COALESCE(i.must_change_password, u.must_change_password) AS must_change_password,
            COALESCE(i.password_changed_at, u.password_changed_at) AS password_changed_at,
            COALESCE(u.token_version, 0) AS token_version,
            COALESCE(i.token_version, 0) AS identity_token_version,
            CASE WHEN u.identity_id IS NOT NULL THEN i.preferred_locale ELSE u.preferred_locale END AS preferred_locale,
            u.updated_at,
            r.name  AS role,
            c.id    AS company_id,
            c.code  AS company_code,
            c.name  AS company_name,
            c.default_locale
        FROM users u
        INNER JOIN roles r     ON r.id = u.role_id
        INNER JOIN companies c ON c.id = u.company_id
        LEFT JOIN user_identities i ON i.id = u.identity_id
        WHERE u.id = ?
        LIMIT 1
    ");
    $fetchStmt->bind_param("i", $loggedInUserId);
    $fetchStmt->execute();
    $updatedProfile = $fetchStmt->get_result()->fetch_assoc();
    $fetchStmt->close();
    if (!$updatedProfile) throw new Exception('Updated profile not found.', 500);
    $updatedProfile['effective_locale'] = appEffectiveLocale(
        $updatedProfile['preferred_locale'] ?? null, $updatedProfile['default_locale'] ?? null
    );
    $updatedProfile['identity_id'] = (int)($updatedProfile['identity_id'] ?? 0);
    if ($newPasswordHash !== null) {
        // Reissue with the incremented identity token version; don't log the
        // current user out on a successful voluntary profile-password change.
        $updatedProfile['csrf_token'] = startSecureSession($updatedProfile);
    }
    unset($updatedProfile['token_version'], $updatedProfile['identity_token_version']);

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Profile updated successfully",
        "data"    => $updatedProfile
    ]);

} catch (Throwable $e) {
    error_log("UpdateProfile Error: " . $e->getMessage());
    $code = (int)$e->getCode();
    http_response_code($code >= 400 && $code <= 599 ? $code : 500);
    echo json_encode([
        "status"  => "Failed",
        "message" => $e->getMessage()
    ]);
}