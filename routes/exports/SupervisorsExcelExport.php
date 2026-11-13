<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/CompanyExportAccess.php';
require_once __DIR__ . '/../utils/SimpleXlsx.php';
require_once __DIR__ . '/../../includes/ExportLocalization.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Only GET requests are allowed.', 405);
    }

    $user = requireRoles(['super_admin', 'admin']);
    $locale = exportLocale($user);
    $role = authRoleKey($user['role'] ?? '');
    $actingContext = $role === 'admin' ? resolveActingCompanyContext($user) : null;
    $cycleId = filter_var($_GET['cycle_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$cycleId) {
        throw new Exception('Select an appraisal cycle to export supervisor assignments.', 400);
    }

    // Use the same company access rules as the Supervisors and Assignments pages.
    $companyScope = $actingContext !== null
        ? (int)$actingContext['company_id'] : resolveCompanyScope($user);
    $cycleSql = 'SELECT ac.id, ac.company_id, ac.title, ac.year, c.name AS company_name '
        . 'FROM appraisal_cycles ac INNER JOIN companies c ON c.id = ac.company_id WHERE ac.id = ?';
    $cycleParams = [$cycleId];
    $cycleTypes = 'i';
    if ($companyScope !== null) {
        $cycleSql .= ' AND ac.company_id = ?';
        $cycleParams[] = $companyScope;
        $cycleTypes .= 'i';
    }
    $cycleSql .= ' LIMIT 1';
    $stmt = $conn->prepare($cycleSql);
    if (!$stmt) throw new Exception('Unable to load the appraisal cycle.', 500);
    $stmt->bind_param($cycleTypes, ...$cycleParams);
    $stmt->execute();
    $cycle = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$cycle) {
        throw new Exception('The selected appraisal cycle is unavailable for your company.', 404);
    }

    $companyId = (int) $cycle['company_id'];
    companyExportAssertCycleScope($role === 'admin'
        ? $actingContext : ['company_id' => $companyScope], $companyId);
    $roleWhere = appraiserRoleWhere('r', 'u');
    // Include inactive supervisors too, so the report covers historical assignments.
    $stmt = $conn->prepare("SELECT u.id, u.staff_id, u.first_name, u.last_name, u.email,
        u.department, u.job_title, u.staff_type, u.is_active
        FROM users u INNER JOIN roles r ON r.id = u.role_id
        WHERE u.company_id = ? AND {$roleWhere}
        ORDER BY u.first_name ASC, u.last_name ASC, u.id ASC");
    if (!$stmt) throw new Exception('Unable to load supervisors.', 500);
    $stmt->bind_param('i', $companyId);
    $stmt->execute();
    $supervisors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Assignments belong to a *cycle*, not to the supervisor's current department.
    // Restrict both sides of the relationship to the selected company's records.
    $staffSql = "SELECT sa.supervisor_id, s.staff_id, s.first_name, s.last_name,
        s.email, s.department, s.job_title, s.staff_type, s.is_active
        FROM supervisor_assignments sa
        INNER JOIN users s ON s.id = sa.staff_id AND s.company_id = ?
        INNER JOIN users u ON u.id = sa.supervisor_id AND u.company_id = ?
        INNER JOIN roles r ON r.id = u.role_id
        INNER JOIN roles sr ON sr.id = s.role_id
        WHERE sa.cycle_id = ? AND {$roleWhere}";
    $staffParams = [$companyId, $companyId, $cycleId];
    $staffTypes = 'iii';

    // Admin staff scopes must not expose the other staff type in Excel exports.
    $adminScope = $role === 'admin'
        ? (string)$actingContext['staff_scope'] : 'All';
    if ($role === 'admin' && empty($actingContext['is_home'])) {
        $grantStaff = companyExportGrantUserFilter($actingContext, (int)$user['id'], 's', 'sr', true);
        $staffSql .= $grantStaff['sql'];
        $staffTypes .= $grantStaff['types'];
        array_push($staffParams, ...$grantStaff['params']);
    } elseif (in_array($adminScope, ['Local', 'Expatriate'], true)) {
        $staffSql .= " AND (LOWER(REPLACE(TRIM(sr.name), ' ', '_')) <> 'staff' OR s.staff_type = ?)";
        $staffParams[] = $adminScope;
        $staffTypes .= 's';
    }
    $staffSql .= ' ORDER BY u.first_name, u.last_name, u.id, s.first_name, s.last_name, s.id';
    $stmt = $conn->prepare($staffSql);
    if (!$stmt) throw new Exception('Unable to load assigned staff.', 500);
    $stmt->bind_param($staffTypes, ...$staffParams);
    $stmt->execute();
    $assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $approved = exportContentMap($conn, $locale, [
        ['context' => 'department', 'values' => array_merge(
            array_column($supervisors, 'department'), array_column($assignments, 'department'))],
        ['context' => 'job_title', 'values' => array_merge(
            array_column($supervisors, 'job_title'), array_column($assignments, 'job_title'))],
        ['context' => 'cycle_title', 'values' => [$cycle['title']]],
    ]);
    $staffBySupervisor = [];
    $supervisorById = [];
    foreach ($supervisors as $supervisor) {
        $supervisorById[(int) $supervisor['id']] = $supervisor;
        $staffBySupervisor[(int) $supervisor['id']] = 0;
    }

    $detailRows = [];
    foreach ($assignments as $assignment) {
        $supervisorId = (int) $assignment['supervisor_id'];
        if (!isset($supervisorById[$supervisorId])) continue;
        $supervisor = $supervisorById[$supervisorId];
        $staffBySupervisor[$supervisorId]++;
        $detailRows[] = [
            count($detailRows) + 1,
            trim($supervisor['first_name'] . ' ' . $supervisor['last_name']),
            (string) ($supervisor['staff_id'] ?? ''),
            exportContent($approved, 'department', $supervisor['department'] ?? ''),
            trim($assignment['first_name'] . ' ' . $assignment['last_name']),
            (string) ($assignment['staff_id'] ?? ''),
            exportContent($approved, 'department', $assignment['department'] ?? ''),
            exportContent($approved, 'job_title', $assignment['job_title'] ?? ''),
            exportLabel($locale, $assignment['staff_type'] ?? ''),
            (string) ($assignment['email'] ?? ''),
            exportLabel($locale, (int) $assignment['is_active'] === 1 ? 'Active' : 'Inactive'),
        ];
    }

    $summaryRows = [];
    foreach ($supervisors as $supervisor) {
        $summaryRows[] = [
            count($summaryRows) + 1,
            trim($supervisor['first_name'] . ' ' . $supervisor['last_name']),
            (string) ($supervisor['staff_id'] ?? ''),
            exportContent($approved, 'department', $supervisor['department'] ?? ''),
            exportContent($approved, 'job_title', $supervisor['job_title'] ?? ''),
            (string) ($supervisor['email'] ?? ''),
            exportLabel($locale, (int) $supervisor['is_active'] === 1 ? 'Active' : 'Inactive'),
            (int) $staffBySupervisor[(int) $supervisor['id']],
        ];
    }

    $filename = sprintf($locale === 'fr' ? 'Affectations_Superviseurs_%s_Cycle_%d.xlsx' : 'Supervisor_Assignments_%s_Cycle_%d.xlsx', preg_replace('/[^0-9]/', '', (string) $cycle['year']), $cycleId);
    companyExportAudit($conn, $user, $actingContext, $companyId,
        'export_supervisors_excel', 'appraisal_cycles', (int)$cycleId, count($detailRows));
    $sheets = [
        [
            'name' => 'Supervisor Summary',
            'headers' => ['S/N', 'Supervisor', 'Supervisor Staff ID', 'Department', 'Job Title', 'Email', 'Status', 'Assigned Staff'],
            'rows' => $summaryRows,
            'widths' => [7, 32, 22, 24, 30, 38, 15, 18],
        ],
        [
            'name' => 'Assigned Staff',
            'headers' => ['S/N', 'Supervisor', 'Supervisor Staff ID', 'Supervisor Department', 'Assigned Employee', 'Employee Staff ID', 'Employee Department', 'Job Title', 'Staff Type', 'Employee Email', 'Status'],
            'rows' => $detailRows,
            'widths' => [7, 32, 22, 26, 32, 22, 26, 30, 18, 38, 15],
        ],
        [
            'name' => 'Report Details',
            'headers' => ['Report Field', 'Value'],
            'rows' => [
                ['Report', 'Supervisor Assignments'],
                ['Company', $cycle['company_name']],
                ['Appraisal Cycle', exportContent($approved, 'cycle_title', $cycle['title']) . ' (' . $cycle['year'] . ')'],
                ['Supervisors', count($supervisors)],
                ['Staff Assignment Records', count($detailRows)],
                ['Staff Scope', exportLabel($locale, $adminScope)],
                ['Generated On', exportDate($locale, date('Y-m-d H:i'), true)],
                ['Note', 'Includes supervisors with no assignments. Counts reflect records accessible to the exporting user.'],
            ],
            'widths' => [30, 90],
        ],
    ];
    streamMultiSheetXlsx($filename, exportSheetLabels($sheets, $locale, ['Report Details']));
} catch (Throwable $e) {
    $status = (int) $e->getCode();
    if ($status < 400 || $status > 599) $status = 500;
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to generate supervisor assignments export.' : $e->getMessage(),
    ]);
}
