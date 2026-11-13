<?php
/**
 * Single source of truth for a conducting supervisor's existing update eligibility.
 * This intentionally excludes payload-dependent HSE validation: the update endpoint
 * still checks the declaration against the FOR UPDATE row before saving scores.
 */
require_once __DIR__ . '/AdminStaffScope.php';

function apUpdateEligibilityResult(bool $allowed, string $reasonKey, int $updatesLeft): array
{
    return ['allowed' => $allowed, 'reason_key' => $reasonKey,
        'updates_left' => max(0, $updatesLeft)];
}

function apUpdateAttemptsLeft(array $appraisal): int
{
    return max(0, 2 + (int)($appraisal['extra_update_allowance'] ?? 0) - (int)($appraisal['update_count'] ?? 0));
}

function apCanSupervisorUpdateAppraisal(mysqli $conn, array $actor, ?array $context, array $appraisal): array
{
    $left = apUpdateAttemptsLeft($appraisal);
    $actorId = (int)($actor['id'] ?? 0);
    $role = strtolower(str_replace(' ', '_', trim((string)($actor['role'] ?? ''))));
    $companyId = (int)($appraisal['company_id'] ?? 0);
    $staffId = (int)($appraisal['staff_user_id'] ?? 0);
    $cycleId = (int)($appraisal['cycle_id'] ?? 0);
    $supervisorId = (int)($appraisal['supervisor_id'] ?? 0);
    if (!$companyId || !$staffId || !$cycleId || !$supervisorId) {
        return apUpdateEligibilityResult(false, 'appraisal.notFound', $left);
    }
    // Admins cannot update a personal appraisal while viewing a granted company.
    if ($role === 'admin' && $companyId !== (int)($actor['company_id'] ?? 0)) {
        return apUpdateEligibilityResult(false, 'appraisal.companyDenied', $left);
    }
    if ($role === 'admin' && $staffId !== $actorId) {
        $stmt = $conn->prepare('SELECT u.id, u.company_id, u.staff_type, r.name AS role_name FROM users u INNER JOIN roles r ON r.id=u.role_id WHERE u.id=? LIMIT 1');
        if (!$stmt) throw new RuntimeException('Unable to verify staff scope.');
        $stmt->bind_param('i', $staffId);
        $stmt->execute();
        $subject = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($subject) {
            $subject['staff_type'] = $subject['staff_type'] ?? ($appraisal['staff_type'] ?? null);
        }
        if ($subject && !staffScopeAllowsSubject($conn, $actor, $context, $subject, $cycleId, $supervisorId)) {
            return apUpdateEligibilityResult(false, 'appraisal.scopeDenied', $left);
        }
    }
    if (!userCanConductAppraisals($actor) || $supervisorId !== $actorId) {
        return apUpdateEligibilityResult(false, 'appraisal.supervisorOnly', $left);
    }
    $assignment = $conn->prepare('SELECT id FROM supervisor_assignments WHERE cycle_id=? AND staff_id=? AND supervisor_id=? LIMIT 1');
    if (!$assignment) throw new RuntimeException('Unable to verify supervisor assignment.');
    $assignment->bind_param('iii', $cycleId, $staffId, $actorId);
    $assignment->execute();
    $foundAssignment = $assignment->get_result()->num_rows > 0;
    $assignment->close();
    if (!$foundAssignment) return apUpdateEligibilityResult(false, 'appraisal.assignmentChanged', $left);

    $onboarding = $conn->prepare('SELECT id FROM supervisor_onboarding WHERE cycle_id=? AND supervisor_id=? LIMIT 1');
    if (!$onboarding) throw new RuntimeException('Unable to verify supervisor onboarding.');
    $onboarding->bind_param('ii', $cycleId, $actorId);
    $onboarding->execute();
    $hasOnboarding = $onboarding->get_result()->num_rows > 0;
    $onboarding->close();
    if (!$hasOnboarding) return apUpdateEligibilityResult(false, 'appraisal.onboardingEdit', $left);
    if ($left <= 0) return apUpdateEligibilityResult(false, 'appraisal.noUpdates', 0);
    return apUpdateEligibilityResult(true, '', $left);
}

/** Preserve the pre-existing user-facing denial text and status. */
function apAssertSupervisorUpdateEligible(array $eligibility, bool $locked = false): void
{
    if (!empty($eligibility['allowed'])) return;
    $messages = [
        'appraisal.notFound' => 'Appraisal not found.',
        'appraisal.companyDenied' => 'Unauthorized: This appraisal does not belong to your company.',
        'appraisal.scopeDenied' => 'Unauthorized: This appraisal is outside your staff scope.',
        'appraisal.supervisorOnly' => 'Unauthorized: Only the assigned supervisor can update this appraisal.',
        'appraisal.assignmentChanged' => 'This employee is no longer assigned to you for the appraisal cycle.',
        'appraisal.onboardingEdit' => 'Complete supervisor onboarding before editing this appraisal.',
        'appraisal.noUpdates' => $locked
            ? 'No update attempts remain. Ask an administrator to restore your update allowance.'
            : 'You have reached the maximum number of updates for this appraisal. Please contact an administrator for further changes.',
    ];
    $key = $eligibility['reason_key'] ?? 'appraisal.supervisorOnly';
    throw new Exception($messages[$key] ?? $messages['appraisal.supervisorOnly'], 403);
}
