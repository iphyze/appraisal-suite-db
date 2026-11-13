<?php
/**
 * Super Admin may configure an Admin's appraisal peer-visibility policy in
 * their home company OR a company for which that Admin has an active grant.
 * No foreign policy is inferred or copied from the home company.
 */
function aavPolicyCompanyId(
    array $admin, $requestedCompanyId,
    callable $activeGrantReader
): int {
    $home = (int)($admin['company_id'] ?? 0);
    $adminId = (int)($admin['id'] ?? 0);
    if ($home < 1 || $adminId < 1) throw new Exception('Administrator not found.', 404);
    if ($requestedCompanyId === null || $requestedCompanyId === '') return $home;
    if ((!is_string($requestedCompanyId) && !is_int($requestedCompanyId))
        || !preg_match('/^[1-9][0-9]*$/D', (string)$requestedCompanyId)) {
        throw new Exception('Invalid policy company identifier.', 422);
    }
    $company = filter_var((string)$requestedCompanyId, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]);
    if ($company === false) throw new Exception('Invalid policy company identifier.', 422);
    if ($company === $home) return $home;
    if ((int)($admin['is_active'] ?? 0) !== 1 || !$activeGrantReader($adminId, $company)) {
        throw new Exception('Administrator does not have an active grant for that company.', 403);
    }
    return $company;
}

function aavActivePolicyGrant(mysqli $conn, int $adminId, int $companyId, bool $lock = false): bool
{
    $suffix = $lock ? ' FOR UPDATE' : '';
    $stmt = $conn->prepare('SELECT g.id FROM admin_company_access g
        INNER JOIN users u ON u.id = g.user_id AND u.is_active = 1
        INNER JOIN roles r ON r.id = u.role_id
        INNER JOIN companies c ON c.id = g.company_id AND c.is_active = 1
        WHERE g.user_id = ? AND g.company_id = ? AND g.revoked_at IS NULL
          AND g.company_id <> u.company_id
          AND LOWER(REPLACE(TRIM(r.name), \' \', \'_\')) = \'admin\'
        LIMIT 1' . $suffix);
    if (!$stmt) throw new Exception('Unable to check administrator grant.', 500);
    $stmt->bind_param('ii', $adminId, $companyId);
    $stmt->execute();
    $allowed = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $allowed;
}
