<?php
require_once __DIR__ . '/../../includes/ApiException.php';

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
require_once 'includes/AdminAppraisalVisibility.php';
require_once 'includes/CompanyAppraisalWrite.php';
require_once __DIR__ . '/AppraisalHelpers.php';
require_once __DIR__ . '/../utils/Notifications.php';

header('Content-Type: application/json');

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new Exception('Only POST is allowed.', 405);
    }
    $user = requireRoles(['admin', 'super_admin']);
    $actorId = (int)$user['id'];
    $role = authRoleKey($user['role'] ?? '');
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) throw new Exception('Invalid request body.', 400);
    $appraisalId = (int)($data['appraisal_id'] ?? 0);
    $expectedRevision = (int)($data['expected_revision_no'] ?? 0);
    $decision = strtolower(trim((string)($data['decision'] ?? '')));
    $note = trim((string)($data['note'] ?? ''));
    if ($appraisalId <= 0 || $expectedRevision <= 0) throw new Exception('Appraisal and current revision are required.', 400);
    if (!in_array($decision, ['confirmed', 'dismissed'], true)) throw new Exception('Decision must be Confirmed or Dismissed.', 400);
    if ($note === '') throw new Exception('A management decision reason is required.', 400);
    $noteLength = function_exists('mb_strlen') ? mb_strlen($note, 'UTF-8') : strlen($note);
    if ($noteLength > 5000) throw new Exception('Decision reason must not exceed 5,000 characters.', 400);

    $conn->begin_transaction();
    try {
        $ap = apFetchOne($conn, "
            SELECT a.*, cy.year AS cycle_year FROM appraisals a
            INNER JOIN appraisal_cycles cy ON cy.id = a.cycle_id
            WHERE a.id = {$appraisalId} FOR UPDATE
        ");
        if (!$ap) throw new Exception('Appraisal not found.', 404);
        if ($actorId === (int)$ap['staff_user_id'] || $actorId === (int)$ap['supervisor_id']) {
            throw new Exception('A manager cannot verify an appraisal they received or personally conducted.', 403);
        }
        $writeContext = companyAppraisalWriteContext($user);
        companyAppraisalWriteAssertRecord($conn, $user, $writeContext, $ap);
        if (!(int)$ap['breach_cap_active'] && (int)$ap['serious_breach_declared'] !== 1) {
            throw new Exception('This appraisal has no outstanding serious-breach declaration.', 409);
        }
        $rev = apFetchOne($conn, 'SELECT MAX(revision_no) AS revision_no FROM appraisal_revisions WHERE appraisal_id=' . $appraisalId);
        $latestRevision = max(1, (int)($rev['revision_no'] ?? 0));
        if ($latestRevision !== $expectedRevision) {
            throw new Exception('This appraisal was revised. Reload it and verify the latest version.', 409);
        }
        if ((string)$ap['serious_breach_review_status'] === $decision) {
            throw new Exception('This decision has already been recorded. Refresh the appraisal to review it.', 409);
        }

        $raw = $ap['raw_appraisal_summary'] === null ? (float)$ap['appraisal_summary'] : (float)$ap['raw_appraisal_summary'];
        $capActive = $decision === 'confirmed';
        $final = apScoreWithBreachCap($raw, $capActive);
        $config = json_decode((string)($ap['rating_config_snapshot'] ?? ''), true);
        if (!is_array($config) || !isset($config['rating_bands'])) {
            $config = apCycleRatingConfig($conn, (int)$ap['company_id'], (int)$ap['cycle_id']);
        }
        $statement = apEvaluationStatementFromConfig($config, $final);
        $stmt = $conn->prepare('UPDATE appraisals SET breach_cap_active=?, serious_breach_review_status=?, serious_breach_review_note=?, serious_breach_reviewed_by=?, serious_breach_reviewed_at=NOW(), appraisal_summary=?, evaluation_statement=? WHERE id=?');
        if (!$stmt) throw new Exception('Unable to prepare safety decision.', 500);
        $active = $capActive ? 1 : 0;
        $stmt->bind_param('issidsi', $active, $decision, $note, $actorId, $final, $statement, $appraisalId);
        if (!$stmt->execute()) throw new Exception('Unable to save safety decision: ' . $stmt->error, 500);
        $stmt->close();

        $stmt = $conn->prepare('INSERT INTO appraisal_breach_verifications (appraisal_id, company_id, appraisal_revision_no, decision, note, reviewed_by, raw_score, final_score) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$stmt) throw new Exception('Unable to prepare verification history.', 500);
        $companyId = (int)$ap['company_id'];
        $stmt->bind_param('iiissidd', $appraisalId, $companyId, $latestRevision, $decision, $note, $actorId, $raw, $final);
        if (!$stmt->execute()) throw new Exception('Unable to save verification history: ' . $stmt->error, 500);
        $stmt->close();

        companyAppraisalWriteAudit($conn, $user, $writeContext, $companyId, 'verify_serious_breach', 'appraisals', $appraisalId,
            "Management {$decision} the safety/integrity breach for revision {$latestRevision}. Raw score: {$raw}; final score: {$final}. Reason: {$note}");
        $supervisorId = (int)$ap['supervisor_id'];
        if ($supervisorId && $supervisorId !== $actorId) {
            companyAppraisalWriteNotify($conn, $writeContext, $companyId, $supervisorId, 'appraisal_breach_verification', 'Safety/integrity declaration reviewed',
                "Management {$decision} the serious-breach declaration on {$ap['staff_fullname']}'s {$ap['cycle_year']} appraisal.", '/appraisals/view/' . $appraisalId,
                $decision === 'confirmed' ? 'notification.breachConfirmed' : 'notification.breachDismissed', ['name' => $ap['staff_fullname'], 'year' => (string)$ap['cycle_year']]);
        }
        if (abs($final - (float)$ap['appraisal_summary']) > 0.005) {
            $staffId = (int)$ap['staff_user_id'];
            if ($staffId && $staffId !== $actorId) {
                companyAppraisalWriteNotify($conn, $writeContext, $companyId, $staffId, 'appraisal_breach_score_changed', 'Your appraisal score has changed',
                    "Management {$decision} the safety/integrity declaration. Your current final appraisal score is " . number_format($final, 2) . '.',
                    '/appraisals/view/' . $appraisalId,
                    $decision === 'confirmed' ? 'notification.breachScoreConfirmed' : 'notification.breachScoreDismissed',
                    ['score' => number_format($final, 2, '.', '')]);
            }
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    http_response_code(200);
    echo json_encode(['status' => 'Success', 'message' => 'Safety/integrity decision recorded.',
        'message_key' => 'breach.success',
        'message_params' => new stdClass(), 'data' => [
        'appraisal_id' => $appraisalId, 'review_status' => $decision,
        'raw_appraisal_summary' => $raw, 'appraisal_summary' => $final, 'evaluation_statement' => $statement,
    ]]);
} catch (Throwable $e) {
    $code = (int)$e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 500;
    if ($code === 500) error_log('Safety review failure: ' . $e->getMessage());
    http_response_code($code);
    echo json_encode(apiErrorPayload($e, $code === 500 ? 'Unable to save the safety decision. Contact support.' : null));
}
