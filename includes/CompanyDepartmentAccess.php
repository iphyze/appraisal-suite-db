<?php
/** Batch 5E-2A: department setup uses the existing company-wide setup guard. */
require_once __DIR__ . '/CompanySetupWrite.php';

/** Renaming a department updates every matching users.department value. */
function companyDepartmentNeedsRenameScopeCheck(array $context): bool
{
    return companyUserWriteIsGranted($context)
        && in_array($context['staff_scope'] ?? null, ['Local', 'Expatriate'], true);
}

/** Fail closed before a scoped grantee renames an out-of-scope employee's department. */
function companyDepartmentAssertRenameScope(
    mysqli $db, array $context, int $companyId, string $oldName
): void {
    if (!companyDepartmentNeedsRenameScopeCheck($context)) return;
    $staffType = (string) $context['staff_scope'];
    $stmt = $db->prepare('SELECT staff_type FROM users WHERE company_id = ? AND department = ? FOR UPDATE');
    if (!$stmt) throw new Exception('Unable to verify department staff scope.', 500);
    $stmt->bind_param('is', $companyId, $oldName);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception('Unable to verify department staff scope.', 500);
    }
    $employees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($employees as $employee) {
        if (($employee['staff_type'] ?? null) === $staffType) continue;
        throw new Exception('This department contains employees outside your granted staff scope. Ask an All-scope administrator to rename it.', 403);
    }
}
