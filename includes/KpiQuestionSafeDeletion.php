<?php

/**
 * KPI question removal policy. A question referenced by a staff selection or
 * an appraisal is preserved as inactive; only entirely unreferenced rows can
 * be physically deleted. Do not cascade or rewrite staff selections/history.
 *
 * Call inside an existing transaction, after the caller's access checks. The
 * parent row is locked before dependency checks to serialize concurrent FK
 * inserts (InnoDB must acquire a parent shared lock for each child insert).
 */
function kpiQuestionRemovalAction(int $staffAssignments, int $appraisalResponses): string {
    return ($staffAssignments > 0 || $appraisalResponses > 0) ? 'deactivated' : 'deleted';
}

function kpiQuestionRemoveSafely(mysqli $conn, int $questionId, int $companyId, int $actorId): string {
    $lock = $conn->prepare('SELECT id FROM kpi_questions WHERE id=? AND company_id=? LIMIT 1 FOR UPDATE');
    if (!$lock) throw new Exception('Unable to lock KPI question for removal.', 500);
    $lock->bind_param('ii', $questionId, $companyId);
    $lock->execute();
    $exists = $lock->get_result()->fetch_assoc();
    $lock->close();
    if (!$exists) throw new Exception('The KPI question has changed or is no longer available. Refresh and try again.', 409);

    $assignmentStmt = $conn->prepare('SELECT COUNT(*) AS total FROM staff_kpi_assignments WHERE kpi_question_id=?');
    if (!$assignmentStmt) throw new Exception('Unable to check KPI staff assignments.', 500);
    $assignmentStmt->bind_param('i', $questionId);
    $assignmentStmt->execute();
    $assignmentCount = (int)$assignmentStmt->get_result()->fetch_assoc()['total'];
    $assignmentStmt->close();

    $responseStmt = $conn->prepare('SELECT COUNT(*) AS total FROM appraisal_kpi_responses WHERE kpi_question_id=?');
    if (!$responseStmt) throw new Exception('Unable to check historical appraisal responses.', 500);
    $responseStmt->bind_param('i', $questionId);
    $responseStmt->execute();
    $responseCount = (int)$responseStmt->get_result()->fetch_assoc()['total'];
    $responseStmt->close();

    $action = kpiQuestionRemovalAction($assignmentCount, $responseCount);
    if ($action === 'deactivated') {
        $stmt = $conn->prepare('UPDATE kpi_questions SET is_active=0, updated_by=? WHERE id=? AND company_id=?');
        if (!$stmt) throw new Exception('Unable to deactivate referenced KPI question.', 500);
        $stmt->bind_param('iii', $actorId, $questionId, $companyId);
    } else {
        $stmt = $conn->prepare('DELETE FROM kpi_questions WHERE id=? AND company_id=?');
        if (!$stmt) throw new Exception('Unable to delete unused KPI question.', 500);
        $stmt->bind_param('ii', $questionId, $companyId);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception('Unable to complete the safe KPI question removal.', 500);
    }
    $stmt->close();
    return $action;
}
