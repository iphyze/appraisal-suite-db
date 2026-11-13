<?php
/** Super Admin question-only copy across cycles and companies. No staff-owned questions. */
require_once __DIR__ . '/CompanySetupClone.php';

function cqCopyReason(string $reason): string
{
    return companySetupCloneReason($reason);
}

function cqCopyIds($source, $target): array
{
    $source = filter_var($source, FILTER_VALIDATE_INT);
    $target = filter_var($target, FILTER_VALIDATE_INT);
    if (!$source || !$target || $source <= 0 || $target <= 0 || $source === $target) {
        throw new Exception('Select two different, valid appraisal cycles.', 400);
    }
    return [(int)$source, (int)$target];
}

function cqKey(string $type, int $id): string
{
    return ($type === 'general' ? 'g-' : 'k-') . $id;
}

function cqNormalize(string $value): string
{
    $value = preg_replace('/\s+/u', ' ', trim($value));
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function cqQuestionIdentity(string $type, array $q, int $sectionId): string
{
    $text = cqNormalize((string)$q['question_text']);
    if ($type === 'kpi') return implode('|', [$sectionId, cqNormalize((string)$q['department']), $text]);
    return $sectionId . '|' . $text;
}

function cqSelectedKeys($keys): array
{
    if (!is_array($keys) || count($keys) < 1 || count($keys) > 2500) {
        throw new Exception('Select between 1 and 2,500 questions.', 400);
    }
    $unique = [];
    foreach ($keys as $key) {
        if (!is_string($key) || !preg_match('/^(g|k)-[1-9][0-9]*$/D', $key)) {
            throw new Exception('Invalid question selection.', 400);
        }
        $unique[$key] = true;
    }
    if (count($unique) !== count($keys)) throw new Exception('Duplicate selection received.', 400);
    return $unique;
}

function cqLoad(mysqli $db, int $sourceId, int $targetId, bool $lock = false): array
{
    [$sourceId, $targetId] = cqCopyIds($sourceId, $targetId);
    $cycles = companySetupCloneRows($db,
        'SELECT cy.id, cy.company_id, cy.year, cy.title, c.name AS company_name, c.code AS company_code, c.is_active AS company_active '
        . 'FROM appraisal_cycles cy JOIN companies c ON c.id=cy.company_id WHERE cy.id IN (?, ?) ORDER BY cy.id'
        . ($lock ? ' FOR UPDATE' : ''), 'ii', [$sourceId, $targetId]);
    $byId = [];
    foreach ($cycles as $cycle) $byId[(int)$cycle['id']] = $cycle;
    if (!isset($byId[$sourceId], $byId[$targetId])) throw new Exception('The selected source or target cycle does not exist.', 404);
    $from = $byId[$sourceId];
    $to = $byId[$targetId];
    if (!(int)$from['company_active'] || !(int)$to['company_active']) {
        throw new Exception('Both source and destination companies must be active.', 409);
    }
    $sections = companySetupCloneRows($db,
        'SELECT id, company_id, cycle_id, code, label, type, is_active FROM appraisal_sections WHERE cycle_id IN (?, ?) ORDER BY id',
        'ii', [$sourceId, $targetId]);
    $sourceSections = []; $targetSections = [];
    foreach ($sections as $section) {
        $isSource = (int)$section['cycle_id'] === $sourceId && (int)$section['company_id'] === (int)$from['company_id'];
        $isTarget = (int)$section['cycle_id'] === $targetId && (int)$section['company_id'] === (int)$to['company_id'];
        if ($isSource) $sourceSections[(int)$section['id']] = $section;
        if ($isTarget) $targetSections[(int)$section['id']] = $section;
    }
    $general = companySetupCloneRows($db,
        'SELECT q.id, q.section_id, q.question_text, q.question_code, q.question_label, q.anchor_low_value, q.anchor_low_text, q.anchor_mid_value, q.anchor_mid_text, q.anchor_high_value, q.anchor_high_text, q.sort_order, q.is_active '
        . 'FROM general_questions q JOIN appraisal_sections s ON s.id=q.section_id WHERE s.cycle_id=? AND s.company_id=? AND q.company_id=? ORDER BY s.sort_order, q.sort_order, q.id',
        'iii', [$sourceId, $from['company_id'], $from['company_id']]);
    $kpi = companySetupCloneRows($db,
        'SELECT q.id, q.section_id, q.department, q.supervisor_id, q.staff_user_id, q.question_text, q.objective_code, q.measure_target, q.evidence_source, q.review_status, q.review_note, q.weight_percent, q.sort_order, q.is_active '
        . 'FROM kpi_questions q JOIN appraisal_sections s ON s.id=q.section_id WHERE s.cycle_id=? AND s.company_id=? AND q.company_id=? ORDER BY s.sort_order, q.department, q.sort_order, q.id',
        'iii', [$sourceId, $from['company_id'], $from['company_id']]);
    $existingGeneral = companySetupCloneRows($db,
        'SELECT q.section_id, q.question_text FROM general_questions q JOIN appraisal_sections s ON s.id=q.section_id WHERE s.cycle_id=? AND s.company_id=? AND q.company_id=?',
        'iii', [$targetId, $to['company_id'], $to['company_id']]);
    $existingKpi = companySetupCloneRows($db,
        'SELECT q.section_id, q.department, q.question_text FROM kpi_questions q JOIN appraisal_sections s ON s.id=q.section_id WHERE s.cycle_id=? AND s.company_id=? AND q.company_id=?',
        'iii', [$targetId, $to['company_id'], $to['company_id']]);
    $departments = companySetupCloneRows($db,
        'SELECT name FROM departments WHERE company_id=? AND is_active=1', 'i', [$to['company_id']]);
    $sourceRules = companySetupCloneRows($db,
        'SELECT rules.general_question_id, p.code, p.cycle_id AS profile_cycle_id, p.company_id AS profile_company_id '
        . 'FROM general_question_scoring_profiles rules '
        . 'JOIN general_questions q ON q.id=rules.general_question_id '
        . 'JOIN appraisal_sections s ON s.id=q.section_id '
        . 'LEFT JOIN appraisal_scoring_profiles p ON p.id=rules.profile_id '
        . 'WHERE s.cycle_id=? AND s.company_id=? AND q.company_id=?',
        'iii', [$sourceId, $from['company_id'], $from['company_id']]);
    $targetProfiles = companySetupCloneRows($db,
        'SELECT id, code FROM appraisal_scoring_profiles WHERE cycle_id=? AND company_id=? AND is_active=1',
        'ii', [$targetId, $to['company_id']]);
    return compact('from', 'to', 'sourceSections', 'targetSections', 'general', 'kpi', 'existingGeneral', 'existingKpi', 'departments', 'sourceRules', 'targetProfiles');
}

/** Pure planner: never copies staff/supervisor KPI overrides or mismatched section types. */
function cqBuildPlan(array $bundle): array
{
    $targetMap = [];
    foreach ($bundle['targetSections'] as $section) {
        $targetMap[cqNormalize((string)$section['code'])] = $section;
    }
    $departments = [];
    foreach ($bundle['departments'] as $d) $departments[cqNormalize((string)$d['name'])] = true;
    $targetProfiles = [];
    foreach ($bundle['targetProfiles'] as $p) $targetProfiles[cqNormalize((string)$p['code'])] = (int)$p['id'];
    $rules = [];
    foreach ($bundle['sourceRules'] as $rule) $rules[(int)$rule['general_question_id']][] = $rule;
    $duplicates = ['general' => [], 'kpi' => []];
    foreach (['general' => $bundle['existingGeneral'], 'kpi' => $bundle['existingKpi']] as $type => $existing) {
        foreach ($existing as $q) $duplicates[$type][cqQuestionIdentity($type, $q, (int)$q['section_id'])] = true;
    }
    $seen = $duplicates;
    $rows = [];
    $excludedPersonal = 0;
    foreach (['general' => $bundle['general'], 'kpi' => $bundle['kpi']] as $type => $items) {
        foreach ($items as $q) {
            // No personal/employee identifiers are serialized into the preview.
            if ($type === 'kpi' && ($q['staff_user_id'] !== null || $q['supervisor_id'] !== null)) {
                $excludedPersonal++;
                continue;
            }
            $sourceSection = $bundle['sourceSections'][(int)$q['section_id']] ?? null;
            if (!$sourceSection || $sourceSection['type'] !== $type) continue;
            $targetSection = $targetMap[cqNormalize((string)$sourceSection['code'])] ?? null;
            $blocker = null;
            if (!$targetSection) $blocker = 'Destination section ' . $sourceSection['code'] . ' is missing.';
            elseif ($targetSection['type'] !== $type) $blocker = 'Destination section ' . $sourceSection['code'] . ' has a different type.';
            elseif (array_key_exists('is_active', $targetSection) && (int)$targetSection['is_active'] !== 1) $blocker = 'Destination section ' . $sourceSection['code'] . ' is inactive.';
            elseif ($type === 'kpi' && !isset($departments[cqNormalize((string)$q['department'])])) {
                $blocker = 'Destination department ' . $q['department'] . ' is missing or inactive.';
            }
            $profileIds = [];
            if ($type === 'general' && !$blocker) {
                foreach ($rules[(int)$q['id']] ?? [] as $rule) {
                    $code = (string)($rule['code'] ?? '');
                    // Never widen a restricted question if its source mapping is invalid.
                    if ($code === '' || (isset($rule['profile_cycle_id']) && (int)$rule['profile_cycle_id'] !== (int)$bundle['from']['id'])
                        || (isset($rule['profile_company_id']) && (int)$rule['profile_company_id'] !== (int)$bundle['from']['company_id'])) {
                        $blocker = 'Source scoring-profile mapping is invalid; review it before copying.'; break;
                    }
                    $match = $targetProfiles[cqNormalize($code)] ?? null;
                    if (!$match) { $blocker = 'Destination scoring profile ' . $code . ' is missing or inactive.'; break; }
                    $profileIds[] = $match;
                }
            }
            $identity = $targetSection ? cqQuestionIdentity($type, $q, (int)$targetSection['id']) : null;
            $status = $blocker ? 'blocked' : (isset($seen[$type][$identity]) ? 'existing' : 'ready');
            if ($status === 'ready') $seen[$type][$identity] = true;
            $rows[] = [
                'key' => cqKey($type, (int)$q['id']), 'type' => $type, 'question_text' => $q['question_text'],
                'section_code' => $sourceSection['code'], 'section_label' => $sourceSection['label'],
                'department' => $type === 'kpi' ? $q['department'] : null,
                'status' => $status, 'message' => $blocker ?? ($status === 'existing' ? 'Already in destination' : null),
                'target_section_id' => $targetSection ? (int)$targetSection['id'] : null,
                'profile_ids' => $profileIds,
                'source' => $q,
            ];
        }
    }
    $counts = ['general' => 0, 'kpi' => 0, 'ready' => 0, 'existing' => 0, 'blocked' => 0, 'excluded_personal_kpis' => $excludedPersonal];
    foreach ($rows as $row) {
        $counts[$row['type']]++;
        $counts[$row['status']]++;
    }
    return ['rows' => $rows, 'counts' => $counts];
}

function cqPublicPreview(array $bundle, array $plan): array
{
    return [
        'source_cycle' => $bundle['from'], 'target_cycle' => $bundle['to'], 'counts' => $plan['counts'],
        'questions' => array_map(static function ($row) {
            return array_intersect_key($row, array_flip(['key','type','question_text','section_code','section_label','department','status','message']));
        }, $plan['rows']),
    ];
}

function cqApply(mysqli $db, array $bundle, array $plan, array $selected, array $actor, string $reason): array
{
    $rowMap = [];
    foreach ($plan['rows'] as $row) $rowMap[$row['key']] = $row;
    foreach ($selected as $key => $_) {
        if (!isset($rowMap[$key])) throw new Exception('A selected question is not in the source cycle.', 409);
        if ($rowMap[$key]['status'] !== 'ready') throw new Exception('A selected question cannot be copied: ' . ($rowMap[$key]['message'] ?: 'already present'), 409);
    }
    $companyId = (int)$bundle['to']['company_id'];
    $actorId = (int)$actor['id'];
    $copied = ['general' => 0, 'kpi' => 0];
    foreach ($selected as $key => $_) {
        $row = $rowMap[$key];
        $q = $row['source'];
        if ($row['type'] === 'general') {
            $newId = companySetupCloneInsert($db, 'general_questions', [
                'company_id' => $companyId, 'section_id' => $row['target_section_id'], 'question_text' => $q['question_text'],
                'question_code' => $q['question_code'], 'question_label' => $q['question_label'],
                'anchor_low_value' => $q['anchor_low_value'], 'anchor_low_text' => $q['anchor_low_text'],
                'anchor_mid_value' => $q['anchor_mid_value'], 'anchor_mid_text' => $q['anchor_mid_text'],
                'anchor_high_value' => $q['anchor_high_value'], 'anchor_high_text' => $q['anchor_high_text'],
                'sort_order' => (int)$q['sort_order'], 'is_active' => (int)$q['is_active'], 'created_by' => $actorId, 'updated_by' => $actorId,
            ]);
            if ($row['profile_ids']) {
                $link = $db->prepare('INSERT INTO general_question_scoring_profiles (general_question_id, profile_id) VALUES (?, ?)');
                if (!$link) throw new Exception('Cannot create question profile mapping.', 500);
                try {
                    foreach ($row['profile_ids'] as $profileId) {
                        $link->bind_param('ii', $newId, $profileId);
                        if (!$link->execute()) throw new Exception('Cannot copy question profile applicability.', 500);
                    }
                } finally { $link->close(); }
            }
        } else {
            companySetupCloneInsert($db, 'kpi_questions', [
                'company_id' => $companyId, 'section_id' => $row['target_section_id'],
                'department' => $q['department'], 'supervisor_id' => null, 'staff_user_id' => null,
                'question_text' => $q['question_text'], 'objective_code' => $q['objective_code'],
                'measure_target' => $q['measure_target'], 'evidence_source' => $q['evidence_source'],
                'review_status' => $q['review_status'], 'review_note' => $q['review_note'],
                'weight_percent' => $q['weight_percent'], 'sort_order' => (int)$q['sort_order'],
                'is_active' => (int)$q['is_active'], 'created_by' => $actorId, 'updated_by' => $actorId,
            ]);
        }
        $copied[$row['type']]++;
    }
    $description = 'Super Admin copied questions from ' . $bundle['from']['company_name'] . ' (' . $bundle['from']['year'] . ', cycle #' . $bundle['from']['id'] . ') to '
        . $bundle['to']['company_name'] . ' (' . $bundle['to']['year'] . ', cycle #' . $bundle['to']['id'] . '). '
        . 'General: ' . $copied['general'] . ', KPI: ' . $copied['kpi'] . '. '
        . 'Actor home company #' . (int)($actor['company_id'] ?? 0) . '. Reason: ' . $reason;
    $stmt = $db->prepare('INSERT INTO audit_log (company_id, user_id, action, target_table, target_id, description) VALUES (?, ?, ?, ?, ?, ?)');
    if (!$stmt) throw new Exception('Unable to prepare copy audit log.', 500);
    $action = 'copy_cycle_questions'; $table = 'appraisal_cycles'; $targetId = (int)$bundle['to']['id'];
    try {
        $stmt->bind_param('iissis', $companyId, $actorId, $action, $table, $targetId, $description);
        if (!$stmt->execute()) throw new Exception('Unable to record question-copy audit log.', 500);
    } finally { $stmt->close(); }
    return ['general_copied' => $copied['general'], 'kpi_copied' => $copied['kpi'], 'target_cycle_id' => $targetId];
}
