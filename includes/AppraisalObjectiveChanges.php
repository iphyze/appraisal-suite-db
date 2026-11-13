<?php
require_once __DIR__ . '/ApiException.php';
/**
 * Submitted-objective re-rating: never use a client-supplied objective ID,
 * target, evidence or weight as the authority when a locked plan was changed.
 * All callers of the write helpers hold the appraisal FOR UPDATE first.
 */

/** Pure diff used by the edit form and the immutable revision evidence. */
function apocDifference(array $before, array $after, int $sectionId): array
{
    $index = static function (array $rows): array {
        $items = [];
        foreach ($rows as $row) {
            $id = (int)($row['kpi_question_id'] ?? $row['question_id'] ?? $row['id'] ?? 0);
            if ($id <= 0) continue;
            $weight = $row['question_weight'] ?? $row['weight_percent'] ?? null;
            $items[$id] = [
                'question_id' => $id,
                'question_text' => (string)($row['question_text'] ?? ''),
                'objective_target' => $row['objective_target'] ?? $row['target'] ?? null,
                'evidence_source' => $row['evidence_source'] ?? null,
                'question_weight' => $weight === null || $weight === '' ? null : (float)$weight,
            ];
        }
        ksort($items, SORT_NUMERIC);
        return $items;
    };
    $old = $index($before);
    $new = $index($after);
    $added = [];
    $removed = [];
    $changed = [];
    foreach ($new as $id => $item) {
        if (!isset($old[$id])) {
            $added[] = $item;
            continue;
        }
        $previous = $old[$id];
        $fields = [];
        foreach (['objective_target', 'evidence_source', 'question_weight'] as $field) {
            $a = $previous[$field];
            $b = $item[$field];
            $different = $field === 'question_weight'
                ? (($a === null) !== ($b === null) || ($a !== null && $b !== null && abs($a - $b) > 0.001))
                : (trim((string)$a) !== trim((string)$b));
            if ($different) $fields[] = $field;
        }
        if ($fields) $changed[] = ['question_id' => $id, 'fields' => $fields, 'before' => $previous, 'after' => $item];
    }
    foreach ($old as $id => $item) {
        if (!isset($new[$id])) $removed[] = $item;
    }
    return ['section_id' => $sectionId, 'added' => $added, 'removed' => $removed, 'changed' => $changed];
}

/** The live, locked per-staff selection, including individual objective IDs. */
function apocLockedObjectiveRows(mysqli $conn, int $companyId, int $sectionId, int $staffId): array
{
    $plan = apFetchOne($conn, 'SELECT id, status FROM staff_objective_plans WHERE section_id=' . $sectionId . ' AND staff_user_id=' . $staffId . ' LIMIT 1');
    if (!$plan || ($plan['status'] ?? '') !== 'locked') {
        throw new Exception('Lock the objective plan before updating the appraisal.', 409);
    }
    $rows = apFetchAll($conn, "
        SELECT q.id, q.objective_code, q.question_text,
               COALESCE(ska.target_override,q.measure_target) AS objective_target,
               COALESCE(ska.evidence_override,q.evidence_source) AS evidence_source,
               COALESCE(ska.weight_percent,q.weight_percent) AS question_weight,
               q.department, q.supervisor_id, q.staff_user_id, q.sort_order,
               CASE WHEN q.staff_user_id = {$staffId} THEN 'individual'
                    WHEN q.supervisor_id IS NOT NULL THEN 'supervisor'
                    ELSE 'department' END AS scope
        FROM staff_kpi_assignments ska
        INNER JOIN kpi_questions q ON q.id = ska.kpi_question_id
        WHERE ska.section_id = {$sectionId}
          AND ska.staff_user_id = {$staffId}
          AND q.company_id = {$companyId}
        ORDER BY q.sort_order ASC, ska.id ASC
    ");
    if (!$rows) throw new ApiException('objective.planEmpty', [], 409, 'The locked objective plan has no objectives.');
    return $rows;
}

/** Stable over ordering and SQL decimal formatting; changes for any objective
 * text/target/evidence/weight. This is a stale-form guard, NOT the marker used
 * to decide whether a submitted appraisal needs re-rating.
 */
function apocFingerprintItems(array $items): string
{
    $canonical = [];
    foreach ($items as $item) {
        $id = (int)($item['id'] ?? $item['kpi_question_id'] ?? $item['question_id'] ?? 0);
        if ($id <= 0) throw new Exception('The locked objective plan contains an invalid objective.', 409);
        $weight = $item['question_weight'] ?? $item['weight_percent'] ?? null;
        $canonical[$id] = [
            'id' => $id,
            'text' => trim((string)($item['question_text'] ?? '')),
            'target' => trim((string)($item['objective_target'] ?? $item['target'] ?? '')),
            'evidence' => trim((string)($item['evidence_source'] ?? '')),
            'weight' => $weight === null || $weight === '' ? null : number_format((float)$weight, 2, '.', ''),
        ];
    }
    ksort($canonical, SORT_NUMERIC);
    return hash('sha256', json_encode(array_values($canonical), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/** The edit form must submit this signature; the update route recalculates it
 * under the appraisal/plan locks to reject a form opened on an older plan.
 */
function apocPlanSignature(mysqli $conn, int $appraisalId, int $companyId, int $cycleId, int $staffId): string
{
    $plans = apFetchAll($conn, "
        SELECT sop.section_id, sop.status
        FROM staff_objective_plans sop
        INNER JOIN appraisal_section_scores sc ON sc.section_id=sop.section_id AND sc.appraisal_id={$appraisalId}
        INNER JOIN appraisal_sections sec ON sec.id=sop.section_id AND sec.type='kpi'
        WHERE sop.cycle_id={$cycleId} AND sop.staff_user_id={$staffId}
        ORDER BY sop.section_id ASC
    ");
    $state = [];
    foreach ($plans as $plan) {
        if (($plan['status'] ?? '') !== 'locked') throw new ApiException('objective.lockBeforeUpdate', [], 409, 'Lock the objective plan before updating the appraisal.');
        $sid = (int)$plan['section_id'];
        $state[$sid] = apocFingerprintItems(apocLockedObjectiveRows($conn, $companyId, $sid, $staffId));
    }
    return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
}

/** Lock all existing KPI plans after the appraisal lock, following Batch 2 lock order. */
function apocLockPlansForAppraisal(mysqli $conn, int $appraisalId, int $cycleId, int $staffId): void
{
    $plans = apFetchAll($conn, "
        SELECT sop.id, sop.status FROM staff_objective_plans sop
        INNER JOIN appraisal_section_scores sc ON sc.section_id=sop.section_id AND sc.appraisal_id={$appraisalId}
        WHERE sop.cycle_id={$cycleId} AND sop.staff_user_id={$staffId}
        ORDER BY sop.id ASC FOR UPDATE
    ");
    foreach ($plans as $plan) {
        if (($plan['status'] ?? '') !== 'locked') {
            throw new Exception('Lock the objective plan before updating the appraisal.', 409);
        }
    }
}

/** Capture before and after rated responses inside the same update transaction. */
function apocResponseSnapshot(mysqli $conn, int $appraisalId, ?int $sectionId = null): array
{
    $filter = $sectionId === null ? '' : ' AND section_id=' . $sectionId;
    return apFetchAll($conn, "
        SELECT section_id, kpi_question_id, question_text, objective_target, evidence_source,
               actual_result, question_weight, rating, rating_comment
        FROM appraisal_kpi_responses
        WHERE appraisal_id={$appraisalId}{$filter}
        ORDER BY section_id ASC, id ASC
    ");
}

/** Each unlock reason is attached once; the rated after-image is immutable. */
function apocConsumeUnlockEvents(mysqli $conn, int $appraisalId, int $revisionNo, array $after, bool $mustHaveChange): void
{
    $events = apFetchAll($conn, "
        SELECT ev.id, ev.relocked_at, sop.section_id
        FROM appraisal_objective_change_events ev
        INNER JOIN staff_objective_plans sop ON sop.id=ev.objective_plan_id
        WHERE ev.appraisal_id={$appraisalId} AND ev.consumed_revision_no IS NULL
        ORDER BY ev.id ASC FOR UPDATE
    ");
    if ($mustHaveChange && !$events) {
        throw new ApiException('objective.auditMissing', [], 409, 'The objective-change audit is missing. Recheck the locked plan before updating.');
    }
    $bySection = [];
    foreach ($after as $row) $bySection[(int)$row['section_id']][] = $row;
    $stmt = $conn->prepare('UPDATE appraisal_objective_change_events SET consumed_revision_no=?, rated_snapshot_json=?, objective_section_id=? WHERE id=? AND consumed_revision_no IS NULL');
    if (!$stmt) throw new Exception('Unable to prepare objective revision evidence.', 500);
    foreach ($events as $event) {
        if (empty($event['relocked_at'])) throw new Exception('Lock all edited objective plans before updating the appraisal.', 409);
        $snapshot = json_encode($bySection[(int)$event['section_id']] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $eventId = (int)$event['id'];
        $sectionId = (int)$event['section_id'];
        $stmt->bind_param('isii', $revisionNo, $snapshot, $sectionId, $eventId);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            $stmt->close();
            throw new Exception('Unable to associate the objective change with this revision.', 500);
        }
    }
    $stmt->close();
}

/** History is only exposed by the already-authorized appraisal detail endpoint. */
function apocRevisionEvents(mysqli $conn, int $appraisalId): array
{
    $events = apFetchAll($conn, "
        SELECT ev.id, ev.consumed_revision_no, ev.unlock_reason, ev.unlocked_at, ev.relocked_at,
               ev.submitted_snapshot_json, ev.locked_snapshot_json, ev.rated_snapshot_json,
               ev.objective_section_id AS section_id, ev.actor_user_id
        FROM appraisal_objective_change_events ev
        WHERE ev.appraisal_id={$appraisalId} AND ev.consumed_revision_no IS NOT NULL
        ORDER BY ev.id ASC
    ");
    $byRevision = [];
    foreach ($events as $event) {
        $before = json_decode((string)$event['submitted_snapshot_json'], true) ?: [];
        $after = json_decode((string)$event['rated_snapshot_json'], true) ?: [];
        $locked = json_decode((string)$event['locked_snapshot_json'], true) ?: [];
        $number = (int)$event['consumed_revision_no'];
        $byRevision[$number][] = [
            'event_id' => (int)$event['id'], 'section_id' => (int)$event['section_id'],
            'actor_user_id' => (int)$event['actor_user_id'],
            'unlock_reason' => $event['unlock_reason'], 'unlocked_at' => $event['unlocked_at'],
            'relocked_at' => $event['relocked_at'], 'before' => $before, 'locked_plan' => $locked,
            'after' => $after, 'difference' => apocDifference($before, $locked, (int)$event['section_id']),
        ];
    }
    return $byRevision;
}
