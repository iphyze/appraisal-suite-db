<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/AdminStaffScope.php';
require_once 'includes/AdminAppraisalVisibility.php';
require_once 'includes/CompanyExportAccess.php';
require_once __DIR__ . '/../dashboard/ManagementInsights.php';
require_once __DIR__ . '/../utils/SimpleXlsx.php';
require_once __DIR__ . '/../../includes/ExportLocalization.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Exception('Bad Request: Only GET method is allowed', 405);

    $userData = requireRoles(['super_admin', 'admin']);
    $locale = exportLocale($userData);
    $role = strtolower(str_replace(' ', '_', trim((string)($userData['role'] ?? ''))));
    $userId = (int)($userData['id'] ?? 0);
    $actingContext = $role === 'admin' ? resolveActingCompanyContext($userData) : null;
    $cycleId = (int)($_GET['cycle_id'] ?? 0);
    if ($cycleId <= 0) throw new Exception('Please select an appraisal cycle to export.', 400);

    $cycle = managementBindAndFetchOne($conn, "
        SELECT ac.id, ac.company_id, ac.year, ac.title, c.name AS company_name, c.code AS company_code,
               COALESCE(acs.management_review_enabled,0) AS management_review_enabled
        FROM appraisal_cycles ac
        INNER JOIN companies c ON c.id = ac.company_id
        LEFT JOIN appraisal_cycle_settings acs ON acs.cycle_id = ac.id
        WHERE ac.id = ?
        LIMIT 1
    ", 'i', [$cycleId]);
    if (!$cycle) throw new Exception('Selected appraisal cycle was not found.', 404);

    $companyId = (int)$cycle['company_id'];
    $companyScope = $actingContext !== null
        ? (int)$actingContext['company_id'] : resolveCompanyScope($userData);
    companyExportAssertCycleScope($role === 'admin'
        ? $actingContext : ['company_id' => $companyScope], $companyId);

    $whereParts = ['ap.company_id = ?', 'ap.cycle_id = ?'];
    $types = 'ii';
    $params = [$companyId, $cycleId];
    $eligibleUserWhere = '1=1';
    $eligibleTypes = '';
    $eligibleParams = [];

    if ($role === 'admin' && empty($actingContext['is_home'])) {
        $grantAppraisal = companyExportGrantAppraisalFilter($actingContext, $userId, 'ap');
        $whereParts[] = substr($grantAppraisal['sql'], 5);
        $types .= $grantAppraisal['types'];
        array_push($params, ...$grantAppraisal['params']);
        $grantUsers = companyExportGrantUserFilter($actingContext, $userId, 'u', 'r', true);
        $eligibleUserWhere = '1=1' . $grantUsers['sql'];
        $eligibleTypes = $grantUsers['types'];
        $eligibleParams = $grantUsers['params'];
    } elseif ($role === 'admin') {
        $whereParts[] = adminAppraisalVisibilityWhereSql($userId, $companyId, 'ap');
        $eligibleUserWhere = adminAppraiseeVisibilityWhereSql($userId, $companyId, 'u', 'r', $cycleId);
        $staffScope = trim((string)($userData['staff_scope'] ?? 'All'));
        if (in_array($staffScope, ['Local', 'Expatriate'], true)) {
            $whereParts[] = staffScopeHomeAppraisalSql($userData, 'ap', true);
            $types .= 's';
            $params[] = $staffScope;
            $eligibleUserWhere .= ' AND ' . staffScopeHomeUserSql($userData, $cycleId, 'u', 'r', true);
            $eligibleTypes .= 's';
            $eligibleParams[] = $staffScope;
        }
    }

    $whereSql = implode(' AND ', $whereParts);
    $insights = fetchManagementInsights(
        $conn,
        $whereSql,
        $types,
        $params,
        (int)($cycle['management_review_enabled'] ?? 0) === 1
    );
    $insights['objective_setup'] = fetchObjectiveSetupSummary(
        $conn,
        $companyId,
        $cycleId,
        $eligibleUserWhere,
        $eligibleTypes,
        $eligibleParams
    );

    $overall = managementBindAndFetchOne($conn, "
        SELECT COUNT(*) AS total_appraised,
               SUM(CASE WHEN ap.status='Acknowledged' THEN 1 ELSE 0 END) AS acknowledged,
               SUM(CASE WHEN ap.status='Pending' THEN 1 ELSE 0 END) AS pending_acknowledgement,
               ROUND(AVG(ap.appraisal_summary),2) AS avg_score
        FROM appraisals ap
        WHERE {$whereSql}
    ", $types, $params);

    $departments = managementBindAndFetchAll($conn, "
        SELECT ap.staff_department AS department,
               COUNT(*) AS appraised,
               SUM(CASE WHEN ap.status='Acknowledged' THEN 1 ELSE 0 END) AS acknowledged,
               ROUND(AVG(ap.appraisal_summary),2) AS avg_score
        FROM appraisals ap
        WHERE {$whereSql}
        GROUP BY ap.staff_department
        ORDER BY avg_score DESC, department ASC
    ", $types, $params);

    $distribution = managementBindAndFetchAll($conn, "
        SELECT COALESCE(NULLIF(TRIM(ap.evaluation_statement),''),'Unrated') AS evaluation_statement,
               COUNT(*) AS cnt,
               ROUND(AVG(ap.appraisal_summary),2) AS avg_score
        FROM appraisals ap
        WHERE {$whereSql}
        GROUP BY COALESCE(NULLIF(TRIM(ap.evaluation_statement),''),'Unrated')
        ORDER BY cnt DESC
    ", $types, $params);

    $allObjectivePerformance = managementBindAndFetchAll($conn, "
        SELECT
            COALESCE(NULLIF(TRIM(kq.objective_code), ''), CONCAT('OBJ-', akr.kpi_question_id)) AS objective_code,
            akr.question_text AS objective,
            COUNT(*) AS rating_count,
            ROUND(AVG(akr.rating), 2) AS avg_rating,
            SUM(CASE WHEN NULLIF(TRIM(COALESCE(akr.actual_result, '')), '') IS NOT NULL THEN 1 ELSE 0 END) AS actual_result_count
        FROM appraisal_kpi_responses akr
        INNER JOIN appraisals ap ON ap.id = akr.appraisal_id
        LEFT JOIN kpi_questions kq ON kq.id = akr.kpi_question_id
        WHERE {$whereSql}
        GROUP BY objective_code, akr.question_text
        ORDER BY avg_rating ASC, rating_count DESC, objective_code ASC
    ", $types, $params);

    $allDevelopmentThemes = managementBindAndFetchAll($conn, "
        SELECT TRIM(ap.development) AS development, COUNT(*) AS cnt
        FROM appraisals ap
        WHERE {$whereSql}
          AND NULLIF(TRIM(COALESCE(ap.development, '')), '') IS NOT NULL
          AND LOWER(TRIM(ap.development)) NOT IN ('no','none','n/a','na','nil','not required','not applicable')
        GROUP BY TRIM(ap.development)
        ORDER BY cnt DESC, development ASC
    ", $types, $params);

    $sections = managementBindAndFetchAll($conn, "
        SELECT id, code, label
        FROM appraisal_sections
        WHERE cycle_id=? AND is_active=1
        ORDER BY sort_order ASC, id ASC
    ", 'i', [$cycleId]);

    $appraisals = managementBindAndFetchAll($conn, "
        SELECT ap.id, ap.staff_fullname, ap.staff_department, ap.staff_location, ap.staff_job_title,
               ap.staff_type, COALESCE(ap.scoring_profile_label,'') AS scoring_profile,
               TRIM(CONCAT(COALESCE(sup.first_name,''),' ',COALESCE(sup.last_name,''))) AS supervisor_name,
               ap.appraisal_summary, COALESCE(ap.raw_appraisal_summary, ap.appraisal_summary) AS raw_appraisal_summary,
               ap.serious_breach_declared, ap.breach_cap_active, ap.serious_breach_review_status,
               ap.evaluation_statement, ap.status,
               ap.development, ap.status_upgrade, ap.salary_upgrade, ap.update_count,
               CASE
                   WHEN mr.id IS NULL THEN 'Unreviewed'
                   WHEN mr.appraisal_revision_no <> (COALESCE(ap.update_count,0)+1) THEN 'Unreviewed - Changed Since Review'
                   WHEN mr.review_status='follow_up' THEN 'Follow-up Required'
                   ELSE 'Reviewed'
               END AS management_review_status
        FROM appraisals ap
        LEFT JOIN users sup ON sup.id=ap.supervisor_id
        LEFT JOIN appraisal_management_reviews mr ON mr.id=(
            SELECT MAX(mr2.id) FROM appraisal_management_reviews mr2 WHERE mr2.appraisal_id=ap.id
        )
        WHERE {$whereSql}
        ORDER BY ap.staff_department ASC, ap.staff_fullname ASC
    ", $types, $params);

    $sectionRows = managementBindAndFetchAll($conn, "
        SELECT ap.id AS appraisal_id, ss.section_id, ss.section_avg, ss.section_weight
        FROM appraisals ap
        INNER JOIN appraisal_section_scores ss ON ss.appraisal_id=ap.id
        WHERE {$whereSql}
    ", $types, $params);
    $scoreMap = [];
    foreach ($sectionRows as $row) {
        $scoreMap[(int)$row['appraisal_id']][(int)$row['section_id']] = $row;
    }

    // One batched lookup for all configured labels and frozen English snapshots.
    $approved = exportContentMap($conn, $locale, [
        ['context' => 'cycle_title', 'values' => [$cycle['title']]],
        ['context' => 'department', 'values' => array_merge(array_column($departments, 'department'), array_column($appraisals, 'staff_department'))],
        ['context' => 'job_title', 'values' => array_column($appraisals, 'staff_job_title')],
        ['context' => 'evaluation_statement', 'values' => array_merge(array_column($distribution, 'evaluation_statement'), array_column($appraisals, 'evaluation_statement'))],
        ['context' => 'scoring_profile', 'values' => array_merge(array_column($appraisals, 'scoring_profile'), array_column($insights['profile_distribution'] ?? [], 'profile_label'))],
        ['context' => 'section', 'values' => array_merge(array_column($sections, 'label'), array_column($insights['section_performance'] ?? [], 'section_label'), array_column($insights['objective_setup']['sections'] ?? [], 'section_label'))],
        ['context' => 'question', 'values' => array_column($allObjectivePerformance, 'objective')],
    ]);

    $summaryRows = [
        ['Company', $cycle['company_name']],
        ['Cycle', exportContent($approved, 'cycle_title', $cycle['title']) . ' (' . $cycle['year'] . ')'],
        ['Appraised Employees', (int)($overall['total_appraised'] ?? 0)],
        ['Acknowledged', (int)($overall['acknowledged'] ?? 0)],
        ['Pending Acknowledgement', (int)($overall['pending_acknowledgement'] ?? 0)],
        ['Average Score', $overall['avg_score'] ?? ''],
        ['Objective Plans Locked', (int)($insights['objective_setup']['locked'] ?? 0)],
        ['Objective Plans Required', (int)($insights['objective_setup']['required_plans'] ?? 0)],
        ['Objective Setup Progress %', $insights['objective_setup']['progress_percent'] ?? 0],
        ['Management Follow-up Required', (int)($insights['management_review']['follow_up'] ?? 0)],
        ['Management Reviewed', (int)($insights['management_review']['reviewed'] ?? 0)],
        ['Management Unreviewed/Stale', (int)($insights['management_review']['unreviewed'] ?? 0) + (int)($insights['management_review']['stale'] ?? 0)],
        ['Appraisals Revised By Supervisors', (int)($insights['revision_summary']['revised_appraisals'] ?? 0)],
        ['Evidence Exceptions', (int)($insights['evidence_exception_count'] ?? 0)],
    ];

    $detailHeaders = ['S/N','Full Name','Department','Location','Job Title','Staff Type','Scoring Profile','Supervisor'];
    foreach ($sections as $section) {
        $prefix = trim((string)$section['code']) !== '' ? $section['code'] . ' - ' : '';
        $translatedSection = exportContent($approved, 'section', $section['label']);
        $detailHeaders[] = $prefix . $translatedSection . ' ' . exportLabel($locale, 'Score');
        $detailHeaders[] = $prefix . $translatedSection . ' ' . exportLabel($locale, 'Weight Used');
    }
    $detailHeaders = array_merge($detailHeaders, ['Original Weighted Score','Final Score','Breach Declared','Breach Cap','Safety Verification','Evaluation','Employee Status','Management Review','Development','Status Upgrade','Salary Upgrade','Supervisor Updates']);

    $detailRows = [];
    foreach ($appraisals as $index => $ap) {
        $row = [$index+1,$ap['staff_fullname'],exportContent($approved, 'department', $ap['staff_department']),$ap['staff_location'],exportContent($approved, 'job_title', $ap['staff_job_title']),exportLabel($locale, $ap['staff_type']),exportContent($approved, 'scoring_profile', $ap['scoring_profile']),$ap['supervisor_name']];
        foreach ($sections as $section) {
            $score = $scoreMap[(int)$ap['id']][(int)$section['id']] ?? [];
            $row[] = exportNumber($score['section_avg'] ?? '');
            $row[] = exportNumber($score['section_weight'] ?? '');
        }
        $row = array_merge($row, [
            exportNumber($ap['raw_appraisal_summary']), exportNumber($ap['appraisal_summary']),
            exportLabel($locale, $ap['serious_breach_declared'] === null ? 'Not recorded' : ((int)$ap['serious_breach_declared'] === 1 ? 'Yes' : 'No')),
            exportLabel($locale, (int)$ap['breach_cap_active'] === 1 ? 'Applied' : 'Not applied'),
            exportLabel($locale, $ap['serious_breach_review_status']),
            exportContent($approved, 'evaluation_statement', $ap['evaluation_statement']),exportLabel($locale, $ap['status']),exportLabel($locale, $ap['management_review_status']),$ap['development'],$ap['status_upgrade'],$ap['salary_upgrade'],$ap['update_count']]);
        $detailRows[] = $row;
    }

    $sheets = [
        ['name'=>'Summary','headers'=>['Metric','Value'],'rows'=>$summaryRows,'widths'=>[34,35]],
        ['name'=>'Departments','headers'=>['Department','Appraised','Acknowledged','Average Score'],'rows'=>array_map(fn($r)=>[exportContent($approved, 'department', $r['department']),$r['appraised'],$r['acknowledged'],exportNumber($r['avg_score'])],$departments),'widths'=>[28,14,16,16]],
        ['name'=>'Score Distribution','headers'=>['Evaluation Band','Count','Average Score'],'rows'=>array_map(fn($r)=>[exportContent($approved, 'evaluation_statement', $r['evaluation_statement']),$r['cnt'],exportNumber($r['avg_score'])],$distribution),'widths'=>[32,12,16]],
        ['name'=>'Scoring Profiles','headers'=>['Profile','Count','Average Score'],'rows'=>array_map(fn($r)=>[exportContent($approved, 'scoring_profile', $r['profile_label']),$r['cnt'],exportNumber($r['avg_score'])],$insights['profile_distribution'] ?? []),'widths'=>[28,12,16]],
        ['name'=>'Section Performance','headers'=>['Section Code','Section','Appraisals','Average Score','Average Weighted Score'],'rows'=>array_map(fn($r)=>[$r['section_code'],exportContent($approved, 'section', $r['section_label']),$r['appraisal_count'],exportNumber($r['avg_score']),exportNumber($r['avg_weighted_score'])],$insights['section_performance'] ?? []),'widths'=>[14,34,14,16,22]],
        ['name'=>'Objective Performance','headers'=>['Objective Code','Objective','Ratings','Average Rating','Actual Result Recorded'],'rows'=>array_map(fn($r)=>[$r['objective_code'],exportContent($approved, 'question', $r['objective']),$r['rating_count'],exportNumber($r['avg_rating']),$r['actual_result_count']],$allObjectivePerformance),'widths'=>[18,64,12,16,22]],
        ['name'=>'Objective Setup','headers'=>['Section','Required Plans','Locked','Draft','Missing','Progress %'],'rows'=>array_map(fn($r)=>[exportContent($approved, 'section', $r['section_label']),$r['required_plans'],$r['locked'],$r['draft'],$r['missing'],exportNumber($r['progress_percent'])],$insights['objective_setup']['sections'] ?? []),'widths'=>[36,18,12,12,12,14]],
        ['name'=>'Oversight','headers'=>['Category','Review Status','Count'],'rows'=>array_map(fn($r)=>[exportLabel($locale, $r['category']),exportLabel($locale, $r['review_status']),$r['cnt']],$insights['review_categories'] ?? []),'widths'=>[24,22,12]],
        ['name'=>'Development Themes','headers'=>['Development Need / Theme','Count'],'rows'=>array_map(fn($r)=>[$r['development'],$r['cnt']],$allDevelopmentThemes),'widths'=>[72,12]],
        ['name'=>'Appraisal Details','headers'=>$detailHeaders,'rows'=>$detailRows],
    ];

    $safeCompany = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)($cycle['company_code'] ?: $cycle['company_name']));
    companyExportAudit($conn, $userData, $actingContext, $companyId,
        'export_management_appraisals_excel', 'appraisal_cycles', $cycleId, count($appraisals));
    streamMultiSheetXlsx(($locale === 'fr' ? 'Rapport_Evaluations_Direction_' : 'Management_Appraisal_Report_') . $safeCompany . '_' . $cycle['year'] . '.xlsx',
        exportSheetLabels($sheets, $locale, ['Summary']));
} catch (Exception $e) {
    $code = $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['status'=>'Failed','message'=>$e->getMessage()]);
}
