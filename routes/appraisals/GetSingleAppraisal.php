<?php
require_once __DIR__ . '/../../includes/ContentTranslationPeople.php';

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once __DIR__ . '/AppraisalHelpers.php';
require_once 'includes/AdminAppraisalVisibility.php';
require_once 'includes/CompanyAccess.php';
require_once 'includes/ContentTranslationAppraisal.php';
require_once 'includes/AppraisalViewAuthorization.php';
require_once 'includes/AppraisalUpdateEligibility.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Exception('Bad Request: Only GET method is allowed', 405);

    $userData = authenticateUser();
    $loggedInUserId = (int)$userData['id'];
    $loggedInRole = $userData['role'] ?? '';
    $loggedInRoleKey = strtolower(str_replace(' ', '_', trim((string)$loggedInRole)));
    $loggedInCompanyId = isset($userData['company_id']) ? (int)$userData['company_id'] : 0;
    $loggedInCanAppraise = userCanConductAppraisals($userData);

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) throw new Exception("Missing required parameter: 'id'.", 400);

    $appraisal = apFetchOne($conn, "
        SELECT ap.*, ac.year AS cycle_year, ac.title AS cycle_title, ac.start_date AS cycle_start_date, ac.end_date AS cycle_end_date,
               c.name AS company_name, c.code AS company_code,
               COALESCE(acs.management_review_enabled, 0) AS management_review_enabled,
               TRIM(CONCAT(COALESCE(sup.first_name,''), ' ', COALESCE(sup.last_name,''))) AS supervisor_name,
               sup.email AS supervisor_email, sup.department AS supervisor_department,
               CASE
                   WHEN ap.supervisor_id = {$loggedInUserId}
                    AND " . ($loggedInCanAppraise ? "1 = 1" : "1 = 0") . "
                    AND EXISTS (
                        SELECT 1
                        FROM supervisor_assignments sa
                        WHERE sa.cycle_id = ap.cycle_id
                          AND sa.staff_id = ap.staff_user_id
                          AND sa.supervisor_id = ap.supervisor_id
                        LIMIT 1
                    )
                    AND EXISTS (
                        SELECT 1
                        FROM supervisor_onboarding so
                        WHERE so.cycle_id = ap.cycle_id
                          AND so.supervisor_id = ap.supervisor_id
                        LIMIT 1
                    )
                   THEN 1
                   ELSE 0
               END AS can_manage_appraisal
        FROM appraisals ap
        INNER JOIN appraisal_cycles ac ON ac.id = ap.cycle_id
        INNER JOIN companies c ON c.id = ap.company_id
        LEFT JOIN appraisal_cycle_settings acs ON acs.cycle_id = ap.cycle_id AND acs.company_id = ap.company_id
        LEFT JOIN users sup ON sup.id = ap.supervisor_id
        WHERE ap.id = {$id}
        LIMIT 1
    ");
    if (!$appraisal) throw new Exception('Appraisal not found.', 404);

    appraisalAssertViewAuthorized($conn, $userData, $appraisal);
    $eligibility=apCanSupervisorUpdateAppraisal($conn,$userData,null,$appraisal);
    $appraisal['can_update_appraisal']=$eligibility['allowed'] ? 1 : 0;
    $appraisal['can_adjust_objectives']=$eligibility['allowed'] ? 1 : 0;
    $appraisal['updates_left']=$eligibility['updates_left'];
    $appraisal['reason_key']=$eligibility['reason_key'];
    $appraisal['objectives_changed_since_submission']=!empty($appraisal['objectives_changed_at']) ? 1 : 0;

    $scores = apFetchAll($conn, "
        SELECT sc.section_id, sc.section_code, sc.section_label, sc.section_weight,
               sc.section_avg, sc.weighted_score, sc.rating_mode, sc.overall_rating, sc.overall_rating_comment, sc.question_weighting_mode,
               s.type AS section_type, s.sort_order
        FROM appraisal_section_scores sc
        LEFT JOIN appraisal_sections s ON s.id = sc.section_id
        WHERE sc.appraisal_id = {$id}
        ORDER BY COALESCE(s.sort_order, 999), sc.section_code ASC
    ");

    $general = apFetchAll($conn, "
        SELECT r.id AS response_id, r.section_id, s.code AS section_code, s.label AS section_label, s.type AS section_type,
               r.general_question_id AS question_id, r.question_text, r.rating, r.rating_comment
        FROM appraisal_section_responses r
        LEFT JOIN appraisal_sections s ON s.id = r.section_id
        WHERE r.appraisal_id = {$id}
        ORDER BY COALESCE(s.sort_order, 999), r.id ASC
    ");

    $kpi = apFetchAll($conn, "
        SELECT r.id AS response_id, r.section_id, s.code AS section_code, s.label AS section_label, s.type AS section_type,
               r.kpi_question_id AS question_id, r.question_text, r.objective_target, r.evidence_source, r.actual_result, r.question_weight, r.rating, r.rating_comment
        FROM appraisal_kpi_responses r
        LEFT JOIN appraisal_sections s ON s.id = r.section_id
        WHERE r.appraisal_id = {$id}
        ORDER BY COALESCE(s.sort_order, 999), r.id ASC
    ");

    $grouped = [];

    // Always initialise sections from saved section scores. This keeps historical
    // appraisals visible even when the old system did not store question responses.
    foreach ($scores as $score) {
        $sid = (int)$score['section_id'];
        $grouped[$sid] = [
            'section_id' => $sid,
            'section_code' => $score['section_code'],
            'section_label' => $score['section_label'],
            'section_type' => $score['section_type'] ?? 'general',
            'rating_mode' => $score['rating_mode'] ?? 'historical_summary',
            'overall_rating' => $score['overall_rating'] ?? $score['section_avg'],
            'overall_rating_comment' => $score['overall_rating_comment'] ?? null,
            'question_weighting_mode' => ($score['question_weighting_mode'] ?? 'equal') === 'custom' ? 'custom' : 'equal',
            'section_avg' => $score['section_avg'],
            'responses' => [],
            'is_historical_reference' => false,
        ];
    }

    foreach (array_merge($kpi, $general) as $row) {
        $sid = (int)$row['section_id'];

        if (!isset($grouped[$sid])) {
            $grouped[$sid] = [
                'section_id' => $sid,
                'section_code' => $row['section_code'],
                'section_label' => $row['section_label'],
                'section_type' => $row['section_type'] ?? 'general',
                'rating_mode' => 'per_question',
                'overall_rating' => null,
                'overall_rating_comment' => null,
                'question_weighting_mode' => 'equal',
                'section_avg' => null,
                'responses' => [],
                'is_historical_reference' => false,
            ];
        }

        $grouped[$sid]['responses'][] = [
            'question_id' => (int)$row['question_id'],
            'response_id' => (int)($row['response_id'] ?? 0),
            'response_table' => array_key_exists('kpi_question_id', $row) ? 'kpi' : 'general',
            'question_text' => $row['question_text'],
            'question_weight' => $row['question_weight'] ?? null,
            'objective_target' => $row['objective_target'] ?? null,
            'evidence_source' => $row['evidence_source'] ?? null,
            'actual_result' => $row['actual_result'] ?? null,
            'rating' => $row['rating'],
            'rating_comment' => $row['rating_comment'] ?? null,
            'is_reference' => false,
        ];
    }

    /*
     * Imported legacy appraisals carry accurate section scores, but the old
     * source did not retain dependable question-by-question ratings. Where
     * response snapshots are absent, show the questions configured for that
     * historical cycle as reference information and keep the saved section
     * score as the authoritative rating.
     */
    foreach ($grouped as $sid => &$sectionGroup) {
        if (!empty($sectionGroup['responses'])) {
            continue;
        }

        $sectionGroup['is_historical_reference'] = true;
        if ($sectionGroup['rating_mode'] === 'per_question') {
            $sectionGroup['rating_mode'] = 'historical_summary';
        }

        if (($sectionGroup['section_type'] ?? '') === 'kpi') {
            $snapshotLines = apHistoricalKpiSnapshotLines(
                $appraisal['kpi_questions_snapshot'] ?? ''
            );

            if (!empty($snapshotLines)) {
                foreach ($snapshotLines as $index => $questionText) {
                    $sectionGroup['responses'][] = [
                        'question_id' => 0,
                        'question_text' => $questionText,
                        'rating' => null,
                        'is_reference' => true,
                        'reference_source' => 'historical_snapshot',
                        'sort_order' => $index + 1,
                    ];
                }
            } else {
                $referenceQuestions = apHistoricalKpiReferenceQuestions(
                    $conn,
                    (int) $appraisal['company_id'],
                    (int) $sid,
                    (int) $appraisal['staff_user_id'],
                    (int) $appraisal['supervisor_id'],
                    (string) ($appraisal['staff_department'] ?? '')
                );

                foreach ($referenceQuestions as $question) {
                    $sectionGroup['responses'][] = [
                        'question_id' => (int) $question['id'],
                        'question_text' => $question['question_text'],
                        'rating' => null,
                        'is_reference' => true,
                        'reference_source' => 'configured_question_bank',
                    ];
                }
            }
        } else {
            $referenceQuestions = apFetchAll($conn, "
                SELECT id, question_text
                FROM general_questions
                WHERE section_id = {$sid}
                  AND company_id = " . (int)$appraisal['company_id'] . "
                ORDER BY sort_order ASC, id ASC
            ");

            foreach ($referenceQuestions as $question) {
                $sectionGroup['responses'][] = [
                    'question_id' => (int)$question['id'],
                    'question_text' => $question['question_text'],
                    'rating' => null,
                    'is_reference' => true,
                ];
            }
        }
    }
    unset($sectionGroup);

    if (in_array($loggedInRoleKey, ['staff', 'supervisor'], true)) {
        foreach ($scores as &$score) {
            unset($score['weighted_score']);
            if ($loggedInRoleKey === 'staff') {
                unset($score['section_weight']);
            }
        }
        unset($score);
    }

    $appraisal['raw_appraisal_summary'] = $appraisal['raw_appraisal_summary'] === null
        ? $appraisal['appraisal_summary'] : $appraisal['raw_appraisal_summary'];
    $canReadVerificationNotes = (in_array($loggedInRoleKey, ['admin', 'super_admin'], true)
        && (int)$appraisal['staff_user_id'] !== $loggedInUserId)
        || ((int)$appraisal['supervisor_id'] === $loggedInUserId
            && (int)$appraisal['staff_user_id'] !== $loggedInUserId);
    if (!$canReadVerificationNotes) {
        unset($appraisal['serious_breach_review_note'], $appraisal['serious_breach_reviewed_by']);
    }
    $appraisal['breach_verification_history'] = $canReadVerificationNotes ? apFetchAll($conn, "
        SELECT v.id, v.appraisal_revision_no, v.decision, v.note, v.raw_score, v.final_score, v.created_at,
               TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS reviewer_name
        FROM appraisal_breach_verifications v
        LEFT JOIN users u ON u.id = v.reviewed_by
        WHERE v.appraisal_id = {$id}
        ORDER BY v.id DESC
    ") : [];
    $latestSavedRevision = apFetchOne($conn, "SELECT MAX(revision_no) AS revision_no FROM appraisal_revisions WHERE appraisal_id = {$id}");
    $appraisal['current_revision_no'] = max(1, (int)($latestSavedRevision['revision_no'] ?? 0));
    $appraisal['section_scores'] = $scores;
    $appraisal['general_responses'] = $general;
    $appraisal['kpi_responses'] = $kpi;
    $appraisal['sections'] = array_values($grouped);
    $savedRatingConfig = json_decode((string)($appraisal['rating_config_snapshot'] ?? ''), true);
    $appraisal['rating_config'] = is_array($savedRatingConfig) && isset($savedRatingConfig['rating_min'], $savedRatingConfig['rating_max'], $savedRatingConfig['rating_step'])
        ? $savedRatingConfig
        : apCycleRatingConfig($conn, (int)$appraisal['company_id'], (int)$appraisal['cycle_id']);

    $canSeeManagementOversight = (int)($appraisal['management_review_enabled'] ?? 0) === 1 && (
        (
            in_array($loggedInRoleKey, ['super_admin', 'admin'], true)
            && (int)$appraisal['staff_user_id'] !== $loggedInUserId
        ) || (
            (int)($appraisal['can_manage_appraisal'] ?? 0) === 1
            && (int)$appraisal['staff_user_id'] !== $loggedInUserId
        )
    );

    if ($canSeeManagementOversight) {
        $revisionHistory = apRevisionHistory($conn, $id);
        if ($loggedInRoleKey === 'supervisor') {
            foreach ($revisionHistory as &$revisionRow) {
                foreach (($revisionRow['section_scores'] ?? []) as &$revisionScore) {
                    unset($revisionScore['weighted_score'], $revisionScore['section_weight']);
                }
                unset($revisionScore);
            }
            unset($revisionRow);
        }
        $currentRevisionNo = !empty($revisionHistory)
            ? (int)$revisionHistory[0]['revision_no']
            : max(((int)($appraisal['update_count'] ?? 0)) + 1, 1);
        $managementReview = apManagementReviewHistory($conn, $id, $currentRevisionNo);
        $appraisal['revision_history'] = $revisionHistory;
        $appraisal['current_revision_no'] = $currentRevisionNo;
        $appraisal['management_review_status'] = $managementReview['status'];
        $appraisal['management_review'] = $managementReview['current'];
        $appraisal['management_review_history'] = $managementReview['history'];
        $appraisal['management_open_follow_up'] = $managementReview['open_follow_up'] ?? null;
    }

    // Translation occurs only after role, company, staff-scope and privacy checks.
    // This is one batched approved-memory lookup for all frozen nested content.
    contentTranslationApplyPaths($conn, $appraisal, $userData, contentTranslationAppraisalDetailPaths());
    contentTranslationAddJobTitleDisplays($conn, $appraisal, $userData);

    http_response_code(200);
    echo json_encode(['status' => 'Success', 'message' => 'Appraisal fetched successfully', 'data' => $appraisal]);

} catch (Exception $e) {
    $code = $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($code);
    echo json_encode(['status' => 'Failed', 'message' => $e->getMessage()]);
}
