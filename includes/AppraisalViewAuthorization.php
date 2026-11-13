<?php
/**
 * Shared appraisal detail READ authorisation for the normal viewer and the
 * on-demand translation endpoint. Do not use edit permissions for read access.
 * Keep role/company/grant/scope rules identical across these two paths.
 */
require_once __DIR__ . '/authMiddleware.php';
require_once __DIR__ . '/AdminAppraisalVisibility.php';
require_once __DIR__ . '/CompanyAccess.php';
require_once __DIR__ . '/AdminStaffScope.php';
require_once __DIR__ . '/../routes/appraisals/AppraisalHelpers.php';

function appraisalAssertViewAuthorized(mysqli $conn, array $userData, array &$appraisal): void
{
    $loggedInUserId = (int)$userData['id'];
    $loggedInRoleKey = authRoleKey($userData['role'] ?? '');
    $loggedInCompanyId = (int)($userData['company_id'] ?? 0);
    if ($loggedInRoleKey === 'super_admin') {
        $companyScope = resolveCompanyScope($userData);
        if ($companyScope !== null && (int) $appraisal['company_id'] !== (int) $companyScope) {
            throw new Exception('Unauthorized: This appraisal is outside the selected company context.', 403);
        }
    }
    if ($loggedInRoleKey === 'staff' && (int)$appraisal['staff_user_id'] !== $loggedInUserId) throw new Exception('Unauthorized: You can only view your own appraisal.', 403);
    if ($loggedInRoleKey === 'supervisor' && (int)$appraisal['supervisor_id'] !== $loggedInUserId && (int)$appraisal['staff_user_id'] !== $loggedInUserId) throw new Exception('Unauthorized: You can only view your own appraisal or appraisals you conducted.', 403);
    if ($loggedInRoleKey === 'admin') {
        // Own appraisals and appraisals conducted for the admin's HOME team are
        // personal/supervisor records; never reroute them through the grant view.
        $personalRecord = (int) $appraisal['staff_user_id'] === $loggedInUserId
            || ((int) $appraisal['supervisor_id'] === $loggedInUserId
                && (int) $appraisal['company_id'] === $loggedInCompanyId);
        if ($personalRecord && (int)$appraisal['staff_user_id'] !== $loggedInUserId
            && (int)$appraisal['company_id'] === $loggedInCompanyId) {
            $subject = apFetchOne($conn, "SELECT u.id, u.company_id, u.staff_type, r.name AS role_name FROM users u INNER JOIN roles r ON r.id=u.role_id WHERE u.id=" . (int)$appraisal['staff_user_id'] . " LIMIT 1");
            if ($subject) $subject['staff_type'] = $subject['staff_type'] ?? ($appraisal['staff_type'] ?? null);
            if ($subject && !staffScopeAllowsSubject($conn, $userData, null, $subject,
                (int)$appraisal['cycle_id'], (int)$appraisal['supervisor_id'])) {
                throw new Exception('Unauthorized: This appraisal is outside your staff scope.', 403);
            }
        }
        if (!$personalRecord) {
            $actingContext = resolveActingCompanyContext($userData);
            $actingCompanyId = (int) $actingContext['company_id'];
            if ((int) $appraisal['company_id'] !== $actingCompanyId) {
                throw new Exception('Unauthorized: This appraisal is outside the selected company context.', 403);
            }
            if (!adminCanViewAppraisalSubject(
                $conn,
                $loggedInUserId,
                $actingCompanyId,
                (int) $appraisal['staff_user_id'],
                !empty($actingContext['is_home']) ? (int) $appraisal['supervisor_id'] : 0
            )) {
                throw new Exception('Unauthorized: You are not permitted to view this administrator appraisal.', 403);
            }
            if (empty($actingContext['is_home'])) {
                // Strict grant scope even for supervisor and admin appraisees;
                // appraisal staff_type is the saved appraisee classification.
                $scope = (string) $actingContext['staff_scope'];
                if ($scope !== 'All' && (string) ($appraisal['staff_type'] ?? '') !== $scope) {
                    throw new Exception('Unauthorized: This appraisal is outside your staff scope.', 403);
                }
                // Company-management grants are not supervisor assignments.
                $appraisal['can_manage_appraisal'] = 0;
            } else {
                // Keep home-company staff scope, with the same cycle/recorded-supervisor exception.
                $subject = apFetchOne($conn, "SELECT u.id, u.company_id, u.staff_type, r.name AS role_name FROM users u INNER JOIN roles r ON r.id=u.role_id WHERE u.id=" . (int)$appraisal['staff_user_id'] . " LIMIT 1");
                if ($subject) $subject['staff_type'] = $subject['staff_type'] ?? ($appraisal['staff_type'] ?? null);
                if ($subject && !staffScopeAllowsSubject($conn, $userData, $actingContext, $subject,
                    (int)$appraisal['cycle_id'], (int)$appraisal['supervisor_id'])) {
                    throw new Exception('Unauthorized: This appraisal is outside your staff scope.', 403);
                }
            }
        }
    }

}
