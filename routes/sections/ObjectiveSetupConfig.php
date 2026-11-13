<?php

function objectiveSetupDefaults(): array {
    return [
        'enabled' => 0,
        'min_objectives' => 1,
        'max_objectives' => null,
        'require_weights' => 0,
        'required_weight_total' => 100.00,
        'require_target' => 0,
        'require_evidence' => 0,
        'require_actual_result' => 0,
        'allow_individual_objectives' => 1,
        'require_locked_plan' => 1,
        'lock_questions_during_appraisal' => 1,
        'force_per_question_ratings' => 1,
    ];
}

function objectiveSetupNormalize(array $input): array {
    $defaults = objectiveSetupDefaults();
    $config = array_merge($defaults, $input);

    $config['enabled'] = !empty($config['enabled']) ? 1 : 0;
    $config['min_objectives'] = max(0, (int)($config['min_objectives'] ?? 0));
    $config['max_objectives'] = ($config['max_objectives'] ?? '') === '' || $config['max_objectives'] === null
        ? null
        : max(1, (int)$config['max_objectives']);
    if ($config['max_objectives'] !== null && $config['max_objectives'] < $config['min_objectives']) {
        throw new Exception('Maximum objectives cannot be lower than minimum objectives.', 400);
    }

    $config['require_weights'] = !empty($config['require_weights']) ? 1 : 0;
    $config['required_weight_total'] = round((float)($config['required_weight_total'] ?? 100), 2);
    if ($config['required_weight_total'] <= 0 || $config['required_weight_total'] > 100) {
        throw new Exception('Required objective weight total must be greater than 0 and no more than 100%.', 400);
    }

    foreach (['require_target','require_evidence','require_actual_result','allow_individual_objectives','require_locked_plan','lock_questions_during_appraisal','force_per_question_ratings'] as $flag) {
        $config[$flag] = !empty($config[$flag]) ? 1 : 0;
    }

    return $config;
}

function objectiveSetupFetch(mysqli $conn, int $sectionId): array {
    $stmt = $conn->prepare('SELECT enabled, min_objectives, max_objectives, require_weights, required_weight_total, require_target, require_evidence, require_actual_result, allow_individual_objectives, require_locked_plan, lock_questions_during_appraisal, force_per_question_ratings FROM appraisal_section_objective_settings WHERE section_id = ? LIMIT 1');
    if (!$stmt) throw new Exception('Database error: ' . $conn->error, 500);
    $stmt->bind_param('i', $sectionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return objectiveSetupNormalize($row ?: objectiveSetupDefaults());
}

function objectiveSetupSave(mysqli $conn, int $sectionId, int $companyId, int $cycleId, array $input, int $userId): array {
    $config = objectiveSetupNormalize($input);
    $stmt = $conn->prepare('INSERT INTO appraisal_section_objective_settings (section_id, company_id, cycle_id, enabled, min_objectives, max_objectives, require_weights, required_weight_total, require_target, require_evidence, require_actual_result, allow_individual_objectives, require_locked_plan, lock_questions_during_appraisal, force_per_question_ratings, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE company_id=VALUES(company_id), cycle_id=VALUES(cycle_id), enabled=VALUES(enabled), min_objectives=VALUES(min_objectives), max_objectives=VALUES(max_objectives), require_weights=VALUES(require_weights), required_weight_total=VALUES(required_weight_total), require_target=VALUES(require_target), require_evidence=VALUES(require_evidence), require_actual_result=VALUES(require_actual_result), allow_individual_objectives=VALUES(allow_individual_objectives), require_locked_plan=VALUES(require_locked_plan), lock_questions_during_appraisal=VALUES(lock_questions_during_appraisal), force_per_question_ratings=VALUES(force_per_question_ratings), updated_by=VALUES(updated_by)');
    if (!$stmt) throw new Exception('Database error: ' . $conn->error, 500);
    $stmt->bind_param(
        'iiiiiiidiiiiiiii',
        $sectionId, $companyId, $cycleId,
        $config['enabled'], $config['min_objectives'], $config['max_objectives'],
        $config['require_weights'], $config['required_weight_total'],
        $config['require_target'], $config['require_evidence'], $config['require_actual_result'], $config['allow_individual_objectives'],
        $config['require_locked_plan'], $config['lock_questions_during_appraisal'], $config['force_per_question_ratings'],
        $userId
    );
    if (!$stmt->execute()) throw new Exception('Unable to save objective setup configuration: ' . $stmt->error, 500);
    $stmt->close();
    return $config;
}
