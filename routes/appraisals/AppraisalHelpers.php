<?php
require_once __DIR__ . '/../../includes/ApiException.php';
require_once __DIR__ . '/../../includes/AppraisalObjectiveChanges.php';

function apEsc($conn, $value) { return $conn->real_escape_string(trim((string)$value)); }
function apFetchOne($conn, $sql) { $r = $conn->query($sql); if (!$r) throw new Exception('Database error: '.$conn->error, 500); return $r->fetch_assoc(); }
function apFetchAll($conn, $sql) { $r = $conn->query($sql); if (!$r) throw new Exception('Database error: '.$conn->error, 500); $rows=[]; while($row=$r->fetch_assoc()) $rows[]=$row; return $rows; }
function apRoleKeySql($alias) { return "LOWER(REPLACE(TRIM({$alias}.name), ' ', '_'))"; }
function apFullName($row) { return trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: ($row['fullname'] ?? ''); }

function apCycleRatingConfig($conn, int $companyId, int $cycleId): array {
    $settings = apFetchOne($conn, "
        SELECT rating_min, rating_max, rating_step, require_explicit_profile
        FROM appraisal_cycle_settings
        WHERE cycle_id = {$cycleId} AND company_id = {$companyId}
        LIMIT 1
    ");

    $config = [
        'rating_min' => $settings ? (float)$settings['rating_min'] : 1.0,
        'rating_max' => $settings ? (float)$settings['rating_max'] : 5.0,
        'rating_step' => $settings ? (float)$settings['rating_step'] : 0.1,
        'require_explicit_profile' => $settings ? (int)($settings['require_explicit_profile'] ?? 0) : 0,
        'rating_bands' => [],
        'rating_points' => [],
    ];

    $bands = apFetchAll($conn, "
        SELECT label, min_score, max_score, sort_order
        FROM appraisal_rating_bands
        WHERE cycle_id = {$cycleId}
          AND company_id = {$companyId}
          AND is_active = 1
        ORDER BY sort_order ASC, min_score ASC, id ASC
    ");

    if (!$bands) {
        $bands = [
            ['label' => 'Requires Development', 'min_score' => 1.00, 'max_score' => 1.09, 'sort_order' => 1],
            ['label' => 'Marginally Meets Requirements', 'min_score' => 1.10, 'max_score' => 2.09, 'sort_order' => 2],
            ['label' => 'Completely Meet Requirements', 'min_score' => 2.10, 'max_score' => 3.09, 'sort_order' => 3],
            ['label' => 'Consistently Exceeds Requirements', 'min_score' => 3.10, 'max_score' => 4.59, 'sort_order' => 4],
            ['label' => 'Significant / Outstanding', 'min_score' => 4.60, 'max_score' => 5.00, 'sort_order' => 5],
        ];
    }

    $config['rating_bands'] = array_map(function ($band) {
        return [
            'label' => (string)$band['label'],
            'min_score' => (float)$band['min_score'],
            'max_score' => (float)$band['max_score'],
            'sort_order' => (int)($band['sort_order'] ?? 0),
        ];
    }, $bands);

    $points = apFetchAll($conn, "
        SELECT rating_value, label, definition, evidence_requirement, requires_comment, sort_order
        FROM appraisal_rating_points
        WHERE cycle_id = {$cycleId}
          AND company_id = {$companyId}
          AND is_active = 1
        ORDER BY sort_order ASC, rating_value ASC, id ASC
    ");
    $config['rating_points'] = array_map(function ($point) {
        return [
            'rating_value' => (float)$point['rating_value'],
            'label' => (string)$point['label'],
            'definition' => (string)($point['definition'] ?? ''),
            'evidence_requirement' => (string)($point['evidence_requirement'] ?? ''),
            'requires_comment' => (int)($point['requires_comment'] ?? 0),
            'sort_order' => (int)($point['sort_order'] ?? 0),
        ];
    }, $points);

    return $config;
}

function apValidateRatingValue(float $rating, array $config, string $fieldLabel = 'Rating'): void {
    $min = (float)$config['rating_min'];
    $max = (float)$config['rating_max'];
    $step = max((float)$config['rating_step'], 0.0001);

    if ($rating < $min - 0.0001 || $rating > $max + 0.0001) {
        throw new Exception("{$fieldLabel} must be between {$min} and {$max}.", 400);
    }

    $activePoints = array_values(array_filter(
        is_array($config['rating_points'] ?? null) ? $config['rating_points'] : [],
        fn($point) => (int)($point['is_active'] ?? 1) === 1
    ));
    if ($activePoints) {
        foreach ($activePoints as $point) {
            if (abs(((float)($point['rating_value'] ?? 0)) - $rating) <= 0.0001) return;
        }
        $allowed = implode(', ', array_map(
            fn($point) => rtrim(rtrim(number_format((float)$point['rating_value'], 2, '.', ''), '0'), '.'),
            $activePoints
        ));
        throw new Exception("{$fieldLabel} must be one of this cycle's configured rating values: {$allowed}.", 400);
    }

    $steps = ($rating - $min) / $step;
    if (abs($steps - round($steps)) > 0.001) {
        throw new Exception("{$fieldLabel} must follow this cycle's {$step} rating step.", 400);
    }
}

function apRatingPointFromConfig(array $config, $rating): ?array {
    $rating = (float)$rating;
    foreach (($config['rating_points'] ?? []) as $point) {
        if ((int)($point['is_active'] ?? 1) !== 1) continue;
        if (abs(((float)($point['rating_value'] ?? 0)) - $rating) <= 0.0001) return $point;
    }
    return null;
}

function apValidateRatingComment(array $config, $rating, $comment, string $fieldLabel = 'Rating'): ?string {
    $comment = trim((string)($comment ?? ''));
    $point = apRatingPointFromConfig($config, $rating);
    if ($point && !empty($point['requires_comment']) && $comment === '') {
        $label = trim((string)($point['label'] ?? ''));
        throw new Exception($fieldLabel . ($label !== '' ? " ({$label})" : '') . ' requires a written example/comment.', 400);
    }
    return $comment !== '' ? $comment : null;
}

/** Keep the unadjusted weighted score; cap only the published final score. */
function apScoreWithBreachCap(float $rawScore, bool $capActive): float {
    return round($capActive ? min($rawScore, 2.0) : $rawScore, 2);
}

/** A supervisor cannot lift an unverified or confirmed cap by changing Yes to No. */
function apBreachStateForSubmission(array $declaration, ?array $prior = null): array {
    $yes = (int)($declaration['serious_breach_declared'] ?? 0) === 1;
    $oldStatus = (string)($prior['serious_breach_review_status'] ?? 'not_required');
    $oldActive = (int)($prior['breach_cap_active'] ?? 0) === 1;
    if ($oldActive && $oldStatus === 'confirmed') return ['active' => true, 'status' => 'confirmed'];
    // Preserve an already dismissed declaration on routine rating edits. A newly
    // declared breach or changed evidence must return to management as pending.
    if ($yes && !$oldActive && $oldStatus === 'dismissed'
        && (int)($prior['serious_breach_declared'] ?? 0) === 1
        && trim((string)($prior['serious_breach_reason'] ?? '')) === trim((string)($declaration['serious_breach_reason'] ?? ''))
        && trim((string)($prior['serious_breach_reference'] ?? '')) === trim((string)($declaration['serious_breach_reference'] ?? ''))) {
        return ['active' => false, 'status' => 'dismissed'];
    }
    if ($yes || ($oldActive && $oldStatus === 'pending')) return ['active' => true, 'status' => 'pending'];
    return ['active' => false, 'status' => $oldStatus === 'dismissed' ? 'dismissed' : 'not_required'];
}

/**
 * A supervisor may not retract an open serious-breach declaration, even if the
 * final score remains capped. Otherwise the reason/reference disappear from
 * the current appraisal and management has no visible evidence to review.
 * Once management confirms a breach, the verified evidence is immutable until
 * management explicitly dismisses the decision.
 */
function apValidateBreachUpdateAgainstReview(array $declaration, array $prior): void {
    $status = (string)($prior['serious_breach_review_status'] ?? 'not_required');
    $capActive = (int)($prior['breach_cap_active'] ?? 0) === 1;
    if (!$capActive || !in_array($status, ['pending', 'confirmed'], true)) return;

    if ((int)($declaration['serious_breach_declared'] ?? 0) !== 1) {
        throw new Exception('Management must dismiss the existing serious-breach declaration before it can be changed to No.', 409);
    }
    if ($status === 'confirmed' && (
        trim((string)($declaration['serious_breach_reason'] ?? '')) !== trim((string)($prior['serious_breach_reason'] ?? ''))
        || trim((string)($declaration['serious_breach_reference'] ?? '')) !== trim((string)($prior['serious_breach_reference'] ?? ''))
    )) {
        throw new Exception('Management has confirmed this breach. Its reason and reference cannot be changed until management dismisses the decision.', 409);
    }
}

function apApplyBreachScore(array $scoreData, bool $capActive): array {
    $raw = (float)$scoreData['appraisal_summary'];
    $scoreData['raw_appraisal_summary'] = $raw;
    $scoreData['breach_cap_active'] = $capActive ? 1 : 0;
    $scoreData['appraisal_summary'] = apScoreWithBreachCap($raw, $capActive);
    $scoreData['evaluation_statement'] = apEvaluationStatementFromConfig($scoreData['rating_config'], $scoreData['appraisal_summary']);
    return $scoreData;
}

function apEvaluationStatementFromConfig(array $config, $score): string {
    $score = (float)$score;
    $bands = is_array($config['rating_bands'] ?? null) ? $config['rating_bands'] : [];

    foreach ($bands as $band) {
        if ($score >= ((float)$band['min_score'] - 0.0001) && $score <= ((float)$band['max_score'] + 0.0001)) {
            return (string)$band['label'];
        }
    }

    return $bands[count($bands) - 1]['label'] ?? 'Unrated';
}

function apEvaluationStatement($conn, int $companyId, int $cycleId, $score): string {
    return apEvaluationStatementFromConfig(apCycleRatingConfig($conn, $companyId, $cycleId), $score);
}

function apResolveScoringProfile($conn, int $companyId, int $cycleId, int $staffUserId, array $sectionRows): array {
    $profile = null;
    $explicitProfile = null;

    if ($staffUserId > 0) {
        $explicitProfile = apFetchOne($conn, "
            SELECT p.id, p.code, p.label
            FROM appraisal_staff_scoring_profiles a
            INNER JOIN appraisal_scoring_profiles p ON p.id = a.profile_id
            WHERE a.cycle_id = {$cycleId}
              AND a.staff_user_id = {$staffUserId}
              AND p.company_id = {$companyId}
              AND p.cycle_id = {$cycleId}
              AND p.is_active = 1
            LIMIT 1
        ");
        $profile = $explicitProfile;
    }

    $cycleSettings = apFetchOne($conn, "SELECT require_explicit_profile FROM appraisal_cycle_settings WHERE cycle_id={$cycleId} AND company_id={$companyId} LIMIT 1");
    if ((int)($cycleSettings['require_explicit_profile'] ?? 0) === 1 && !$explicitProfile) {
        throw new Exception('Select the employee scoring profile/level for this appraisal cycle before starting the appraisal.', 409);
    }

    if (!$profile) {
        $profile = apFetchOne($conn, "
            SELECT id, code, label
            FROM appraisal_scoring_profiles
            WHERE company_id = {$companyId}
              AND cycle_id = {$cycleId}
              AND is_active = 1
              AND is_default = 1
            ORDER BY sort_order ASC, id ASC
            LIMIT 1
        ");
    }

    $weights = [];
    if ($profile) {
        $profileId = (int)$profile['id'];
        $weightRows = apFetchAll($conn, "
            SELECT section_id, weight
            FROM appraisal_profile_section_weights
            WHERE profile_id = {$profileId}
        ");
        foreach ($weightRows as $row) $weights[(int)$row['section_id']] = (float)$row['weight'];
    }

    $total = 0.0;
    foreach ($sectionRows as $section) {
        $sectionId = (int)$section['id'];
        if ($profile && !array_key_exists($sectionId, $weights)) {
            throw new Exception("Scoring profile '{$profile['label']}' is missing a weight for section {$section['code']}.", 400);
        }
        $weight = $profile ? (float)$weights[$sectionId] : (float)$section['weight'];
        if ($weight < 0 || $weight > 100) {
            throw new Exception("Section {$section['code']} has an invalid scoring weight.", 400);
        }
        $weights[$sectionId] = $weight;
        $total += $weight;
    }

    if (abs($total - 100.0) > 0.01) {
        $profileLabel = $profile ? " for scoring profile '{$profile['label']}'" : '';
        throw new Exception('Active section weights' . $profileLabel . ' must total exactly 100% before an appraisal can be submitted. Current total: ' . number_format($total, 2) . '%.', 400);
    }

    return [
        'profile' => $profile ? [
            'id' => (int)$profile['id'],
            'code' => (string)$profile['code'],
            'label' => (string)$profile['label'],
        ] : null,
        'weights' => $weights,
    ];
}

function apDurationYears($dateOfJoining) {
    if (!$dateOfJoining) return null;
    try {
        $diff = (new DateTime($dateOfJoining))->diff(new DateTime());
        return round($diff->y + ($diff->m / 12), 2);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Validate the three mandatory recommendation notes on create and update.
 * Older update callers may omit an unchanged field, so retain its saved value.
 * An explicitly empty field is never accepted (including on update).
 */
function apRequireAppraisalRecommendations(array $data, array $existing = []): array {
    $fields = [
        'status_upgrade' => 'Status Upgrade',
        'salary_upgrade' => 'Salary Upgrade',
        'development' => 'Development',
    ];
    $result = [];

    foreach ($fields as $key => $label) {
        $value = array_key_exists($key, $data) ? $data[$key] : ($existing[$key] ?? null);
        if (!is_string($value) && !is_numeric($value)) {
            throw new Exception("{$label} recommendation is required.", 400);
        }
        $value = trim((string)$value);
        if ($value === '') {
            throw new Exception("{$label} recommendation is required. Enter No or None if not applicable.", 400);
        }
        $result[$key] = $value;
    }

    return $result;
}

function apNormalizeSections($data) {
    if (!isset($data['sections']) || !is_array($data['sections']) || count($data['sections']) === 0) {
        throw new Exception("Field 'sections' is required and must be a non-empty array.", 400);
    }
    return $data['sections'];
}

function apSaveResponsesAndScores($conn, $appraisalId, $companyId, $cycleId, array $sections, array $context = []) {
    $appraisalId = (int)$appraisalId;
    $companyId = (int)$companyId;
    $cycleId = (int)$cycleId;
    $staffUserId = (int)($context['staff_user_id'] ?? 0);
    $supervisorId = (int)($context['supervisor_id'] ?? 0);
    $loggedInUserId = (int)($context['logged_in_user_id'] ?? 0);
    $staffDepartment = apEsc($conn, $context['staff_department'] ?? '');
    $enforceObjectivePlan = !empty($context['enforce_objective_plan']);
    $isEdit = !empty($context['is_edit']);
    $preserveResponseSnapshots = !empty($context['preserve_response_snapshots']);
    $reRateObjectives = $isEdit && !empty($context['re_rate_objectives']);
    $sectionScopeSql = $isEdit
        ? " AND EXISTS (SELECT 1 FROM appraisal_section_scores saved_sc WHERE saved_sc.appraisal_id = {$appraisalId} AND saved_sc.section_id = s.id)"
        : " AND s.is_active = 1";

    $sectionRows = apFetchAll($conn, "
        SELECT s.id, s.code, s.label, s.type, s.weight, s.default_rating_mode, s.lock_rating_mode,
               COALESCE(os.enabled,0) AS objective_setup_enabled,
               COALESCE(os.require_weights,0) AS objective_require_weights,
               COALESCE(os.required_weight_total,100.00) AS objective_required_weight_total,
               COALESCE(os.require_actual_result,0) AS objective_require_actual_result,
               COALESCE(os.require_locked_plan,1) AS objective_require_locked_plan,
               COALESCE(os.lock_questions_during_appraisal,1) AS objective_lock_questions_during_appraisal,
               COALESCE(os.force_per_question_ratings,1) AS objective_force_per_question_ratings
        FROM appraisal_sections s
        LEFT JOIN appraisal_section_objective_settings os ON os.section_id = s.id
        WHERE s.company_id = {$companyId}
          AND s.cycle_id = {$cycleId}
          {$sectionScopeSql}
    ");

    $savedSectionModes = [];
    if ($isEdit) {
        foreach (apFetchAll($conn, "SELECT section_id, section_code, section_label, rating_mode, question_weighting_mode FROM appraisal_section_scores WHERE appraisal_id = {$appraisalId}") as $savedScore) {
            $sid = (int)$savedScore['section_id'];
            $savedSectionModes[$sid] = [
                'section_code' => (string)$savedScore['section_code'],
                'section_label' => (string)$savedScore['section_label'],
                'rating_mode' => (string)($savedScore['rating_mode'] ?? 'per_question'),
                'question_weighting_mode' => (string)($savedScore['question_weighting_mode'] ?? 'equal'),
            ];
        }
        foreach ($sectionRows as &$sectionRow) {
            $sid = (int)$sectionRow['id'];
            if (isset($savedSectionModes[$sid])) {
                $sectionRow['code'] = $savedSectionModes[$sid]['section_code'];
                $sectionRow['label'] = $savedSectionModes[$sid]['section_label'];
            }
        }
        unset($sectionRow);
    }

    $sectionMap = [];
    foreach ($sectionRows as $section) {
        $sectionMap[(int)$section['id']] = $section;
    }

    if (empty($sectionMap)) {
        throw new Exception('No active appraisal sections found for this cycle.', 400);
    }

    $ratingConfig = is_array($context['rating_config_override'] ?? null)
        ? $context['rating_config_override']
        : apCycleRatingConfig($conn, $companyId, $cycleId);

    $weightOverrides = is_array($context['section_weight_overrides'] ?? null)
        ? $context['section_weight_overrides']
        : null;

    if ($weightOverrides !== null) {
        $resolvedWeights = [];
        $resolvedTotal = 0.0;
        foreach ($sectionRows as $sectionRow) {
            $resolvedSectionId = (int)$sectionRow['id'];
            $rawWeight = $weightOverrides[$resolvedSectionId] ?? $weightOverrides[(string)$resolvedSectionId] ?? null;
            if ($rawWeight === null || !is_numeric($rawWeight)) {
                throw new Exception("The saved appraisal weight for section {$sectionRow['code']} could not be resolved.", 400);
            }
            $resolvedWeight = (float)$rawWeight;
            if ($resolvedWeight < 0 || $resolvedWeight > 100) {
                throw new Exception("The saved appraisal weight for section {$sectionRow['code']} is invalid.", 400);
            }
            $resolvedWeights[$resolvedSectionId] = $resolvedWeight;
            $resolvedTotal += $resolvedWeight;
        }
        if (abs($resolvedTotal - 100.0) > 0.01) {
            throw new Exception('Saved appraisal section weights must total exactly 100%.', 400);
        }
        $scoringContext = [
            'profile' => is_array($context['scoring_profile_override'] ?? null) ? $context['scoring_profile_override'] : null,
            'weights' => $resolvedWeights,
        ];
    } else {
        $scoringContext = apResolveScoringProfile($conn, $companyId, $cycleId, $staffUserId, $sectionRows);
    }

    foreach ($sectionMap as $resolvedSectionId => &$resolvedSection) {
        $resolvedSection['base_weight'] = (float)$resolvedSection['weight'];
        $resolvedSection['weight'] = (float)$scoringContext['weights'][$resolvedSectionId];
    }
    unset($resolvedSection);

    $payloadSectionIds = [];
    foreach ($sections as $sectionPayload) {
        $payloadSectionId = (int)($sectionPayload['section_id'] ?? 0);
        if ($payloadSectionId <= 0) throw new Exception('Each section must contain a valid section_id.', 400);
        if (isset($payloadSectionIds[$payloadSectionId])) throw new Exception("Section ID {$payloadSectionId} was submitted more than once.", 400);
        $payloadSectionIds[$payloadSectionId] = true;
    }
    $expectedSectionIds = array_keys($sectionMap);
    $submittedSectionIds = array_keys($payloadSectionIds);
    sort($expectedSectionIds);
    sort($submittedSectionIds);
    if ($submittedSectionIds !== $expectedSectionIds) {
        throw new Exception('The appraisal payload must contain every configured section exactly once.', 409);
    }

    $existingGeneralQuestionIds = [];
    $existingGeneralSnapshots = [];
    $existingKpiQuestionIds = [];
    $existingKpiSnapshots = [];
    if ($isEdit) {
        foreach (apFetchAll($conn, "SELECT section_id, general_question_id, question_text FROM appraisal_section_responses WHERE appraisal_id = {$appraisalId}") as $savedResponse) {
            $sid = (int)$savedResponse['section_id'];
            $qid = (int)$savedResponse['general_question_id'];
            $existingGeneralQuestionIds[$sid][] = $qid;
            $existingGeneralSnapshots[$sid][$qid] = $savedResponse;
        }
        foreach (apFetchAll($conn, "SELECT section_id, kpi_question_id, question_text, objective_target, evidence_source, question_weight FROM appraisal_kpi_responses WHERE appraisal_id = {$appraisalId}") as $savedResponse) {
            $sid = (int)$savedResponse['section_id'];
            $qid = (int)$savedResponse['kpi_question_id'];
            $existingKpiQuestionIds[$sid][] = $qid;
            $existingKpiSnapshots[$sid][$qid] = $savedResponse;
        }
        foreach ($existingGeneralQuestionIds as &$ids) sort($ids);
        unset($ids);
        foreach ($existingKpiQuestionIds as &$ids) sort($ids);
        unset($ids);
    }

    if (!$conn->query("DELETE FROM appraisal_section_scores WHERE appraisal_id = {$appraisalId}")
        || !$conn->query("DELETE FROM appraisal_section_responses WHERE appraisal_id = {$appraisalId}")
        || !$conn->query("DELETE FROM appraisal_kpi_responses WHERE appraisal_id = {$appraisalId}")) {
        throw new Exception('Unable to reset the appraisal response records: ' . $conn->error, 500);
    }

    $sectionScores = [];
    $summary = 0;
    $kpiRating = null;
    $kpiSnapshot = [];

    foreach ($sections as $sectionPayload) {
        if (!isset($sectionPayload['section_id'], $sectionPayload['responses']) || !is_array($sectionPayload['responses'])) {
            throw new Exception('Each section must contain section_id and responses.', 400);
        }

        $sectionId = (int)$sectionPayload['section_id'];
        if (!isset($sectionMap[$sectionId])) {
            throw new Exception("Section ID {$sectionId} is not valid for this cycle.", 400);
        }

        $section = $sectionMap[$sectionId];
        // Only KPI sections with an actual objective plan can use the re-rating
        // exception; legacy/no-plan sections keep their original snapshot IDs.
        $reRateThisSection = $reRateObjectives
            && $section['type'] === 'kpi'
            && (int)($section['objective_setup_enabled'] ?? 0) === 1
            && (bool) apFetchOne($conn, 'SELECT id FROM staff_objective_plans WHERE section_id=' . $sectionId . ' AND staff_user_id=' . $staffUserId . ' LIMIT 1');
        $responses = $sectionPayload['responses'];
        if (count($responses) === 0) {
            throw new Exception("Section {$section['code']} must contain at least one question.", 400);
        }

        if ($isEdit && $section['type'] === 'general') {
            $submittedGeneralIds = [];
            foreach ($responses as $candidateResponse) {
                $candidateId = (int)($candidateResponse['question_id'] ?? 0);
                if ($candidateId > 0) $submittedGeneralIds[] = $candidateId;
            }
            sort($submittedGeneralIds);
            $savedGeneralIds = $existingGeneralQuestionIds[$sectionId] ?? [];
            if ($submittedGeneralIds !== $savedGeneralIds) {
                throw new Exception("The questions for section {$section['code']} must match the original appraisal submission.", 409);
            }
        }
        if ($isEdit && $section['type'] === 'kpi' && !$reRateThisSection) {
            $submittedKpiIds = [];
            foreach ($responses as $candidateResponse) {
                $candidateId = (int)($candidateResponse['question_id'] ?? 0);
                if ($candidateId > 0) $submittedKpiIds[] = $candidateId;
            }
            sort($submittedKpiIds);
            $savedKpiIds = $existingKpiQuestionIds[$sectionId] ?? [];
            if ($submittedKpiIds !== $savedKpiIds) {
                throw new Exception("The objectives for section {$section['code']} must match the original appraisal submission.", 409);
            }
        }

        $savedMode = $savedSectionModes[$sectionId]['rating_mode'] ?? null;
        $ratingMode = $isEdit && $savedMode !== null
            ? ($savedMode === 'overall' ? 'overall' : 'per_question')
            : ((($sectionPayload['rating_mode'] ?? 'per_question') === 'overall') ? 'overall' : 'per_question');
        if (!$isEdit && (int)($section['lock_rating_mode'] ?? 0) === 1) {
            $requiredMode = (($section['default_rating_mode'] ?? 'overall') === 'per_question') ? 'per_question' : 'overall';
            if ($ratingMode !== $requiredMode) {
                throw new Exception("Section {$section['code']} must use the configured {$requiredMode} rating mode for this cycle.", 400);
            }
        }

        $objectiveAssignmentMap = [];
        $objectivePlanApplies = $section['type'] === 'kpi'
            && $enforceObjectivePlan
            && (int)($section['objective_setup_enabled'] ?? 0) === 1;
        $plan = null;
        if ($objectivePlanApplies) {
            $plan = apFetchOne($conn, "SELECT id, status FROM staff_objective_plans WHERE section_id = {$sectionId} AND staff_user_id = {$staffUserId} LIMIT 1");
            // Appraisals submitted before objective planning was enabled must remain editable
            // from their own snapshots; do not retroactively require a plan that did not exist.
            if ($isEdit && !$plan) {
                $objectivePlanApplies = false;
            }
        }

        if ($reRateThisSection && $objectivePlanApplies && (!$plan || ($plan['status'] ?? '') !== 'locked')) {
            throw new Exception('Lock the objective plan before updating the appraisal.', 409);
        }

        if ($objectivePlanApplies) {
            if ((int)($section['objective_force_per_question_ratings'] ?? 1) === 1 && $ratingMode !== 'per_question') {
                throw new Exception("Section {$section['code']} must be rated per objective for this cycle.", 400);
            }

            if ((int)($section['objective_require_locked_plan'] ?? 1) === 1 && (($plan['status'] ?? '') !== 'locked')) {
                throw new Exception("The objective plan for section {$section['code']} must be locked before appraisal submission.", 409);
            }

            $objectiveAssignments = apFetchAll($conn, "
                SELECT ska.kpi_question_id, COALESCE(ska.weight_percent,q.weight_percent) AS weight_percent,
                       COALESCE(ska.target_override,q.measure_target) AS objective_target,
                       COALESCE(ska.evidence_override,q.evidence_source) AS evidence_source,
                       q.question_text
                FROM staff_kpi_assignments ska
                INNER JOIN kpi_questions q ON q.id = ska.kpi_question_id
                WHERE ska.section_id = {$sectionId}
                  AND ska.staff_user_id = {$staffUserId}
                  AND q.company_id = {$companyId}
                  " . ($isEdit ? "" : "AND q.is_active = 1") . "
                ORDER BY ska.id ASC
            ");
            foreach ($objectiveAssignments as $assignmentRow) {
                $objectiveAssignmentMap[(int)$assignmentRow['kpi_question_id']] = $assignmentRow;
            }

            if ($reRateThisSection || (int)($section['objective_lock_questions_during_appraisal'] ?? 1) === 1) {
                $payloadIds = [];
                foreach ($responses as $candidateResponse) {
                    $candidateId = (int)($candidateResponse['question_id'] ?? 0);
                    if ($candidateId > 0) $payloadIds[] = $candidateId;
                }
                sort($payloadIds);
                $expectedIds = array_keys($objectiveAssignmentMap);
                sort($expectedIds);
                if ($payloadIds !== $expectedIds) {
                    throw new Exception("The KPI questions for section {$section['code']} must match the locked objective plan.", 409);
                }
            }
        }

        // Question-level weighting is intentionally separate from the existing
        // section weight. It only changes how a KPI section average is derived.
        // Existing/legacy requests omit this field and therefore remain equal-weighted.
        $questionWeightingMode = 'equal';
        if ($section['type'] === 'kpi' && $ratingMode === 'per_question') {
            if ($isEdit && isset($savedSectionModes[$sectionId])) {
                $questionWeightingMode = $savedSectionModes[$sectionId]['question_weighting_mode'] === 'custom' ? 'custom' : 'equal';
            } else {
                $requestedWeightingMode = strtolower(trim((string)($sectionPayload['question_weighting_mode'] ?? 'equal')));
                $questionWeightingMode = $requestedWeightingMode === 'custom' ? 'custom' : 'equal';
                if ($objectivePlanApplies && (int)($section['objective_require_weights'] ?? 0) === 1) {
                    $questionWeightingMode = 'custom';
                }
            }
        }

        $questionWeightDenominator = 100.0;
        if ($section['type'] === 'kpi' && $objectivePlanApplies && (int)($section['objective_require_weights'] ?? 0) === 1) {
            $questionWeightDenominator = max((float)($section['objective_required_weight_total'] ?? 100), 0.01);
        }

        $overallRating = null;
        $overallRatingComment = null;
        if ($ratingMode === 'overall') {
            if (!array_key_exists('overall_rating', $sectionPayload) || $sectionPayload['overall_rating'] === null || $sectionPayload['overall_rating'] === '') {
                throw new ApiException('appraisal.sectionRatingRequired', ['section' => (string)$section['code']], 400, "Overall rating for section {$section['code']} is required.");
            }
            $overallRating = (float)$sectionPayload['overall_rating'];
            apValidateRatingValue($overallRating, $ratingConfig, "Overall rating for section {$section['code']}");
            $overallRatingComment = apValidateRatingComment(
                $ratingConfig,
                $overallRating,
                $sectionPayload['overall_rating_comment'] ?? null,
                "Overall rating for section {$section['code']}"
            );
        }

        $ratings = [];
        $questionWeightTotal = 0.0;
        $weightedRatingTotal = 0.0;

        foreach ($responses as $response) {
            if (!isset($response['question_id'])) {
                throw new Exception('Each response must contain question_id.', 400);
            }

            $questionId = (int)$response['question_id'];
            $rating = null;

            if ($ratingMode === 'per_question') {
                if (!isset($response['rating']) || $response['rating'] === null || $response['rating'] === '') {
                    throw new ApiException('appraisal.questionRatingRequired', ['section' => (string)$section['code']], 400, "A rating is required for each question in section {$section['code']}.");
                }

                $rating = (float)$response['rating'];
                apValidateRatingValue($rating, $ratingConfig, "Rating for section {$section['code']}");
                $ratings[] = $rating;
            }

            $ratingComment = null;
            if ($ratingMode === 'per_question') {
                $ratingComment = apValidateRatingComment(
                    $ratingConfig,
                    $rating,
                    $response['rating_comment'] ?? $response['comment'] ?? null,
                    "Rating for section {$section['code']}"
                );
            }
            $ratingCommentSql = $ratingComment !== null ? "'" . apEsc($conn, $ratingComment) . "'" : 'NULL';
            $ratingSql = $rating === null ? 'NULL' : (string)(float)$rating;

            if ($section['type'] === 'kpi') {
                $incomingText = trim((string)($response['question_text'] ?? ''));
                $isCustom = !empty($response['is_custom']);
                $isEdited = !empty($response['is_edited']);
                $question = null;
                $savedKpiSnapshot = $isEdit ? ($existingKpiSnapshots[$sectionId][$questionId] ?? null) : null;

                if ($questionId > 0) {
                    $question = apFetchOne($conn, "
                        SELECT id, question_text, weight_percent, measure_target, evidence_source
                        FROM kpi_questions
                        WHERE id = {$questionId}
                          AND company_id = {$companyId}
                          AND section_id = {$sectionId}
                        LIMIT 1
                    ");
                }

                if ($incomingText === '' && $question) {
                    $incomingText = $question['question_text'];
                }
                if ($savedKpiSnapshot && !$reRateThisSection) {
                    $incomingText = (string)$savedKpiSnapshot['question_text'];
                    $isCustom = false;
                    $isEdited = false;
                }

                // Unchanged edits retain the previous snapshots; a post-submission
                // re-rating instead follows the authoritative locked plan.
                // Existing appraisal edits must retain the exact target/evidence
                // snapshots originally saved, even if the objective library changes later.
                $objectiveTarget = $savedKpiSnapshot
                    ? ($savedKpiSnapshot['objective_target'] ?? null)
                    : (array_key_exists('objective_target', $response)
                        ? ($response['objective_target'] !== null ? trim((string)$response['objective_target']) : null)
                        : ($question['measure_target'] ?? null));
                $objectiveEvidence = $savedKpiSnapshot
                    ? ($savedKpiSnapshot['evidence_source'] ?? null)
                    : (array_key_exists('evidence_source', $response)
                        ? ($response['evidence_source'] !== null ? trim((string)$response['evidence_source']) : null)
                        : ($question['evidence_source'] ?? null));
                $lockedAssignment = $objectiveAssignmentMap[$questionId] ?? null;
                if ($reRateThisSection && $objectivePlanApplies && !$lockedAssignment) {
                    throw new ApiException('objective.planMismatch', [], 409, 'Submitted objectives must match the locked objective plan.');
                }
                if ($lockedAssignment) {
                    if ($reRateThisSection && $objectivePlanApplies) {
                        // An old browser form must never silently rate a newer
                        // target/evidence/weight. Compare the submitted metadata
                        // before replacing it with the authoritative plan data.
                        $sameText = trim((string)($response['question_text'] ?? '')) === trim((string)$lockedAssignment['question_text']);
                        $sameTarget = trim((string)($response['objective_target'] ?? '')) === trim((string)($lockedAssignment['objective_target'] ?? ''));
                        $sameEvidence = trim((string)($response['evidence_source'] ?? '')) === trim((string)($lockedAssignment['evidence_source'] ?? ''));
                        $submittedWeight = $response['question_weight'] ?? $response['weight_percent'] ?? null;
                        $lockedWeight = $lockedAssignment['weight_percent'] ?? null;
                        $sameWeight = $questionWeightingMode !== 'custom'
                            || ($submittedWeight !== null && is_numeric($submittedWeight)
                                && $lockedWeight !== null && abs((float)$submittedWeight - (float)$lockedWeight) <= 0.001);
                        if (!$sameText || !$sameTarget || !$sameEvidence || !$sameWeight) {
                            throw new ApiException('objective.planStale', [], 409, 'The objective plan changed while this form was open. Reload the appraisal before updating.');
                        }

                        $incomingText = (string)$lockedAssignment['question_text'];
                        $objectiveTarget = $lockedAssignment['objective_target'] ?? null;
                        $objectiveEvidence = $lockedAssignment['evidence_source'] ?? null;
                        $isCustom = false;
                        $isEdited = false;
                    }
                    if ((int)($section['objective_lock_questions_during_appraisal'] ?? 1) === 1) {
                        if (!$preserveResponseSnapshots || $reRateThisSection) {
                            $incomingText = (string)$lockedAssignment['question_text'];
                        }
                        $isCustom = false;
                        $isEdited = false;
                    }
                    if (!$preserveResponseSnapshots || $reRateThisSection || !array_key_exists('objective_target', $response)) {
                        $objectiveTarget = $lockedAssignment['objective_target'] ?? $objectiveTarget;
                    }
                    if (!$preserveResponseSnapshots || $reRateThisSection || !array_key_exists('evidence_source', $response)) {
                        $objectiveEvidence = $lockedAssignment['evidence_source'] ?? $objectiveEvidence;
                    }
                }

                if ($incomingText === '') {
                    throw new Exception("KPI question text is required for section {$section['code']}.", 400);
                }

                // The final appraisal payload is authoritative for custom weights.
                // This guarantees that edits reopen and recalculate from the exact
                // per-staff weights chosen at appraisal time rather than from a later
                // change to a department/supervisor question template.
                $questionWeight = null;
                if ($questionWeightingMode === 'custom') {
                    $responseWeight = $response['question_weight'] ?? $response['weight_percent'] ?? null;
                    $savedWeight = $savedKpiSnapshot['question_weight'] ?? null;
                    $rawQuestionWeight = $reRateThisSection && $lockedAssignment
                        ? $lockedAssignment['weight_percent']
                        : (!$reRateThisSection && $savedKpiSnapshot && $savedWeight !== null && $savedWeight !== ''
                        ? $savedWeight
                        : ($preserveResponseSnapshots && !$reRateThisSection && $responseWeight !== null && $responseWeight !== ''
                            ? $responseWeight
                            : (($lockedAssignment && $lockedAssignment['weight_percent'] !== null)
                                ? $lockedAssignment['weight_percent']
                                : $responseWeight)));
                    if ($rawQuestionWeight === null || $rawQuestionWeight === '' || !is_numeric($rawQuestionWeight)) {
                        throw new Exception("A weight is required for every KPI question when custom weighting is enabled in section {$section['code']}.", 400);
                    }

                    $questionWeight = round((float)$rawQuestionWeight, 2);
                    if ($questionWeight <= 0 || $questionWeight > 100) {
                        throw new Exception('Each KPI question weight must be greater than 0 and no more than 100%.', 400);
                    }

                    $questionWeightTotal += $questionWeight;
                    $weightedRatingTotal += ((float)$rating) * ($questionWeight / $questionWeightDenominator);
                }

                if (!$reRateThisSection && !$savedKpiSnapshot && (!$question || $isCustom || $isEdited || trim((string)$question['question_text']) !== $incomingText)) {
                    $questionTextForInsert = apEsc($conn, $incomingText);
                    $sortOrder = isset($response['sort_order']) ? (int)$response['sort_order'] : 0;
                    $departmentSql = $staffDepartment !== '' ? "'{$staffDepartment}'" : "''";
                    $supervisorSql = $supervisorId > 0 ? (int)$supervisorId : 'NULL';
                    $staffSql = $staffUserId > 0 ? (int)$staffUserId : 'NULL';
                    $createdBySql = $loggedInUserId > 0 ? (int)$loggedInUserId : 'NULL';

                    // A KPI added during appraisal becomes an individual-staff question.
                    // If the supervisor is using custom weighting, retain that selected
                    // weight as the suggested starting point for a future appraisal of
                    // this staff member. Existing template questions are never overwritten.
                    $newQuestionWeightSql = $questionWeight === null ? 'NULL' : (string)(float)$questionWeight;
                    $insertQuestion = "INSERT INTO kpi_questions
                        (company_id, section_id, department, supervisor_id, staff_user_id, question_text, weight_percent, sort_order, is_active, created_by, updated_by)
                        VALUES ({$companyId}, {$sectionId}, {$departmentSql}, {$supervisorSql}, {$staffSql}, '{$questionTextForInsert}', {$newQuestionWeightSql}, {$sortOrder}, 1, {$createdBySql}, {$createdBySql})";
                    if (!$conn->query($insertQuestion)) {
                        throw new Exception('Unable to save the customised KPI question: ' . $conn->error, 500);
                    }
                    $questionId = (int)$conn->insert_id;
                }

                if ($questionId <= 0) {
                    throw new Exception("Unable to resolve KPI question for section {$section['code']}.", 400);
                }

                $questionText = apEsc($conn, $incomingText);
                $objectiveTargetSql = $objectiveTarget !== null && trim((string)$objectiveTarget) !== '' ? "'" . apEsc($conn, $objectiveTarget) . "'" : 'NULL';
                $objectiveEvidenceSql = $objectiveEvidence !== null && trim((string)$objectiveEvidence) !== '' ? "'" . apEsc($conn, $objectiveEvidence) . "'" : 'NULL';
                $actualResult = trim((string)($response['actual_result'] ?? ''));
                if ((!$isEdit || $objectivePlanApplies) && (int)($section['objective_require_actual_result'] ?? 0) === 1 && $actualResult === '') {
                    throw new Exception("Actual result is required for every objective in section {$section['code']}.", 400);
                }
                $actualResultSql = $actualResult !== '' ? "'" . apEsc($conn, $actualResult) . "'" : 'NULL';
                if ($questionWeightingMode === 'equal') {
                    // Save the locked-plan weight as metadata without changing the
                    // existing equal-weighted average. Keep it on later edits.
                    $questionWeight = $reRateThisSection && $lockedAssignment
                        ? ($lockedAssignment['weight_percent'] === null ? null : (float)$lockedAssignment['weight_percent'])
                        : ($savedKpiSnapshot['question_weight'] ?? null);
                }
                $questionWeightSql = $questionWeight === null ? 'NULL' : (string)(float)$questionWeight;
                if (!$conn->query("INSERT INTO appraisal_kpi_responses (appraisal_id, section_id, kpi_question_id, question_text, objective_target, evidence_source, actual_result, question_weight, rating, rating_comment) VALUES ({$appraisalId}, {$sectionId}, {$questionId}, '{$questionText}', {$objectiveTargetSql}, {$objectiveEvidenceSql}, {$actualResultSql}, {$questionWeightSql}, {$ratingSql}, {$ratingCommentSql})")) {
                    throw new Exception('Unable to save KPI appraisal response: ' . $conn->error, 500);
                }
                $kpiSnapshot[] = $incomingText;
            } else {
                if ($questionId <= 0) {
                    throw new Exception('Invalid question selected.', 400);
                }

                $question = apFetchOne($conn, "
                    SELECT id, question_text
                    FROM general_questions
                    WHERE id = {$questionId}
                      AND company_id = {$companyId}
                      AND section_id = {$sectionId}
                      " . ($isEdit ? "" : "AND is_active = 1") . "
                    LIMIT 1
                ");

                if (!$question) {
                    throw new Exception("General question ID {$questionId} was not found for section {$section['code']}.", 400);
                }

                $profileId = (int)($scoringContext['profile']['id'] ?? 0);
                if (!$isEdit && $profileId > 0) {
                    $ruleCount = apFetchOne($conn, "SELECT COUNT(*) AS total FROM general_question_scoring_profiles WHERE general_question_id={$questionId}");
                    if ((int)($ruleCount['total'] ?? 0) > 0) {
                        $allowed = apFetchOne($conn, "SELECT 1 AS ok FROM general_question_scoring_profiles WHERE general_question_id={$questionId} AND profile_id={$profileId} LIMIT 1");
                        if (!$allowed) throw new Exception("Question ID {$questionId} does not apply to this employee's scoring profile.", 400);
                    }
                }

                $savedGeneralSnapshot = $isEdit ? ($existingGeneralSnapshots[$sectionId][$questionId] ?? null) : null;
                $questionText = apEsc($conn, $savedGeneralSnapshot['question_text'] ?? $question['question_text']);
                if (!$conn->query("INSERT INTO appraisal_section_responses (appraisal_id, section_id, general_question_id, question_text, rating, rating_comment) VALUES ({$appraisalId}, {$sectionId}, {$questionId}, '{$questionText}', {$ratingSql}, {$ratingCommentSql})")) {
                    throw new Exception('Unable to save general appraisal response: ' . $conn->error, 500);
                }
            }
        }

        if ($section['type'] === 'kpi' && $ratingMode === 'per_question' && $questionWeightingMode === 'custom') {
            if (abs($questionWeightTotal - $questionWeightDenominator) > 0.01) {
                $formattedTotal = rtrim(rtrim(number_format($questionWeightTotal, 2, '.', ''), '0'), '.');
                $formattedRequired = rtrim(rtrim(number_format($questionWeightDenominator, 2, '.', ''), '0'), '.');
                throw new Exception("KPI question weights for section {$section['code']} must total exactly {$formattedRequired}%. Current total: {$formattedTotal}%.", 400);
            }
            $avg = $weightedRatingTotal;
        } else {
            $avg = $ratingMode === 'overall'
                ? $overallRating
                : (count($ratings) ? array_sum($ratings) / count($ratings) : 0);
        }

        // Existing section weighting is deliberately unchanged. The optional
        // question weights only derive the KPI section average above.
        $weighted = $avg * (((float)$section['weight']) / 100);
        $summary += $weighted;

        if ($section['type'] === 'kpi') {
            $kpiRating = round($avg, 2);
        }

        $scoreRow = [
            'section_id' => $sectionId,
            'section_code' => $section['code'],
            'section_label' => $section['label'],
            'section_weight' => (float)$section['weight'],
            'section_avg' => round($avg, 2),
            'weighted_score' => round($weighted, 4),
            'rating_mode' => $ratingMode,
            'overall_rating' => $ratingMode === 'overall' ? round($avg, 2) : null,
            'overall_rating_comment' => $ratingMode === 'overall' ? $overallRatingComment : null,
            'question_weighting_mode' => $questionWeightingMode,
        ];
        $sectionScores[] = $scoreRow;

        $code = apEsc($conn, $scoreRow['section_code']);
        $label = apEsc($conn, $scoreRow['section_label']);
        $weight = (float)$scoreRow['section_weight'];
        $sectionAvg = (float)$scoreRow['section_avg'];
        $weightedScore = (float)$scoreRow['weighted_score'];
        $mode = apEsc($conn, $scoreRow['rating_mode']);
        $overallSql = $scoreRow['overall_rating'] === null ? 'NULL' : (string)(float)$scoreRow['overall_rating'];
        $overallCommentSql = $scoreRow['overall_rating_comment'] === null ? 'NULL' : "'" . apEsc($conn, $scoreRow['overall_rating_comment']) . "'";
        $questionWeightingModeSql = apEsc($conn, $scoreRow['question_weighting_mode']);

        if (!$conn->query("INSERT INTO appraisal_section_scores
            (appraisal_id, section_id, section_code, section_label, section_weight, section_avg, weighted_score, rating_mode, overall_rating, overall_rating_comment, question_weighting_mode)
            VALUES ({$appraisalId}, {$sectionId}, '{$code}', '{$label}', {$weight}, {$sectionAvg}, {$weightedScore}, '{$mode}', {$overallSql}, {$overallCommentSql}, '{$questionWeightingModeSql}')")) {
            throw new Exception('Unable to save section score: ' . $conn->error, 500);
        }
    }

    $summary = round($summary, 2);

    return [
        'section_scores' => $sectionScores,
        'appraisal_summary' => $summary,
        'kpi_rating' => $kpiRating,
        'evaluation_statement' => apEvaluationStatementFromConfig($ratingConfig, $summary),
        'rating_config' => $ratingConfig,
        'scoring_profile' => $scoringContext['profile'],
        'kpi_questions_snapshot' => implode(', ', array_unique($kpiSnapshot)),
    ];
}


function apHistoricalKpiSnapshotLines($snapshot): array
{
    $snapshot = trim((string) $snapshot);

    if ($snapshot === '') {
        return [];
    }

    /*
     * Some legacy rows contain placeholders such as "\\\\", "????" or only
     * punctuation. They are not real KPI questions and must not be displayed.
     */
    $meaningful = preg_replace('/[^\pL\pN]+/u', '', $snapshot);
    if ($meaningful === null || strlen($meaningful) < 3) {
        return [];
    }

    $rawLines = preg_split('/\r\n|\r|\n/u', $snapshot) ?: [];
    $lines = [];

    foreach ($rawLines as $line) {
        $line = trim((string) $line);
        $lineMeaningful = preg_replace('/[^\pL\pN]+/u', '', $line);

        if ($line === '' || $lineMeaningful === null || strlen($lineMeaningful) < 3) {
            continue;
        }

        $lines[$line] = $line;
    }

    return array_values($lines);
}

function apHistoricalKpiReferenceQuestions(
    $conn,
    int $companyId,
    int $sectionId,
    int $staffUserId,
    int $supervisorId,
    string $department
): array {
    $department = apEsc($conn, $department);

    $rows = apFetchAll($conn, "
        SELECT DISTINCT
            q.id,
            q.question_text,
            q.sort_order,
            CASE
                WHEN q.staff_user_id = {$staffUserId} THEN 1
                WHEN ska.id IS NOT NULL THEN 2
                WHEN q.supervisor_id = {$supervisorId} THEN 3
                ELSE 4
            END AS scope_order
        FROM kpi_questions q
        LEFT JOIN staff_kpi_assignments ska
            ON ska.kpi_question_id = q.id
           AND ska.staff_user_id = {$staffUserId}
           AND ska.section_id = q.section_id
        WHERE q.company_id = {$companyId}
          AND q.section_id = {$sectionId}
          AND q.is_active = 1
          AND (
                q.staff_user_id = {$staffUserId}
                OR ska.id IS NOT NULL
                OR (q.supervisor_id = {$supervisorId} AND q.staff_user_id IS NULL)
                OR (
                    q.department = '{$department}'
                    AND q.supervisor_id IS NULL
                    AND q.staff_user_id IS NULL
                )
          )
        ORDER BY scope_order ASC, q.sort_order ASC, q.id ASC
    ");

    if (!empty($rows)) {
        return $rows;
    }

    /*
     * Assignment data from the old system may be incomplete. The department
     * baseline is the safest fallback because it remains scoped to the same
     * company, section and staff department.
     */
    return apFetchAll($conn, "
        SELECT q.id, q.question_text, q.sort_order, 4 AS scope_order
        FROM kpi_questions q
        WHERE q.company_id = {$companyId}
          AND q.section_id = {$sectionId}
          AND q.department = '{$department}'
          AND q.is_active = 1
        ORDER BY q.sort_order ASC, q.id ASC
    ");
}

/**
 * The appraisal declaration is distinct from scored questions. It cannot be
 * silently defaulted to No; existing historical records may legitimately be NULL.
 * Management confirmation is NOT required to submit an appraisal.
 */
function apRequireSeriousBreachDeclaration(array $data): array {
    if (!array_key_exists('serious_breach_declared', $data)) {
        throw new Exception('Select Yes or No for the serious HSE/integrity breach declaration.', 400);
    }
    $raw = $data['serious_breach_declared'];
    if ($raw === true || $raw === 1 || $raw === '1' || $raw === 'yes') {
        $declared = 1;
    } elseif ($raw === false || $raw === 0 || $raw === '0' || $raw === 'no') {
        $declared = 0;
    } else {
        throw new Exception('Serious HSE/integrity breach declaration must be Yes or No.', 400);
    }
    $reason = trim((string)($data['serious_breach_reason'] ?? ''));
    $reference = trim((string)($data['serious_breach_reference'] ?? ''));
    if ($declared === 1 && ($reason === '' || $reference === '')) {
        throw new Exception('A reason and supporting reference are required when a serious HSE/integrity breach is declared.', 400);
    }
    $unicodeLength = static function (string $value): int {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : (int)preg_match_all('/./us', $value);
    };
    if ($unicodeLength($reason) > 5000 || $unicodeLength($reference) > 500) {
        throw new Exception('Breach reason must be at most 5,000 characters and reference at most 500 characters.', 400);
    }
    return [
        'serious_breach_declared' => $declared,
        'serious_breach_reason' => $declared ? $reason : null,
        'serious_breach_reference' => $declared ? $reference : null,
    ];
}

function apSaveRevisionSnapshot($conn, int $appraisalId, int $companyId, int $revisionNo, string $action, int $createdBy, array $scoreData, array $recommendations = []): void {
    $revisionNo = max($revisionNo, 1);
    $allowedActions = ['baseline', 'submitted', 'updated'];
    if (!in_array($action, $allowedActions, true)) $action = 'updated';

    $summary = isset($scoreData['appraisal_summary']) ? (float)$scoreData['appraisal_summary'] : null;
    $rawSummary = isset($scoreData['raw_appraisal_summary']) ? (float)$scoreData['raw_appraisal_summary'] : $summary;
    $kpiRating = array_key_exists('kpi_rating', $scoreData) && $scoreData['kpi_rating'] !== null
        ? (float)$scoreData['kpi_rating']
        : null;
    $statement = (string)($scoreData['evaluation_statement'] ?? '');
    $statusUpgrade = (string)($recommendations['status_upgrade'] ?? '');
    $salaryUpgrade = (string)($recommendations['salary_upgrade'] ?? '');
    $development = (string)($recommendations['development'] ?? '');
    $breach = $recommendations['breach_declaration'] ?? [];
    $breachDeclared = array_key_exists('serious_breach_declared', $breach)
        ? $breach['serious_breach_declared'] : null;
    $breachReason = $breach['serious_breach_reason'] ?? null;
    $breachReference = $breach['serious_breach_reference'] ?? null;
    $sectionJson = json_encode($scoreData['section_scores'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($sectionJson === false) $sectionJson = '[]';

    $stmt = $conn->prepare("\n        INSERT INTO appraisal_revisions (\n            appraisal_id, company_id, revision_no, action, appraisal_summary, kpi_rating,\n            evaluation_statement, status_upgrade, salary_upgrade, development, section_scores_json, created_by,\n            serious_breach_declared, serious_breach_reason, serious_breach_reference, raw_appraisal_summary\n        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)\n        ON DUPLICATE KEY UPDATE\n            action=VALUES(action), appraisal_summary=VALUES(appraisal_summary), kpi_rating=VALUES(kpi_rating),\n            evaluation_statement=VALUES(evaluation_statement), status_upgrade=VALUES(status_upgrade),\n            salary_upgrade=VALUES(salary_upgrade), development=VALUES(development),\n            section_scores_json=VALUES(section_scores_json), created_by=VALUES(created_by),
            serious_breach_declared=VALUES(serious_breach_declared),
            serious_breach_reason=VALUES(serious_breach_reason),
            serious_breach_reference=VALUES(serious_breach_reference),
            raw_appraisal_summary=VALUES(raw_appraisal_summary),
            created_at=CURRENT_TIMESTAMP\n    ");
    if (!$stmt) throw new Exception('Unable to prepare appraisal revision snapshot: ' . $conn->error, 500);
    $stmt->bind_param(
        'iiisddsssssiissd',
        $appraisalId,
        $companyId,
        $revisionNo,
        $action,
        $summary,
        $kpiRating,
        $statement,
        $statusUpgrade,
        $salaryUpgrade,
        $development,
        $sectionJson,
        $createdBy,
        $breachDeclared,
        $breachReason,
        $breachReference,
        $rawSummary
    );
    if (!$stmt->execute()) {
        $error = $stmt->error ?: $conn->error;
        $stmt->close();
        throw new Exception('Unable to save appraisal revision snapshot: ' . $error, 500);
    }
    $stmt->close();
}

function apRevisionHistory($conn, int $appraisalId): array {
    $rows = apFetchAll($conn, "\n        SELECT ar.id, ar.revision_no, ar.action, ar.appraisal_summary, ar.kpi_rating,\n               ar.evaluation_statement, ar.status_upgrade, ar.salary_upgrade, ar.development,\n               ar.serious_breach_declared, ar.serious_breach_reason, ar.serious_breach_reference, ar.raw_appraisal_summary,\n               ar.section_scores_json, ar.created_by, ar.created_at,\n               TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS created_by_name,\n               u.email AS created_by_email\n        FROM appraisal_revisions ar\n        LEFT JOIN users u ON u.id = ar.created_by\n        WHERE ar.appraisal_id = {$appraisalId}\n        ORDER BY ar.revision_no DESC, ar.id DESC\n    ");

    $objectiveEventsByRevision = apocRevisionEvents($conn, $appraisalId);
    foreach ($rows as &$row) {
        $row['objective_changes'] = $objectiveEventsByRevision[(int)$row['revision_no']] ?? [];
        $decoded = json_decode((string)($row['section_scores_json'] ?? ''), true);
        $row['section_scores'] = is_array($decoded) ? $decoded : [];
        unset($row['section_scores_json']);
        $row['revision_no'] = (int)$row['revision_no'];
        $row['serious_breach_declared'] = $row['serious_breach_declared'] === null ? null : (int)$row['serious_breach_declared'];
        $row['appraisal_summary'] = $row['appraisal_summary'] !== null ? (float)$row['appraisal_summary'] : null;
        $row['raw_appraisal_summary'] = $row['raw_appraisal_summary'] !== null ? (float)$row['raw_appraisal_summary'] : null;
        $row['kpi_rating'] = $row['kpi_rating'] !== null ? (float)$row['kpi_rating'] : null;
    }
    unset($row);
    return $rows;
}

function apManagementReviewHistory($conn, int $appraisalId, int $currentRevisionNo = 0): array {
    $rows = apFetchAll($conn, "\n        SELECT mr.id, mr.review_status, mr.follow_up_state, mr.appraisal_revision_no, mr.category, mr.note,
               mr.reviewed_by, mr.resolved_by, mr.resolved_at, mr.created_at,
               TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS reviewer_name,
               u.email AS reviewer_email,
               TRIM(CONCAT(COALESCE(ru.first_name,''), ' ', COALESCE(ru.last_name,''))) AS resolved_by_name,
               ru.email AS resolved_by_email
        FROM appraisal_management_reviews mr
        LEFT JOIN users u ON u.id = mr.reviewed_by
        LEFT JOIN users ru ON ru.id = mr.resolved_by
        WHERE mr.appraisal_id = {$appraisalId}
        ORDER BY mr.id DESC
    ");

    $latestOpenFollowUp = null;
    foreach ($rows as &$row) {
        $row['appraisal_revision_no'] = (int)($row['appraisal_revision_no'] ?? 0);
        $reviewId = (int)($row['id'] ?? 0);
        $responses = $reviewId > 0 ? apFetchAll($conn, "\n            SELECT r.id, r.management_review_id, r.appraisal_revision_no, r.response, r.responded_by, r.created_at,
                   TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS responder_name,
                   u.email AS responder_email
            FROM appraisal_management_follow_up_responses r
            LEFT JOIN users u ON u.id = r.responded_by
            WHERE r.management_review_id = {$reviewId}
            ORDER BY r.id ASC
        ") : [];
        foreach ($responses as &$response) {
            $response['appraisal_revision_no'] = (int)($response['appraisal_revision_no'] ?? 0);
        }
        unset($response);
        $row['responses'] = $responses;
        $row['latest_response'] = !empty($responses) ? $responses[count($responses) - 1] : null;

        if (
            $latestOpenFollowUp === null
            && (string)($row['review_status'] ?? '') === 'follow_up'
            && (string)($row['follow_up_state'] ?? 'open') !== 'resolved'
        ) {
            $latestOpenFollowUp = $row;
        }
    }
    unset($row);

    $current = $rows[0] ?? null;
    if (!$current || ($currentRevisionNo > 0 && (int)$current['appraisal_revision_no'] !== $currentRevisionNo)) {
        $current = null;
    }

    $status = 'unreviewed';
    if ($latestOpenFollowUp) {
        $status = (string)($latestOpenFollowUp['follow_up_state'] ?? 'open') === 'responded'
            ? 'follow_up_responded'
            : 'follow_up';
    } elseif ($current) {
        $status = (string)$current['review_status'];
    }

    return [
        'current' => $current,
        'history' => $rows,
        'status' => $status,
        'open_follow_up' => $latestOpenFollowUp,
    ];
}

function apAudit($conn, $companyId, $userId, $action, $targetTable, $targetId, $description) {
    $action = apEsc($conn, $action);
    $targetTable = apEsc($conn, $targetTable);
    $description = apEsc($conn, $description);
    $companyId = $companyId ? (int)$companyId : 'NULL';
    $userId = $userId ? (int)$userId : 'NULL';
    $targetId = $targetId ? (int)$targetId : 0;
    $conn->query("INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description) VALUES ({$companyId}, {$userId}, '{$action}', '{$targetTable}', {$targetId}, '{$description}')");
}

require_once __DIR__ . '/../../includes/EmailLocale.php';

function apSendStaffEmail($kind, array $vars) {
    if (empty($vars['staff_email']) || !function_exists('sendMail')) return null;
    try {
        $companyName = $vars['company_name'] ?? 'Lambert Electromec Ltd';
        $vars['locale'] = appEmailRecipientLocale($GLOBALS['conn'], (int)($vars['staff_user_id'] ?? 0), (string)$vars['staff_email']);
        $subject = appEmailSubject($kind === 'update' ? 'updated' : 'submitted', $vars['locale'], ['company' => $companyName]);
        $html = $kind === 'update' && function_exists('getAppraisalUpdatedEmail')
            ? getAppraisalUpdatedEmail($vars)
            : (function_exists('getAppraisalSubmittedEmail') ? getAppraisalSubmittedEmail($vars) : '');
        if (!$html) return null;
        $result = sendMail($vars['staff_email'], $subject, $html, $companyName);
        return $result === true ? 'sent' : 'failed';
    } catch (Throwable $e) {
        // The appraisal has already committed; email failure must not trigger an HTTP 500.
        error_log('Appraisal notification email failed: ' . $e->getMessage());
        return 'failed';
    }
}
