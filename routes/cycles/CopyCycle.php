<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/CompanySetupWrite.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Bad Request: Only POST method is allowed.', 405);
    }

    $userData          = requireRoles(['super_admin', 'admin']);
    $setupContext = companySetupWriteContext($userData);
    $loggedInUserId    = (int) ($userData['id'] ?? 0);
    $loggedInUserEmail = trim((string) ($userData['email'] ?? 'System user'));
    $loggedInUserRole  = authRoleKey($userData['role'] ?? '');
    $loggedInCompanyId = (int) ($userData['company_id'] ?? 0);

    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) {
        throw new Exception('Invalid request format. Expected a JSON object.', 400);
    }

    foreach (['from_cycle_id', 'to_cycle_id'] as $field) {
        if (!isset($data[$field]) || !is_numeric($data[$field])) {
            throw new Exception("Field '{$field}' is required.", 400);
        }
    }

    $fromCycleId = (int) $data['from_cycle_id'];
    $toCycleId   = (int) $data['to_cycle_id'];

    $copySections         = array_key_exists('copy_sections', $data) ? (bool) $data['copy_sections'] : true;
    $copyKpiQuestions     = array_key_exists('copy_kpi_questions', $data) ? (bool) $data['copy_kpi_questions'] : true;
    $copyGeneralQuestions = array_key_exists('copy_general_questions', $data) ? (bool) $data['copy_general_questions'] : true;
    $copyScoringConfig    = array_key_exists('copy_scoring_config', $data) ? (bool) $data['copy_scoring_config'] : true;

    if ($fromCycleId <= 0 || $toCycleId <= 0) {
        throw new Exception('Please select valid source and target cycles.', 400);
    }

    if ($fromCycleId === $toCycleId) {
        throw new Exception('The source and target cycles cannot be the same.', 400);
    }

    $cycleStmt = $conn->prepare('
        SELECT id, company_id, year, title
        FROM appraisal_cycles
        WHERE id IN (?, ?)
        ORDER BY id ASC
    ');
    if (!$cycleStmt) {
        throw new Exception('Database error: ' . $conn->error, 500);
    }
    $cycleStmt->bind_param('ii', $fromCycleId, $toCycleId);
    $cycleStmt->execute();
    $cycleRows = $cycleStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $cycleStmt->close();

    $cyclesById = [];
    foreach ($cycleRows as $cycleRow) {
        $cyclesById[(int) $cycleRow['id']] = $cycleRow;
    }

    $fromCycle = $cyclesById[$fromCycleId] ?? null;
    $toCycle   = $cyclesById[$toCycleId] ?? null;

    if (!$fromCycle) {
        throw new Exception('Source cycle not found.', 404);
    }
    if (!$toCycle) {
        throw new Exception('Target cycle not found.', 404);
    }

    $fromCompanyId = (int) $fromCycle['company_id'];
    $toCompanyId   = (int) $toCycle['company_id'];

    companySetupAssertCompany($userData, $setupContext, $fromCompanyId);
    companySetupAssertCompany($userData, $setupContext, $toCompanyId);

    // Questions may contain company-specific departments and user references, so cross-company copies are unsafe.
    if ($fromCompanyId !== $toCompanyId) {
        throw new Exception('Cycle setup can only be copied between cycles belonging to the same company.', 409);
    }

    // Copying cycle setup mutates configuration and, for scoring profiles, replaces
    // target rows. Never allow that to invalidate appraisals or employee-level setup.
    $targetAppraisalResult = $conn->query("SELECT COUNT(*) AS total FROM appraisals WHERE cycle_id = {$toCycleId}");
    if (!$targetAppraisalResult) throw new Exception('Unable to inspect target cycle appraisals: ' . $conn->error, 500);
    $targetAppraisal = $targetAppraisalResult->fetch_assoc();
    if ((int)($targetAppraisal['total'] ?? 0) > 0) {
        throw new Exception('The target cycle already contains appraisals. Copy setup into a new/unused cycle instead.', 409);
    }
    $targetPlansResult = $conn->query("SELECT COUNT(*) AS total FROM staff_objective_plans WHERE cycle_id = {$toCycleId}");
    if (!$targetPlansResult) throw new Exception('Unable to inspect target objective plans: ' . $conn->error, 500);
    $targetPlans = $targetPlansResult->fetch_assoc();
    $targetProfilesResult = $conn->query("SELECT COUNT(*) AS total FROM appraisal_staff_scoring_profiles WHERE cycle_id = {$toCycleId}");
    if (!$targetProfilesResult) throw new Exception('Unable to inspect target scoring profiles: ' . $conn->error, 500);
    $targetProfiles = $targetProfilesResult->fetch_assoc();
    if ((int)($targetPlans['total'] ?? 0) > 0 || (int)($targetProfiles['total'] ?? 0) > 0) {
        throw new Exception('The target cycle already has employee objective/profile setup. Use a new/unused target cycle to avoid invalidating prepared appraisals.', 409);
    }

    $sectionsStmt = $conn->prepare('
        SELECT *
        FROM appraisal_sections
        WHERE cycle_id = ? AND company_id = ?
        ORDER BY sort_order ASC, id ASC
    ');
    if (!$sectionsStmt) {
        throw new Exception('Database error: ' . $conn->error, 500);
    }
    $sectionsStmt->bind_param('ii', $fromCycleId, $fromCompanyId);
    $sectionsStmt->execute();
    $sourceSections = $sectionsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $sectionsStmt->close();

    if (!$sourceSections) {
        throw new Exception('Source cycle has no sections to copy.', 400);
    }

    $conn->begin_transaction();

    try {
        $stats = [
            'sections_copied'           => 0,
            'sections_reused'           => 0,
            'sections_skipped'          => 0,
            'kpi_questions_copied'      => 0,
            'kpi_questions_skipped'     => 0,
            'general_questions_copied'  => 0,
            'general_questions_skipped' => 0,
            'scoring_config_copied'      => 0,
            'scoring_profiles_copied'    => 0,
            'objective_setup_configs_copied' => 0,
        ];

        $sectionIdMap = [];
        $generalQuestionIdMap = [];
        $profileIdMap = [];

        foreach ($sourceSections as $section) {
            $sourceSectionId = (int) $section['id'];
            $targetSectionId = 0;

            $existsStmt = $conn->prepare('
                SELECT id, type
                FROM appraisal_sections
                WHERE cycle_id = ? AND company_id = ? AND code = ?
                LIMIT 1
            ');
            if (!$existsStmt) {
                throw new Exception('Database error: ' . $conn->error, 500);
            }
            $existsStmt->bind_param('iis', $toCycleId, $toCompanyId, $section['code']);
            $existsStmt->execute();
            $existingSection = $existsStmt->get_result()->fetch_assoc();
            $existsStmt->close();

            if ($existingSection) {
                if ((string) $existingSection['type'] !== (string) $section['type']) {
                    throw new Exception(
                        'Target section ' . $section['code'] . ' has a different section type. Update or remove it before copying this setup.',
                        409
                    );
                }
                $targetSectionId = (int) $existingSection['id'];
                if ($copySections) {
                    $updateSectionStmt = $conn->prepare('UPDATE appraisal_sections SET label=?, description=?, weight=?, default_rating_mode=?, lock_rating_mode=?, sort_order=?, is_active=?, updated_at=CURRENT_TIMESTAMP WHERE id=?');
                    if (!$updateSectionStmt) throw new Exception('Database error: ' . $conn->error, 500);
                    $defaultMode = $section['default_rating_mode'] ?? 'overall';
                    $lockMode = (int)($section['lock_rating_mode'] ?? 0);
                    $updateSectionStmt->bind_param('ssdsiiii', $section['label'], $section['description'], $section['weight'], $defaultMode, $lockMode, $section['sort_order'], $section['is_active'], $targetSectionId);
                    if (!$updateSectionStmt->execute()) throw new Exception('Unable to update reused section ' . $section['code'] . ': ' . $updateSectionStmt->error, 500);
                    $updateSectionStmt->close();
                }
                $stats['sections_reused']++;
            } elseif ($copySections) {
                $insertSectionStmt = $conn->prepare('
                    INSERT INTO appraisal_sections
                        (company_id, cycle_id, code, label, description, type, weight, default_rating_mode, lock_rating_mode, sort_order, is_active, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                if (!$insertSectionStmt) {
                    throw new Exception('Database error: ' . $conn->error, 500);
                }
                $insertSectionStmt->bind_param(
                    'iissssdsiiii',
                    $toCompanyId,
                    $toCycleId,
                    $section['code'],
                    $section['label'],
                    $section['description'],
                    $section['type'],
                    $section['weight'],
                    $section['default_rating_mode'] ?? 'overall',
                    $section['lock_rating_mode'] ?? 0,
                    $section['sort_order'],
                    $section['is_active'],
                    $loggedInUserId
                );
                if (!$insertSectionStmt->execute()) {
                    throw new Exception('Unable to copy section ' . $section['code'] . ': ' . $insertSectionStmt->error, 500);
                }
                $targetSectionId = (int) $insertSectionStmt->insert_id;
                $insertSectionStmt->close();
                $stats['sections_copied']++;
            } else {
                $stats['sections_skipped']++;
                continue;
            }

            $sectionIdMap[$sourceSectionId] = $targetSectionId;

            $objectiveConfigStmt = $conn->prepare('SELECT enabled, min_objectives, max_objectives, require_weights, required_weight_total, require_target, require_evidence, require_actual_result, allow_individual_objectives, require_locked_plan, lock_questions_during_appraisal, force_per_question_ratings FROM appraisal_section_objective_settings WHERE section_id = ? LIMIT 1');
            if (!$objectiveConfigStmt) throw new Exception('Database error: ' . $conn->error, 500);
            $objectiveConfigStmt->bind_param('i', $sourceSectionId);
            $objectiveConfigStmt->execute();
            $objectiveConfig = $objectiveConfigStmt->get_result()->fetch_assoc();
            $objectiveConfigStmt->close();

            if ($objectiveConfig) {
                $copyObjectiveConfigStmt = $conn->prepare('INSERT INTO appraisal_section_objective_settings (section_id, company_id, cycle_id, enabled, min_objectives, max_objectives, require_weights, required_weight_total, require_target, require_evidence, require_actual_result, allow_individual_objectives, require_locked_plan, lock_questions_during_appraisal, force_per_question_ratings, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE company_id=VALUES(company_id), cycle_id=VALUES(cycle_id), enabled=VALUES(enabled), min_objectives=VALUES(min_objectives), max_objectives=VALUES(max_objectives), require_weights=VALUES(require_weights), required_weight_total=VALUES(required_weight_total), require_target=VALUES(require_target), require_evidence=VALUES(require_evidence), require_actual_result=VALUES(require_actual_result), allow_individual_objectives=VALUES(allow_individual_objectives), require_locked_plan=VALUES(require_locked_plan), lock_questions_during_appraisal=VALUES(lock_questions_during_appraisal), force_per_question_ratings=VALUES(force_per_question_ratings), updated_by=VALUES(updated_by)');
                if (!$copyObjectiveConfigStmt) throw new Exception('Database error: ' . $conn->error, 500);
                $copyObjectiveConfigStmt->bind_param(
                    'iiiiiiidiiiiiiii',
                    $targetSectionId, $toCompanyId, $toCycleId,
                    $objectiveConfig['enabled'], $objectiveConfig['min_objectives'], $objectiveConfig['max_objectives'],
                    $objectiveConfig['require_weights'], $objectiveConfig['required_weight_total'],
                    $objectiveConfig['require_target'], $objectiveConfig['require_evidence'], $objectiveConfig['require_actual_result'], $objectiveConfig['allow_individual_objectives'],
                    $objectiveConfig['require_locked_plan'], $objectiveConfig['lock_questions_during_appraisal'], $objectiveConfig['force_per_question_ratings'],
                    $loggedInUserId
                );
                if (!$copyObjectiveConfigStmt->execute()) throw new Exception('Unable to copy objective setup configuration: ' . $copyObjectiveConfigStmt->error, 500);
                $copyObjectiveConfigStmt->close();
                $stats['objective_setup_configs_copied']++;
            }

            // Copy into both newly created and already existing target sections.
            if ($copyKpiQuestions && $section['type'] === 'kpi') {
                $kpiStmt = $conn->prepare('
                    SELECT *
                    FROM kpi_questions
                    WHERE section_id = ?
                    ORDER BY sort_order ASC, id ASC
                ');
                if (!$kpiStmt) {
                    throw new Exception('Database error: ' . $conn->error, 500);
                }
                $kpiStmt->bind_param('i', $sourceSectionId);
                $kpiStmt->execute();
                $kpiQuestions = $kpiStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $kpiStmt->close();

                foreach ($kpiQuestions as $kq) {
                    $duplicateStmt = $conn->prepare('
                        SELECT id
                        FROM kpi_questions
                        WHERE company_id = ?
                          AND section_id = ?
                          AND department = ?
                          AND supervisor_id <=> ?
                          AND staff_user_id <=> ?
                          AND question_text = ?
                        LIMIT 1
                    ');
                    if (!$duplicateStmt) {
                        throw new Exception('Database error: ' . $conn->error, 500);
                    }
                    $duplicateStmt->bind_param(
                        'iisiis',
                        $toCompanyId,
                        $targetSectionId,
                        $kq['department'],
                        $kq['supervisor_id'],
                        $kq['staff_user_id'],
                        $kq['question_text']
                    );
                    $duplicateStmt->execute();
                    $duplicateRow = $duplicateStmt->get_result()->fetch_assoc();
                    $duplicateStmt->close();

                    if ($duplicateRow) {
                        $targetKpiId = (int)$duplicateRow['id'];
                        $updateKpiStmt = $conn->prepare('UPDATE kpi_questions SET objective_code=?, measure_target=?, evidence_source=?, review_status=?, review_note=?, weight_percent=?, sort_order=?, is_active=?, updated_by=? WHERE id=?');
                        if (!$updateKpiStmt) throw new Exception('Database error: ' . $conn->error, 500);
                        $updateKpiStmt->bind_param('sssssdiiii', $kq['objective_code'], $kq['measure_target'], $kq['evidence_source'], $kq['review_status'], $kq['review_note'], $kq['weight_percent'], $kq['sort_order'], $kq['is_active'], $loggedInUserId, $targetKpiId);
                        if (!$updateKpiStmt->execute()) throw new Exception('Unable to update existing KPI question metadata: ' . $updateKpiStmt->error, 500);
                        $updateKpiStmt->close();
                        $stats['kpi_questions_skipped']++;
                        continue;
                    }

                    $insertKpiStmt = $conn->prepare('
                        INSERT INTO kpi_questions
                            (company_id, section_id, department, supervisor_id, staff_user_id,
                             question_text, objective_code, measure_target, evidence_source, review_status, review_note,
                             weight_percent, sort_order, is_active, created_by, updated_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ');
                    if (!$insertKpiStmt) {
                        throw new Exception('Database error: ' . $conn->error, 500);
                    }
                    $insertKpiStmt->bind_param(
                        'iisiissssssdiiii',
                        $toCompanyId,
                        $targetSectionId,
                        $kq['department'],
                        $kq['supervisor_id'],
                        $kq['staff_user_id'],
                        $kq['question_text'],
                        $kq['objective_code'],
                        $kq['measure_target'],
                        $kq['evidence_source'],
                        $kq['review_status'],
                        $kq['review_note'],
                        $kq['weight_percent'],
                        $kq['sort_order'],
                        $kq['is_active'],
                        $loggedInUserId,
                        $loggedInUserId
                    );
                    if (!$insertKpiStmt->execute()) {
                        throw new Exception('Unable to copy KPI question: ' . $insertKpiStmt->error, 500);
                    }
                    $insertKpiStmt->close();
                    $stats['kpi_questions_copied']++;
                }
            }

            if ($copyGeneralQuestions && $section['type'] === 'general') {
                $generalStmt = $conn->prepare('
                    SELECT *
                    FROM general_questions
                    WHERE section_id = ?
                    ORDER BY sort_order ASC, id ASC
                ');
                if (!$generalStmt) {
                    throw new Exception('Database error: ' . $conn->error, 500);
                }
                $generalStmt->bind_param('i', $sourceSectionId);
                $generalStmt->execute();
                $generalQuestions = $generalStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $generalStmt->close();

                foreach ($generalQuestions as $gq) {
                    $duplicateStmt = $conn->prepare('
                        SELECT id
                        FROM general_questions
                        WHERE company_id = ?
                          AND section_id = ?
                          AND question_text = ?
                        LIMIT 1
                    ');
                    if (!$duplicateStmt) {
                        throw new Exception('Database error: ' . $conn->error, 500);
                    }
                    $duplicateStmt->bind_param('iis', $toCompanyId, $targetSectionId, $gq['question_text']);
                    $duplicateStmt->execute();
                    $duplicateRow = $duplicateStmt->get_result()->fetch_assoc();
                    $duplicateStmt->close();

                    if ($duplicateRow) {
                        $targetGeneralId = (int)$duplicateRow['id'];
                        $updateGeneralStmt = $conn->prepare('UPDATE general_questions SET question_code=?, question_label=?, anchor_low_value=?, anchor_low_text=?, anchor_mid_value=?, anchor_mid_text=?, anchor_high_value=?, anchor_high_text=?, sort_order=?, is_active=?, updated_by=? WHERE id=?');
                        if (!$updateGeneralStmt) throw new Exception('Database error: ' . $conn->error, 500);
                        $updateGeneralStmt->bind_param('ssdsdsdsiiii', $gq['question_code'], $gq['question_label'], $gq['anchor_low_value'], $gq['anchor_low_text'], $gq['anchor_mid_value'], $gq['anchor_mid_text'], $gq['anchor_high_value'], $gq['anchor_high_text'], $gq['sort_order'], $gq['is_active'], $loggedInUserId, $targetGeneralId);
                        if (!$updateGeneralStmt->execute()) throw new Exception('Unable to update existing general question framework metadata: ' . $updateGeneralStmt->error, 500);
                        $updateGeneralStmt->close();
                        $generalQuestionIdMap[(int)$gq['id']] = $targetGeneralId;
                        $stats['general_questions_skipped']++;
                        continue;
                    }

                    $insertGeneralStmt = $conn->prepare('
                        INSERT INTO general_questions
                            (company_id, section_id, question_text, question_code, question_label, anchor_low_value, anchor_low_text, anchor_mid_value, anchor_mid_text, anchor_high_value, anchor_high_text, sort_order, is_active, created_by, updated_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ');
                    if (!$insertGeneralStmt) {
                        throw new Exception('Database error: ' . $conn->error, 500);
                    }
                    $insertGeneralStmt->bind_param(
                        'iisssdsdsdsiiii',
                        $toCompanyId, $targetSectionId, $gq['question_text'], $gq['question_code'], $gq['question_label'],
                        $gq['anchor_low_value'], $gq['anchor_low_text'], $gq['anchor_mid_value'], $gq['anchor_mid_text'],
                        $gq['anchor_high_value'], $gq['anchor_high_text'], $gq['sort_order'], $gq['is_active'],
                        $loggedInUserId, $loggedInUserId
                    );
                    if (!$insertGeneralStmt->execute()) {
                        throw new Exception('Unable to copy general question: ' . $insertGeneralStmt->error, 500);
                    }
                    $generalQuestionIdMap[(int)$gq['id']] = (int)$insertGeneralStmt->insert_id;
                    $insertGeneralStmt->close();
                    $stats['general_questions_copied']++;
                }
            }
        }

        if ($copyScoringConfig) {
            $assignmentCheck = $conn->query("
                SELECT COUNT(*) AS total
                FROM appraisal_staff_scoring_profiles
                WHERE cycle_id = {$toCycleId}
            ");
            if (!$assignmentCheck) {
                throw new Exception('Unable to validate target scoring assignments: ' . $conn->error, 500);
            }
            $assignmentCount = (int)($assignmentCheck->fetch_assoc()['total'] ?? 0);
            if ($assignmentCount > 0) {
                throw new Exception('The target cycle already has staff scoring-profile assignments. Remove those assignments before replacing its scoring configuration.', 409);
            }

            $sourceSettings = $conn->query("SELECT rating_min, rating_max, rating_step, require_explicit_profile, management_review_enabled FROM appraisal_cycle_settings WHERE cycle_id = {$fromCycleId} AND company_id = {$fromCompanyId} LIMIT 1");
            if (!$sourceSettings) throw new Exception('Unable to read source cycle scoring settings: ' . $conn->error, 500);
            $settings = $sourceSettings->fetch_assoc();
            if ($settings) {
                $settingsStmt = $conn->prepare('INSERT INTO appraisal_cycle_settings (cycle_id, company_id, rating_min, rating_max, rating_step, require_explicit_profile, management_review_enabled, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE company_id=VALUES(company_id), rating_min=VALUES(rating_min), rating_max=VALUES(rating_max), rating_step=VALUES(rating_step), require_explicit_profile=VALUES(require_explicit_profile), management_review_enabled=VALUES(management_review_enabled), updated_by=VALUES(updated_by)');
                if (!$settingsStmt) throw new Exception('Database error: ' . $conn->error, 500);
                $settingsStmt->bind_param('iidddiiii', $toCycleId, $toCompanyId, $settings['rating_min'], $settings['rating_max'], $settings['rating_step'], $settings['require_explicit_profile'], $settings['management_review_enabled'], $loggedInUserId, $loggedInUserId);
                if (!$settingsStmt->execute()) throw new Exception('Unable to copy cycle rating settings: ' . $settingsStmt->error, 500);
                $settingsStmt->close();
            }

            if (!$conn->query("DELETE FROM appraisal_rating_bands WHERE cycle_id = {$toCycleId} AND company_id = {$toCompanyId}")) {
                throw new Exception('Unable to reset target rating bands: ' . $conn->error, 500);
            }
            $bandRows = $conn->query("SELECT label, min_score, max_score, sort_order, is_active FROM appraisal_rating_bands WHERE cycle_id = {$fromCycleId} AND company_id = {$fromCompanyId} ORDER BY sort_order ASC, id ASC");
            if (!$bandRows) throw new Exception('Unable to read source rating bands: ' . $conn->error, 500);
            $bandStmt = $conn->prepare('INSERT INTO appraisal_rating_bands (company_id, cycle_id, label, min_score, max_score, sort_order, is_active, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            if (!$bandStmt) throw new Exception('Database error: ' . $conn->error, 500);
            while ($band = $bandRows->fetch_assoc()) {
                $bandStmt->bind_param('iisddiiii', $toCompanyId, $toCycleId, $band['label'], $band['min_score'], $band['max_score'], $band['sort_order'], $band['is_active'], $loggedInUserId, $loggedInUserId);
                if (!$bandStmt->execute()) throw new Exception('Unable to copy rating band: ' . $bandStmt->error, 500);
            }
            $bandStmt->close();

            if (!$conn->query("DELETE FROM appraisal_rating_points WHERE cycle_id = {$toCycleId} AND company_id = {$toCompanyId}")) {
                throw new Exception('Unable to reset target rating points: ' . $conn->error, 500);
            }
            $pointRows = $conn->query("SELECT rating_value, label, definition, evidence_requirement, requires_comment, sort_order, is_active FROM appraisal_rating_points WHERE cycle_id = {$fromCycleId} AND company_id = {$fromCompanyId} ORDER BY sort_order ASC, id ASC");
            if (!$pointRows) throw new Exception('Unable to read source rating points: ' . $conn->error, 500);
            $pointStmt = $conn->prepare('INSERT INTO appraisal_rating_points (company_id, cycle_id, rating_value, label, definition, evidence_requirement, requires_comment, sort_order, is_active, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            if (!$pointStmt) throw new Exception('Database error: ' . $conn->error, 500);
            while ($point = $pointRows->fetch_assoc()) {
                $pointStmt->bind_param('iidsssiiiii', $toCompanyId, $toCycleId, $point['rating_value'], $point['label'], $point['definition'], $point['evidence_requirement'], $point['requires_comment'], $point['sort_order'], $point['is_active'], $loggedInUserId, $loggedInUserId);
                if (!$pointStmt->execute()) throw new Exception('Unable to copy rating point: ' . $pointStmt->error, 500);
            }
            $pointStmt->close();

            if (!$conn->query("DELETE FROM appraisal_scoring_profiles WHERE cycle_id = {$toCycleId} AND company_id = {$toCompanyId}")) {
                throw new Exception('Unable to reset target scoring profiles: ' . $conn->error, 500);
            }
            $profileRows = $conn->query("SELECT * FROM appraisal_scoring_profiles WHERE cycle_id = {$fromCycleId} AND company_id = {$fromCompanyId} ORDER BY sort_order ASC, id ASC");
            if (!$profileRows) throw new Exception('Unable to read source scoring profiles: ' . $conn->error, 500);
            while ($profile = $profileRows->fetch_assoc()) {
                $insertProfile = $conn->prepare('INSERT INTO appraisal_scoring_profiles (company_id, cycle_id, code, label, description, is_default, is_active, sort_order, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                if (!$insertProfile) throw new Exception('Database error: ' . $conn->error, 500);
                $insertProfile->bind_param('iisssiiiii', $toCompanyId, $toCycleId, $profile['code'], $profile['label'], $profile['description'], $profile['is_default'], $profile['is_active'], $profile['sort_order'], $loggedInUserId, $loggedInUserId);
                if (!$insertProfile->execute()) throw new Exception('Unable to copy scoring profile: ' . $insertProfile->error, 500);
                $targetProfileId = (int)$insertProfile->insert_id;
                $sourceProfileId = (int)$profile['id'];
                $profileIdMap[$sourceProfileId] = $targetProfileId;
                $insertProfile->close();

                $weightRows = $conn->query("SELECT section_id, weight FROM appraisal_profile_section_weights WHERE profile_id = {$sourceProfileId}");
                if (!$weightRows) throw new Exception('Unable to read scoring profile weights: ' . $conn->error, 500);
                $weightStmt = $conn->prepare('INSERT INTO appraisal_profile_section_weights (profile_id, section_id, weight) VALUES (?, ?, ?)');
                if (!$weightStmt) throw new Exception('Database error: ' . $conn->error, 500);
                while ($profileWeight = $weightRows->fetch_assoc()) {
                    $sourceSectionForWeight = (int)$profileWeight['section_id'];
                    if (!isset($sectionIdMap[$sourceSectionForWeight])) {
                        throw new Exception('Scoring profile section weights could not be mapped. Enable Copy Sections when copying scoring configuration.', 400);
                    }
                    $targetSectionForWeight = (int)$sectionIdMap[$sourceSectionForWeight];
                    $weightStmt->bind_param('iid', $targetProfileId, $targetSectionForWeight, $profileWeight['weight']);
                    if (!$weightStmt->execute()) throw new Exception('Unable to copy scoring profile section weight: ' . $weightStmt->error, 500);
                }
                $weightStmt->close();
                $stats['scoring_profiles_copied']++;
            }
            if ($copyGeneralQuestions && $generalQuestionIdMap && $profileIdMap) {
                $sourceQuestionIds = implode(',', array_map('intval', array_keys($generalQuestionIdMap)));
                $applicabilityRows = $conn->query("SELECT general_question_id, profile_id FROM general_question_scoring_profiles WHERE general_question_id IN ({$sourceQuestionIds})");
                if (!$applicabilityRows) throw new Exception('Unable to read question profile applicability: ' . $conn->error, 500);
                $applicabilityStmt = $conn->prepare('INSERT IGNORE INTO general_question_scoring_profiles (general_question_id, profile_id) VALUES (?, ?)');
                if (!$applicabilityStmt) throw new Exception('Database error: ' . $conn->error, 500);
                while ($rule = $applicabilityRows->fetch_assoc()) {
                    $targetQuestionId = $generalQuestionIdMap[(int)$rule['general_question_id']] ?? 0;
                    $targetProfileId = $profileIdMap[(int)$rule['profile_id']] ?? 0;
                    if (!$targetQuestionId || !$targetProfileId) continue;
                    $applicabilityStmt->bind_param('ii', $targetQuestionId, $targetProfileId);
                    if (!$applicabilityStmt->execute()) throw new Exception('Unable to copy question profile applicability: ' . $applicabilityStmt->error, 500);
                }
                $applicabilityStmt->close();
            }

            $stats['scoring_config_copied'] = 1;
        }

        $logStmt = $conn->prepare('
            INSERT INTO audit_log
                (company_id, user_id, action, target_table, target_id, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        if ($logStmt && !companyUserWriteIsGranted($setupContext)) {
            $action = 'copy_cycle';
            $targetTable = 'appraisal_cycles';
            $description = sprintf(
                '%s copied cycle setup from %s (%d) to %s (%d). Sections: %d copied, %d reused, %d skipped. KPI questions: %d copied, %d already present. General questions: %d copied, %d already present. Scoring configuration copied: %s; profiles: %d. Objective setup configs: %d.',
                $loggedInUserEmail !== '' ? $loggedInUserEmail : 'System user',
                (string) $fromCycle['title'],
                (int) $fromCycle['year'],
                (string) $toCycle['title'],
                (int) $toCycle['year'],
                $stats['sections_copied'],
                $stats['sections_reused'],
                $stats['sections_skipped'],
                $stats['kpi_questions_copied'],
                $stats['kpi_questions_skipped'],
                $stats['general_questions_copied'],
                $stats['general_questions_skipped'],
                $stats['scoring_config_copied'] ? 'yes' : 'no',
                $stats['scoring_profiles_copied'],
                $stats['objective_setup_configs_copied']
            );
            $logStmt->bind_param(
                'iissis',
                $toCompanyId,
                $loggedInUserId,
                $action,
                $targetTable,
                $toCycleId,
                $description
            );
            $logStmt->execute();
            $logStmt->close();
        } elseif ($logStmt) {
            $logStmt->close();
        }

        companySetupAuditGranted($conn, $userData, $setupContext, $toCompanyId,
            'copy_cycle', 'appraisal_cycles', $toCycleId,
            "copied cycle configuration from {$fromCycleId} to {$toCycleId}");

        $conn->commit();

        http_response_code(200);
        echo json_encode([
            'status' => 'Success',
            'message' => 'Cycle setup copied successfully.',
            'data' => [
                'from_cycle' => ['id' => $fromCycleId, 'year' => $fromCycle['year']],
                'to_cycle' => ['id' => $toCycleId, 'year' => $toCycle['year']],
                'stats' => $stats,
            ],
        ]);
    } catch (Throwable $transactionError) {
        $conn->rollback();
        throw $transactionError;
    }
} catch (Throwable $e) {
    error_log('CopyCycle Error: ' . $e->getMessage());
    $code = (int) $e->getCode();
    $code = ($code >= 400 && $code <= 599) ? $code : 500;
    http_response_code($code);
    echo json_encode([
        'status' => 'Failed',
        'message' => $e->getMessage(),
    ]);
}
