<?php
/**
 * Batch 9A: Super Admin-only, empty-target cross-company configuration clone.
 * No people, individual KPI questions, appraisals, assignments, or plans are copied.
 * The existing /cycles/copy route deliberately remains same-company-only.
 */

function companySetupCloneReason(string $reason): string
{
    $reason = trim($reason);
    $length = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : preg_match_all('/./us', $reason);
    if ($length === false || $length < 5 || $length > 500) {
        throw new Exception('A reason of 5–500 characters is required.', 400);
    }
    return $reason;
}

function companySetupCloneValidateIds(int $sourceCycleId, int $targetCompanyId): void
{
    if ($sourceCycleId <= 0 || $targetCompanyId <= 0) {
        throw new Exception('A source cycle and target company are required.', 400);
    }
}

/** Never allow a user reference in the company-wide KPI library copy. */
function companySetupCloneMayCopyKpi(array $question): bool
{
    return ($question['supervisor_id'] ?? null) === null
        && ($question['staff_user_id'] ?? null) === null;
}

/** Tables copied by this feature; all table names below are server-side constants. */
function companySetupCloneTables(): array
{
    return [
        'departments', 'appraisal_cycles', 'appraisal_cycle_settings',
        'appraisal_sections', 'appraisal_section_objective_settings',
        'appraisal_scoring_profiles', 'appraisal_profile_section_weights',
        'appraisal_rating_bands', 'appraisal_rating_points',
        'general_questions', 'general_question_scoring_profiles', 'kpi_questions',
    ];
}

function companySetupCloneRows(mysqli $db, string $sql, string $types = '', array $params = []): array
{
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new Exception('Database error: ' . $db->error, 500);
    try {
        if ($types !== '') $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) throw new Exception('Unable to load company setup: ' . $stmt->error, 500);
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } finally {
        $stmt->close();
    }
}

function companySetupCloneOne(mysqli $db, string $sql, string $types = '', array $params = []): ?array
{
    return companySetupCloneRows($db, $sql, $types, $params)[0] ?? null;
}

/** Only fixed, server-controlled table and column names can reach INSERT. */
function companySetupCloneInsert(mysqli $db, string $table, array $values): int
{
    if (!in_array($table, companySetupCloneTables(), true) || !$values) {
        throw new Exception('Invalid setup-copy table.', 500);
    }
    foreach (array_keys($values) as $column) {
        if (!preg_match('/^[a-z_]+$/D', $column)) {
            throw new Exception('Invalid setup-copy column.', 500);
        }
    }
    $columns = array_keys($values);
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $columns) . '`) VALUES ('
        . implode(',', array_fill(0, count($columns), '?')) . ')';
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new Exception('Database error: ' . $db->error, 500);
    $params = array_values($values);
    $types = '';
    foreach ($params as $value) {
        $types .= is_int($value) ? 'i' : (is_float($value) ? 'd' : 's');
    }
    try {
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) throw new Exception('Unable to copy company setup: ' . $stmt->error, 500);
        return (int) $stmt->insert_id;
    } finally {
        $stmt->close();
    }
}

/**
 * Exact named-column selection prevents accidental future PII columns being copied.
 * No source row is mutated or deleted.
 */
function companySetupCloneLoad(mysqli $db, int $sourceCycleId, int $targetCompanyId, bool $lock = false): array
{
    companySetupCloneValidateIds($sourceCycleId, $targetCompanyId);
    $lockSql = $lock ? ' FOR UPDATE' : '';
    // Row locks are taken in ID order when applying a copy.
    $cycle = companySetupCloneOne($db, 'SELECT id, company_id, year, title, start_date, end_date, is_active FROM appraisal_cycles WHERE id = ? LIMIT 1' . $lockSql, 'i', [$sourceCycleId]);
    if (!$cycle || (int) $cycle['is_active'] !== 1) {
        throw new Exception('Select an active source appraisal cycle.', 404);
    }
    $sourceCompanyId = (int) $cycle['company_id'];
    if ($sourceCompanyId === $targetCompanyId) {
        throw new Exception('Source and target companies must be different.', 409);
    }
    $companies = companySetupCloneRows($db,
        'SELECT id, code, name, is_active FROM companies WHERE id IN (?, ?) ORDER BY id' . $lockSql,
        'ii', [$sourceCompanyId, $targetCompanyId]);
    if (count($companies) !== 2 || (int)$companies[0]['is_active'] !== 1 || (int)$companies[1]['is_active'] !== 1) {
        throw new Exception('Source and target companies must both be active.', 409);
    }
    $byCompany = [];
    foreach ($companies as $company) $byCompany[(int)$company['id']] = $company;
    foreach (['appraisal_cycles', 'departments', 'users'] as $table) {
        $existing = companySetupCloneOne($db,
            'SELECT COUNT(*) AS total FROM `' . $table . '` WHERE company_id = ?', 'i', [$targetCompanyId]);
        if ((int)$existing['total'] !== 0) {
            throw new Exception('The target company must be new and empty (no cycles, departments or users).', 409);
        }
    }
    $tables = [];
    $tables['departments'] = companySetupCloneRows($db,
        'SELECT name, is_active FROM departments WHERE company_id = ? ORDER BY id', 'i', [$sourceCompanyId]);
    $tables['sections'] = companySetupCloneRows($db,
        'SELECT id, code, label, description, type, weight, default_rating_mode, lock_rating_mode, sort_order, is_active FROM appraisal_sections WHERE cycle_id = ? AND company_id = ? ORDER BY sort_order, id', 'ii', [$sourceCycleId, $sourceCompanyId]);
    $tables['cycle_settings'] = companySetupCloneRows($db,
        'SELECT rating_min, rating_max, rating_step, require_explicit_profile, management_review_enabled FROM appraisal_cycle_settings WHERE cycle_id = ? AND company_id = ?', 'ii', [$sourceCycleId, $sourceCompanyId]);
    $tables['objective_settings'] = companySetupCloneRows($db,
        'SELECT section_id, enabled, min_objectives, max_objectives, require_weights, required_weight_total, require_target, require_evidence, require_actual_result, allow_individual_objectives, require_locked_plan, lock_questions_during_appraisal, force_per_question_ratings FROM appraisal_section_objective_settings WHERE cycle_id = ? AND company_id = ?', 'ii', [$sourceCycleId, $sourceCompanyId]);
    $tables['profiles'] = companySetupCloneRows($db,
        'SELECT id, code, label, description, is_default, is_active, sort_order FROM appraisal_scoring_profiles WHERE cycle_id = ? AND company_id = ? ORDER BY id', 'ii', [$sourceCycleId, $sourceCompanyId]);
    $tables['profile_weights'] = companySetupCloneRows($db,
        'SELECT w.profile_id, w.section_id, w.weight FROM appraisal_profile_section_weights w INNER JOIN appraisal_scoring_profiles p ON p.id = w.profile_id WHERE p.cycle_id = ? AND p.company_id = ?', 'ii', [$sourceCycleId, $sourceCompanyId]);
    $tables['rating_bands'] = companySetupCloneRows($db,
        'SELECT label, min_score, max_score, sort_order, is_active FROM appraisal_rating_bands WHERE cycle_id = ? AND company_id = ? ORDER BY id', 'ii', [$sourceCycleId, $sourceCompanyId]);
    $tables['rating_points'] = companySetupCloneRows($db,
        'SELECT rating_value, label, definition, evidence_requirement, requires_comment, sort_order, is_active FROM appraisal_rating_points WHERE cycle_id = ? AND company_id = ? ORDER BY id', 'ii', [$sourceCycleId, $sourceCompanyId]);
    $tables['general_questions'] = companySetupCloneRows($db,
        'SELECT q.id, q.section_id, q.question_text, q.question_code, q.question_label, q.anchor_low_value, q.anchor_low_text, q.anchor_mid_value, q.anchor_mid_text, q.anchor_high_value, q.anchor_high_text, q.sort_order, q.is_active FROM general_questions q INNER JOIN appraisal_sections s ON s.id = q.section_id WHERE s.cycle_id = ? AND q.company_id = ? AND s.company_id = ? ORDER BY q.id', 'iii', [$sourceCycleId, $sourceCompanyId, $sourceCompanyId]);
    $tables['question_profiles'] = companySetupCloneRows($db,
        'SELECT m.general_question_id, m.profile_id FROM general_question_scoring_profiles m INNER JOIN general_questions q ON q.id = m.general_question_id INNER JOIN appraisal_sections s ON s.id = q.section_id WHERE s.cycle_id = ? AND q.company_id = ? AND s.company_id = ?', 'iii', [$sourceCycleId, $sourceCompanyId, $sourceCompanyId]);
    $tables['kpi_questions'] = companySetupCloneRows($db,
        'SELECT q.section_id, q.department, q.supervisor_id, q.staff_user_id, q.question_text, q.objective_code, q.measure_target, q.evidence_source, q.review_status, q.review_note, q.weight_percent, q.sort_order, q.is_active FROM kpi_questions q INNER JOIN appraisal_sections s ON s.id = q.section_id WHERE s.cycle_id = ? AND q.company_id = ? AND s.company_id = ? ORDER BY q.id', 'iii', [$sourceCycleId, $sourceCompanyId, $sourceCompanyId]);
    return [
        'cycle' => $cycle,
        'source_company' => $byCompany[$sourceCompanyId],
        'target_company' => $byCompany[$targetCompanyId],
        'tables' => $tables,
    ];
}

function companySetupClonePreview(array $bundle): array
{
    $tables = $bundle['tables'];
    $defaultKpis = array_filter($tables['kpi_questions'], 'companySetupCloneMayCopyKpi');
    return [
        'source_company' => $bundle['source_company'],
        'source_cycle' => $bundle['cycle'],
        'target_company' => $bundle['target_company'],
        'counts' => [
            'departments' => count($tables['departments']),
            'cycles' => 1,
            'sections' => count($tables['sections']),
            'objective_section_settings' => count($tables['objective_settings']),
            'scoring_profiles' => count($tables['profiles']),
            'profile_section_weights' => count($tables['profile_weights']),
            'rating_bands' => count($tables['rating_bands']),
            'rating_points' => count($tables['rating_points']),
            'general_questions' => count($tables['general_questions']),
            'general_question_profiles' => count($tables['question_profiles']),
            'department_kpis' => count($defaultKpis),
            'excluded_personal_kpis' => count($tables['kpi_questions']) - count($defaultKpis),
        ],
        'excluded' => ['users', 'supervisors', 'staff', 'appraisals', 'objective_plans', 'supervisor_assignments', 'individual_kpis', 'staff_kpi_assignments', 'notifications', 'ai_history'],
    ];
}

/** Transaction is owned by the caller so that the grant-independent audit is atomic. */
function companySetupCloneApplyBundle(mysqli $db, array $bundle, array $actor, string $reason): array
{
    $reason = companySetupCloneReason($reason);
    $tables = $bundle['tables'];
    if (count($tables['cycle_settings']) !== 1 || count($tables['sections']) === 0) {
        throw new Exception('Source cycle is missing required settings or sections.', 409);
    }
    $companyId = (int) $bundle['target_company']['id'];
    $actorId = (int) $actor['id'];
    $source = $bundle['cycle'];
    $cycleId = companySetupCloneInsert($db, 'appraisal_cycles', [
        'company_id' => $companyId, 'year' => $source['year'], 'title' => $source['title'],
        'start_date' => $source['start_date'], 'end_date' => $source['end_date'], 'is_active' => 1,
        'created_by' => $actorId,
    ]);
    foreach ($tables['departments'] as $row) {
        companySetupCloneInsert($db, 'departments', ['company_id' => $companyId] + $row);
    }
    companySetupCloneInsert($db, 'appraisal_cycle_settings',
        ['cycle_id' => $cycleId, 'company_id' => $companyId] + $tables['cycle_settings'][0]
        + ['created_by' => $actorId, 'updated_by' => $actorId]);
    $sectionMap = [];
    foreach ($tables['sections'] as $row) {
        $sourceId = (int)$row['id']; unset($row['id']);
        $sectionMap[$sourceId] = companySetupCloneInsert($db, 'appraisal_sections',
            ['company_id' => $companyId, 'cycle_id' => $cycleId] + $row + ['created_by' => $actorId]);
    }
    foreach ($tables['objective_settings'] as $row) {
        $sourceId = (int)$row['section_id'];
        if (!isset($sectionMap[$sourceId])) throw new Exception('Source objective setting has an invalid section.', 409);
        $row['section_id'] = $sectionMap[$sourceId];
        companySetupCloneInsert($db, 'appraisal_section_objective_settings',
            ['company_id' => $companyId, 'cycle_id' => $cycleId] + $row + ['updated_by' => $actorId]);
    }
    foreach ($tables['rating_bands'] as $row) {
        companySetupCloneInsert($db, 'appraisal_rating_bands',
            ['company_id' => $companyId, 'cycle_id' => $cycleId] + $row
            + ['created_by' => $actorId, 'updated_by' => $actorId]);
    }
    foreach ($tables['rating_points'] as $row) {
        companySetupCloneInsert($db, 'appraisal_rating_points',
            ['company_id' => $companyId, 'cycle_id' => $cycleId] + $row
            + ['created_by' => $actorId, 'updated_by' => $actorId]);
    }
    $profileMap = [];
    foreach ($tables['profiles'] as $row) {
        $sourceId = (int)$row['id']; unset($row['id']);
        $profileMap[$sourceId] = companySetupCloneInsert($db, 'appraisal_scoring_profiles',
            ['company_id' => $companyId, 'cycle_id' => $cycleId] + $row
            + ['created_by' => $actorId, 'updated_by' => $actorId]);
    }
    foreach ($tables['profile_weights'] as $row) {
        $oldProfile = (int)$row['profile_id']; $oldSection = (int)$row['section_id'];
        if (!isset($profileMap[$oldProfile], $sectionMap[$oldSection])) {
            throw new Exception('Source scoring profile has an invalid section reference.', 409);
        }
        companySetupCloneInsert($db, 'appraisal_profile_section_weights', [
            'profile_id' => $profileMap[$oldProfile],
            'section_id' => $sectionMap[$oldSection], 'weight' => $row['weight'],
        ]);
    }
    $questionMap = [];
    foreach ($tables['general_questions'] as $row) {
        $oldId = (int)$row['id']; $oldSection = (int)$row['section_id'];
        if (!isset($sectionMap[$oldSection])) throw new Exception('Source general question has an invalid section.', 409);
        unset($row['id']); $row['section_id'] = $sectionMap[$oldSection];
        $questionMap[$oldId] = companySetupCloneInsert($db, 'general_questions',
            ['company_id' => $companyId] + $row + ['created_by' => $actorId, 'updated_by' => $actorId]);
    }
    foreach ($tables['question_profiles'] as $row) {
        $oldQuestion = (int)$row['general_question_id']; $oldProfile = (int)$row['profile_id'];
        if (!isset($questionMap[$oldQuestion], $profileMap[$oldProfile])) {
            throw new Exception('Source question has an invalid scoring profile.', 409);
        }
        companySetupCloneInsert($db, 'general_question_scoring_profiles', [
            'general_question_id' => $questionMap[$oldQuestion], 'profile_id' => $profileMap[$oldProfile],
        ]);
    }
    foreach ($tables['kpi_questions'] as $row) {
        if (!companySetupCloneMayCopyKpi($row)) continue;
        $oldSection = (int)$row['section_id'];
        if (!isset($sectionMap[$oldSection])) throw new Exception('Source KPI has an invalid section.', 409);
        $row['section_id'] = $sectionMap[$oldSection];
        // Explicitly discard source-user references even for company-default KPIs.
        unset($row['supervisor_id'], $row['staff_user_id']);
        companySetupCloneInsert($db, 'kpi_questions', ['company_id' => $companyId] + $row
            + ['created_by' => $actorId, 'updated_by' => $actorId]);
    }
    $description = sprintf('Super Admin copied company setup from %s (cycle %d) to %s; actor home company: %s (id %d). Reason: %s',
        $bundle['source_company']['name'], (int)$source['id'], $bundle['target_company']['name'],
        $actor['company_name'] ?? 'Unknown', (int)($actor['company_id'] ?? 0), $reason);
    companySetupCloneInsertAudit($db, $companyId, $actorId, $cycleId, $description);
    return ['cycle_id' => $cycleId, 'summary' => companySetupClonePreview($bundle)];
}

function companySetupCloneInsertAudit(mysqli $db, int $companyId, int $actorId, int $cycleId, string $description): void
{
    $stmt = $db->prepare('INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description) VALUES (?, ?, ?, ?, ?, ?)');
    if (!$stmt) throw new Exception('Audit logging unavailable.', 500);
    $action = 'copy_company_setup'; $table = 'appraisal_cycles';
    try {
        $stmt->bind_param('iissis', $companyId, $actorId, $action, $table, $cycleId, $description);
        if (!$stmt->execute()) throw new Exception('Unable to audit company setup copy.', 500);
    } finally {
        $stmt->close();
    }
}
