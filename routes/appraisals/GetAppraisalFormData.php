<?php
require_once __DIR__ . '/../../includes/ContentTranslationPeople.php';

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/AdminStaffScope.php';
require_once __DIR__ . '/AppraisalHelpers.php';
require_once 'includes/ContentTranslationAppraisal.php';

header('Content-Type: application/json');

function jsonResponse($status, $message, $data = [], $code = 200) {
    http_response_code($code);
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]);
    exit;
}
function esc($conn, $value) { return $conn->real_escape_string(trim((string)$value)); }
function fetchOneRaw($conn, $sql) { $r = $conn->query($sql); if (!$r) throw new Exception('Database error: '.$conn->error, 500); return $r->fetch_assoc(); }
function fetchAllRaw($conn, $sql) { $r = $conn->query($sql); if (!$r) throw new Exception('Database error: '.$conn->error, 500); $rows=[]; while($row=$r->fetch_assoc()) $rows[]=$row; return $rows; }
function roleKeySql($alias) { return "LOWER(REPLACE(TRIM({$alias}.name), ' ', '_'))"; }
function fullName($row) { return trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: ($row['fullname'] ?? ''); }

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Exception('Bad Request: Only GET method is allowed', 405);

    $userData = requireRoles(['super_admin', 'admin', 'supervisor']);
    $loggedInUserId = (int)$userData['id'];
    $loggedInRole = $userData['role'] ?? '';
    $loggedInRoleKey = strtolower(str_replace(' ', '_', trim((string)$loggedInRole)));
    $loggedInCompanyId = isset($userData['company_id']) ? (int)$userData['company_id'] : 0;

    $appraisalId = isset($_GET['appraisal_id']) ? (int)$_GET['appraisal_id'] : 0;
    $cycleId = isset($_GET['cycle_id']) ? (int)$_GET['cycle_id'] : 0;
    $staffUserId = isset($_GET['staff_user_id']) ? (int)$_GET['staff_user_id'] : 0;

    $existing = null;
    if ($appraisalId > 0) {
        $existing = fetchOneRaw($conn, "
            SELECT ap.*, ac.year AS cycle_year, ac.title AS cycle_title, c.name AS company_name, c.code AS company_code
            FROM appraisals ap
            INNER JOIN appraisal_cycles ac ON ac.id = ap.cycle_id
            INNER JOIN companies c ON c.id = ap.company_id
            WHERE ap.id = {$appraisalId}
            LIMIT 1
        ");
        if (!$existing) throw new Exception('Appraisal not found.', 404);
        $cycleId = (int)$existing['cycle_id'];
        $staffUserId = (int)$existing['staff_user_id'];
    }

    if ($cycleId <= 0 || $staffUserId <= 0) {
        throw new Exception('cycle_id and staff_user_id are required.', 400);
    }

    $companyScope = resolveCompanyScope($userData);
    $cycleCompanySql = $companyScope !== null ? " AND company_id = " . (int) $companyScope : '';
    $cycle = fetchOneRaw($conn, "
        SELECT id, company_id, year, title, start_date, end_date, is_active
        FROM appraisal_cycles
        WHERE id = {$cycleId} {$cycleCompanySql}
        LIMIT 1
    ");
    if (!$cycle) throw new Exception('Selected appraisal cycle was not found or is outside your company scope.', 404);
    $companyId = (int)$cycle['company_id'];

    $staff = fetchOneRaw($conn, "
        SELECT u.id, u.first_name, u.last_name, u.fullname, u.email, u.staff_id, u.unique_ref,
               u.department, u.job_title, u.staff_type, u.location, u.date_of_joining, u.company_id,
               r.name AS role_name
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        WHERE u.id = {$staffUserId}
          AND u.company_id = {$companyId}
          AND u.is_active = 1
          AND " . appraiseeRoleWhere('r') . "
        LIMIT 1
    ");
    if (!$staff) throw new Exception('Staff member was not found for this cycle.', 404);

    // The home admin's supervisor assignment bypasses admin staff scope only
    // for this particular appraisal cycle. Other admin permissions stay intact.
    if ($loggedInRoleKey === 'admin' && !staffScopeAllowsSubject(
        $conn, $userData, null, $staff, $cycleId
    )) {
        throw new Exception('Unauthorized: This employee is outside your staff scope.', 403);
    }

    if ($loggedInRoleKey === 'admin' && $companyId !== $loggedInCompanyId) {
        throw new Exception('Unauthorized: This appraisal does not belong to your company.', 403);
    }

    if (!userCanConductAppraisals($userData)) {
        throw new Exception('Unauthorized: Only a user configured as a supervisor can start or edit an appraisal.', 403);
    }

    $assignment = fetchOneRaw($conn, "
        SELECT id, supervisor_id
        FROM supervisor_assignments
        WHERE cycle_id = {$cycleId}
          AND staff_id = {$staffUserId}
        ORDER BY id ASC
        LIMIT 1
    ");

    if (!$assignment) {
        throw new Exception('Assign this employee to a supervisor before starting the appraisal.', 400);
    }

    $supervisorId = (int) $assignment['supervisor_id'];
    if ($supervisorId !== $loggedInUserId) {
        throw new Exception('This employee is not assigned to you for this appraisal cycle.', 403);
    }

    if ($existing && (int) $existing['supervisor_id'] !== $loggedInUserId) {
        throw new Exception('Unauthorized: You can only edit appraisals assigned to you.', 403);
    }

    $onboard = fetchOneRaw($conn, "SELECT id FROM supervisor_onboarding WHERE cycle_id = {$cycleId} AND supervisor_id = {$supervisorId} LIMIT 1");
    if (!$onboard) throw new Exception('Complete supervisor onboarding before starting or editing this appraisal.', 403);

    $supervisor = fetchOneRaw($conn, "
        SELECT u.id, u.first_name, u.last_name, u.fullname, u.email, u.department, u.job_title, r.name AS role_name
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        WHERE u.id = {$supervisorId}
        LIMIT 1
    ");

    $existingGeneralRatings = [];
    $existingKpiRatings = [];
    $existingSectionRating = [];

    if ($existing) {
        $genRatings = fetchAllRaw($conn, "SELECT general_question_id, question_text, rating, rating_comment FROM appraisal_section_responses WHERE appraisal_id = {$appraisalId}");
        foreach ($genRatings as $row) $existingGeneralRatings[(int)$row['general_question_id']] = $row;

        $kpiRatings = fetchAllRaw($conn, "SELECT kpi_question_id, rating, question_weight, objective_target, evidence_source, actual_result, rating_comment FROM appraisal_kpi_responses WHERE appraisal_id = {$appraisalId}");
        foreach ($kpiRatings as $row) {
            $existingKpiRatings[(int)$row['kpi_question_id']] = [
                'rating' => $row['rating'],
                'question_weight' => $row['question_weight'],
                'objective_target' => $row['objective_target'] ?? null,
                'evidence_source' => $row['evidence_source'] ?? null,
                'actual_result' => $row['actual_result'] ?? null,
                'rating_comment' => $row['rating_comment'] ?? null,
            ];
        }

        $scoreModes = fetchAllRaw($conn, "SELECT section_id, section_code, section_label, section_weight, rating_mode, overall_rating, overall_rating_comment, section_avg, question_weighting_mode FROM appraisal_section_scores WHERE appraisal_id = {$appraisalId}");
        foreach ($scoreModes as $score) {
            $existingSectionRating[(int)$score['section_id']] = [
                'rating_mode' => ($score['rating_mode'] ?? 'per_question') === 'overall' ? 'overall' : 'per_question',
                'overall_rating' => ($score['rating_mode'] ?? '') === 'overall' ? ($score['overall_rating'] ?? $score['section_avg']) : '',
                'overall_rating_comment' => ($score['rating_mode'] ?? '') === 'overall' ? ($score['overall_rating_comment'] ?? '') : '',
                'question_weighting_mode' => ($score['question_weighting_mode'] ?? 'equal') === 'custom' ? 'custom' : 'equal',
                'section_weight' => isset($score['section_weight']) ? (float)$score['section_weight'] : null,
                'section_code' => $score['section_code'] ?? null,
                'section_label' => $score['section_label'] ?? null,
            ];
        }
    }

    $sectionScopeSql = $existing
        ? " AND EXISTS (SELECT 1 FROM appraisal_section_scores saved_sc WHERE saved_sc.appraisal_id = {$appraisalId} AND saved_sc.section_id = s.id)"
        : " AND s.is_active = 1";

    $sections = fetchAllRaw($conn, "
        SELECT s.id, s.code, s.label, s.description, s.type, s.weight, s.default_rating_mode, s.lock_rating_mode, s.sort_order,
               COALESCE(os.enabled,0) AS objective_setup_enabled,
               COALESCE(os.min_objectives,1) AS objective_min_objectives,
               os.max_objectives AS objective_max_objectives,
               COALESCE(os.require_weights,0) AS objective_require_weights,
               COALESCE(os.required_weight_total,100.00) AS objective_required_weight_total,
               COALESCE(os.require_target,0) AS objective_require_target,
               COALESCE(os.require_evidence,0) AS objective_require_evidence,
               COALESCE(os.require_actual_result,0) AS objective_require_actual_result,
               COALESCE(os.allow_individual_objectives,1) AS objective_allow_individual_objectives,
               COALESCE(os.require_locked_plan,1) AS objective_require_locked_plan,
               COALESCE(os.lock_questions_during_appraisal,1) AS objective_lock_questions_during_appraisal,
               COALESCE(os.force_per_question_ratings,1) AS objective_force_per_question_ratings
        FROM appraisal_sections s
        LEFT JOIN appraisal_section_objective_settings os ON os.section_id = s.id
        WHERE s.company_id = {$companyId}
          AND s.cycle_id = {$cycleId}
          {$sectionScopeSql}
        ORDER BY s.sort_order ASC, s.id ASC
    ");

    $ratingConfig = $existing ? json_decode((string)($existing['rating_config_snapshot'] ?? ''), true) : null;
    if (!is_array($ratingConfig) || !isset($ratingConfig['rating_min'], $ratingConfig['rating_max'], $ratingConfig['rating_step'])) {
        $ratingConfig = apCycleRatingConfig($conn, $companyId, $cycleId);
    }
    if ($existing) {
        $savedProfile = null;
        $savedProfileCode = trim((string)($existing['scoring_profile_code'] ?? ''));
        if ($savedProfileCode !== '') {
            $savedProfileRow = fetchOneRaw($conn, "SELECT id, code, label FROM appraisal_scoring_profiles WHERE company_id={$companyId} AND cycle_id={$cycleId} AND code='" . esc($conn, $savedProfileCode) . "' LIMIT 1");
            if ($savedProfileRow) {
                $savedProfile = [
                    'id' => (int)$savedProfileRow['id'],
                    'code' => (string)$savedProfileRow['code'],
                    'label' => (string)$savedProfileRow['label'],
                ];
            }
        }
        if (!$savedProfile && (!empty($existing['scoring_profile_code']) || !empty($existing['scoring_profile_label']))) {
            $savedProfile = [
                'id' => null,
                'code' => $existing['scoring_profile_code'] ?? '',
                'label' => $existing['scoring_profile_label'] ?? '',
            ];
        }
        $scoringContext = ['profile' => $savedProfile, 'weights' => []];
    } else {
        $scoringContext = apResolveScoringProfile($conn, $companyId, $cycleId, $staffUserId, $sections);
    }

    $dept = esc($conn, $staff['department'] ?? '');
    $formSections = [];
    foreach ($sections as $section) {
        $section['base_weight'] = (float)$section['weight'];
        if ($existing && isset($existingSectionRating[(int)$section['id']]['section_weight'])) {
            $savedSection = $existingSectionRating[(int)$section['id']];
            $section['weight'] = (float)$savedSection['section_weight'];
            if (!empty($savedSection['section_code'])) $section['code'] = $savedSection['section_code'];
            if (!empty($savedSection['section_label'])) $section['label'] = $savedSection['section_label'];
        } elseif (!$existing) {
            $section['weight'] = (float)$scoringContext['weights'][(int)$section['id']];
        }
        $sectionId = (int)$section['id'];
        $questions = [];
        $objectivePlan = null;

        if ($section['type'] === 'general') {
            $profileId = (int)($scoringContext['profile']['id'] ?? 0);
            if ($existing) {
                $questionRows = fetchAllRaw($conn, "
                    SELECT r.general_question_id AS id, r.question_text, r.rating, r.rating_comment,
                           COALESCE(q.question_code,'') AS question_code,
                           COALESCE(q.question_label,'') AS question_label,
                           q.anchor_low_value, q.anchor_low_text, q.anchor_mid_value, q.anchor_mid_text,
                           q.anchor_high_value, q.anchor_high_text, COALESCE(q.sort_order,r.id) AS sort_order
                    FROM appraisal_section_responses r
                    LEFT JOIN general_questions q ON q.id=r.general_question_id
                    WHERE r.appraisal_id={$appraisalId} AND r.section_id={$sectionId}
                    ORDER BY r.id ASC
                ");
            } else {
                $profileFilter = $profileId > 0 ? "AND (NOT EXISTS (SELECT 1 FROM general_question_scoring_profiles gqp0 WHERE gqp0.general_question_id=gq.id) OR EXISTS (SELECT 1 FROM general_question_scoring_profiles gqp1 WHERE gqp1.general_question_id=gq.id AND gqp1.profile_id={$profileId}))" : '';
                $questionRows = fetchAllRaw($conn, "
                    SELECT gq.id, gq.question_text, gq.question_code, gq.question_label,
                           gq.anchor_low_value, gq.anchor_low_text, gq.anchor_mid_value, gq.anchor_mid_text,
                           gq.anchor_high_value, gq.anchor_high_text, gq.sort_order,
                           NULL AS rating, NULL AS rating_comment
                    FROM general_questions gq
                    WHERE gq.company_id = {$companyId}
                      AND gq.section_id = {$sectionId}
                      AND gq.is_active = 1
                      {$profileFilter}
                    ORDER BY gq.sort_order ASC, gq.id ASC
                ");
            }
            foreach ($questionRows as $q) {
                $qid = (int)$q['id'];
                $saved = $existingGeneralRatings[$qid] ?? [];
                $questions[] = [
                    'id' => $qid,
                    'question_id' => $qid,
                    'question_type' => 'general',
                    'question_code' => $q['question_code'] ?? '',
                    'question_label' => $q['question_label'] ?? '',
                    'question_text' => $q['question_text'],
                    'anchor_low_value' => $q['anchor_low_value'] !== null ? (float)$q['anchor_low_value'] : null,
                    'anchor_low_text' => $q['anchor_low_text'] ?? null,
                    'anchor_mid_value' => $q['anchor_mid_value'] !== null ? (float)$q['anchor_mid_value'] : null,
                    'anchor_mid_text' => $q['anchor_mid_text'] ?? null,
                    'anchor_high_value' => $q['anchor_high_value'] !== null ? (float)$q['anchor_high_value'] : null,
                    'anchor_high_text' => $q['anchor_high_text'] ?? null,
                    'sort_order' => (int)$q['sort_order'],
                    'rating' => $q['rating'] ?? ($saved['rating'] ?? ''),
                    'rating_comment' => $q['rating_comment'] ?? ($saved['rating_comment'] ?? ''),
                ];
            }
        } else {
            $objectiveSetupEnabled = (int)($section['objective_setup_enabled'] ?? 0) === 1;
            $objectivePlan = null;
            $assignmentCount = 0;

            $assignmentRow = fetchOneRaw($conn, "SELECT COUNT(*) AS total FROM staff_kpi_assignments WHERE section_id = {$sectionId} AND staff_user_id = {$staffUserId}");
            $assignmentCount = (int)($assignmentRow['total'] ?? 0);

            if ($objectiveSetupEnabled) {
                $objectivePlan = fetchOneRaw($conn, "SELECT id, status, locked_at FROM staff_objective_plans WHERE section_id = {$sectionId} AND staff_user_id = {$staffUserId} LIMIT 1");
                if (!$existing && (int)($section['objective_require_locked_plan'] ?? 1) === 1 && (!$objectivePlan || ($objectivePlan['status'] ?? '') !== 'locked')) {
                    throw new Exception("Set and lock this employee's objectives for section {$section['code']} before starting the appraisal.", 409);
                }
                if ($existing && !$objectivePlan) {
                    // Preserve legacy edit behaviour when objective planning was enabled
                    // only after this appraisal had already been submitted.
                    $section['objective_setup_enabled'] = 0;
                    $section['objective_require_actual_result'] = 0;
                    $objectiveSetupEnabled = false;
                }
            }

            if ($existing) {
                // Existing appraisals always reopen from their own snapshots.
                $questionRows = fetchAllRaw($conn, "
                    SELECT r.kpi_question_id AS id, r.question_text, r.objective_target, r.evidence_source, r.actual_result, r.rating_comment,
                           q.objective_code, q.department, q.supervisor_id, q.staff_user_id, q.sort_order,
                           r.rating, r.question_weight,
                           CASE
                               WHEN q.staff_user_id = {$staffUserId} THEN 'individual'
                               WHEN q.supervisor_id = {$supervisorId} THEN 'supervisor'
                               ELSE 'appraisal'
                           END AS scope
                    FROM appraisal_kpi_responses r
                    LEFT JOIN kpi_questions q ON q.id = r.kpi_question_id
                    WHERE r.appraisal_id = {$appraisalId}
                      AND r.section_id = {$sectionId}
                    ORDER BY r.id ASC
                ");
            } elseif ($assignmentCount > 0) {
                // A per-staff selection is authoritative: use exactly the assigned set.
                $questionRows = fetchAllRaw($conn, "
                    SELECT q.id, q.objective_code, q.question_text,
                           COALESCE(ska.target_override,q.measure_target) AS objective_target,
                           COALESCE(ska.evidence_override,q.evidence_source) AS evidence_source,
                           q.department, q.supervisor_id, q.staff_user_id, q.sort_order, NULL AS rating, NULL AS actual_result, NULL AS rating_comment,
                           COALESCE(ska.weight_percent,q.weight_percent) AS question_weight,
                           CASE
                               WHEN q.staff_user_id = {$staffUserId} THEN 'individual'
                               WHEN q.supervisor_id = {$supervisorId} THEN 'supervisor'
                               ELSE 'department'
                           END AS scope
                    FROM staff_kpi_assignments ska
                    INNER JOIN kpi_questions q ON q.id = ska.kpi_question_id
                    WHERE ska.section_id = {$sectionId}
                      AND ska.staff_user_id = {$staffUserId}
                      AND q.company_id = {$companyId}
                      AND q.is_active = 1
                    ORDER BY q.sort_order ASC, ska.id ASC
                ");
            } else {
                // Legacy/no-plan cycles retain the departmental/supervisor/individual fallback.
                $questionRows = fetchAllRaw($conn, "
                    SELECT DISTINCT q.id, q.objective_code, q.question_text, q.measure_target AS objective_target,
                           q.evidence_source, q.department, q.supervisor_id, q.staff_user_id, q.sort_order, NULL AS rating, NULL AS actual_result, NULL AS rating_comment,
                           q.weight_percent AS question_weight,
                           CASE
                               WHEN q.staff_user_id = {$staffUserId} THEN 'individual'
                               WHEN q.supervisor_id = {$supervisorId} THEN 'supervisor'
                               ELSE 'department'
                           END AS scope
                    FROM kpi_questions q
                    WHERE q.company_id = {$companyId}
                      AND q.section_id = {$sectionId}
                      AND q.is_active = 1
                      AND (
                            q.staff_user_id = {$staffUserId}
                            OR (q.supervisor_id = {$supervisorId} AND q.staff_user_id IS NULL)
                            OR (q.department = '{$dept}' AND q.supervisor_id IS NULL AND q.staff_user_id IS NULL)
                      )
                    ORDER BY scope ASC, q.sort_order ASC, q.id ASC
                ");
            }

            foreach ($questionRows as $q) {
                $qid = (int)$q['id'];
                $questions[] = [
                    'id' => $qid,
                    'question_id' => $qid,
                    'question_type' => 'kpi',
                    'objective_code' => $q['objective_code'] ?? null,
                    'question_text' => $q['question_text'],
                    'objective_target' => $q['objective_target'] ?? ($existingKpiRatings[$qid]['objective_target'] ?? null),
                    'evidence_source' => $q['evidence_source'] ?? ($existingKpiRatings[$qid]['evidence_source'] ?? null),
                    'actual_result' => $q['actual_result'] ?? ($existingKpiRatings[$qid]['actual_result'] ?? ''),
                    'rating_comment' => $q['rating_comment'] ?? ($existingKpiRatings[$qid]['rating_comment'] ?? ''),
                    'sort_order' => (int)($q['sort_order'] ?? 0),
                    'scope' => $q['scope'],
                    'rating' => $q['rating'] ?? ($existingKpiRatings[$qid]['rating'] ?? ''),
                    'question_weight' => $q['question_weight'] ?? ($existingKpiRatings[$qid]['question_weight'] ?? null),
                    'suggested_weight' => $q['question_weight'] ?? null,
                ];
            }
        }

        // A new KPI section with a complete Admin-configured 100% weight set
        // should open ready for per-question/custom weighting. All other new
        // sections keep the cumulative default. Existing appraisals retain the
        // exact mode that was saved with the appraisal snapshot.
        $hasCompleteConfiguredKpiWeights = false;
        if (!$existing && ($section['type'] ?? '') === 'kpi' && count($questions) > 0) {
            $configuredWeightTotal = 0.0;
            $configuredWeightCount = 0;
            foreach ($questions as $question) {
                $weight = isset($question['question_weight']) && $question['question_weight'] !== ''
                    ? (float)$question['question_weight']
                    : 0.0;
                if ($weight > 0 && $weight <= 100) {
                    $configuredWeightCount++;
                    $configuredWeightTotal += $weight;
                }
            }
            $hasCompleteConfiguredKpiWeights = $configuredWeightCount === count($questions)
                && abs($configuredWeightTotal - 100.0) <= 0.01;
        }

        $forcePerQuestion = !$existing && ($section['type'] ?? '') === 'kpi' && (int)($section['objective_setup_enabled'] ?? 0) === 1 && (int)($section['objective_force_per_question_ratings'] ?? 1) === 1;
        $configuredDefaultMode = (($section['default_rating_mode'] ?? 'overall') === 'per_question') ? 'per_question' : 'overall';
        $defaultRatingMode = $existing
            ? 'per_question'
            : (($forcePerQuestion || $hasCompleteConfiguredKpiWeights) ? 'per_question' : $configuredDefaultMode);
        $savedMode = $existingSectionRating[$sectionId] ?? [
            'rating_mode' => $defaultRatingMode,
            'overall_rating' => '',
            'overall_rating_comment' => '',
            'question_weighting_mode' => $hasCompleteConfiguredKpiWeights ? 'custom' : 'equal',
        ];
        $formSections[] = array_merge($section, [
            'rating_mode' => $savedMode['rating_mode'],
            'overall_rating' => $savedMode['overall_rating'],
            'overall_rating_comment' => $savedMode['overall_rating_comment'] ?? '',
            'question_weighting_mode' => $section['type'] === 'kpi'
                ? (((int)($section['objective_setup_enabled'] ?? 0) === 1 && (int)($section['objective_require_weights'] ?? 0) === 1) ? 'custom' : ($savedMode['question_weighting_mode'] ?? 'equal'))
                : 'equal',
            'objective_plan_locked' => isset($objectivePlan) && is_array($objectivePlan) && ($objectivePlan['status'] ?? '') === 'locked' ? 1 : 0,
            'questions' => $questions,
        ]);
    }

    $formResponse = [
        'mode' => $existing ? 'edit' : 'create',
        'appraisal' => $existing,
        'cycle' => $cycle,
        'staff' => array_merge($staff, ['full_name' => fullName($staff)]),
        'supervisor' => $supervisor ? array_merge($supervisor, ['full_name' => fullName($supervisor)]) : null,
        'sections' => $formSections,
        'rating_config' => $ratingConfig,
        'scoring_profile' => $scoringContext['profile'],
    ];
    // Frozen question snapshots are translated on read only. *_source fields
    // always contain English for form editing and appraisal submissions.
    contentTranslationApplyPaths($conn, $formResponse, $userData, contentTranslationAppraisalFormPaths());
    contentTranslationAddJobTitleDisplays($conn, $formResponse, $userData);
    jsonResponse('Success', 'Appraisal form data fetched successfully.', $formResponse);

} catch (Exception $e) {
    $code = $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    jsonResponse('Failed', $e->getMessage(), [], $code);
}
