<?php
/**
 * Durable one-time execution receipts for submissions with side effects.
 * Run database/migration/8 - api_idempotency_requests_migration.sql before deploying.
 * A processing/uncertain receipt is deliberately NOT retried automatically: the
 * original request might have committed (or an email might already have sent).
 */

function idempotencyBegin($conn, int $userId, string $route, string $body): array
{
    $key = trim((string)($_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{15,127}$/D', $key)) {
        throw new Exception('A valid X-Idempotency-Key header is required for this submission. Please refresh the application.', 400);
    }

    $fingerprint = hash('sha256', $body);
    try {
        $stmt = $conn->prepare("INSERT INTO api_idempotency_requests (user_id, route, request_key, request_hash, state) VALUES (?, ?, ?, ?, 'processing')");
    } catch (Throwable $e) {
        error_log('Idempotency setup error: ' . $e->getMessage());
        throw new Exception('Submission protection is unavailable. Apply the idempotency database migration.', 503);
    }
    if (!$stmt) throw new Exception('Submission protection is not configured. Apply the idempotency database migration.', 503);
    $stmt->bind_param('isss', $userId, $route, $key, $fingerprint);
    $duplicate = false;
    try {
        if (!$stmt->execute()) {
            if ((int)$stmt->errno !== 1062) throw new Exception('Unable to record the submission attempt.', 503);
            $duplicate = true;
        }
        $id = (int)$stmt->insert_id;
    } catch (mysqli_sql_exception $e) {
        if ((int)$e->getCode() !== 1062) {
            error_log('Idempotency storage failure: ' . $e->getMessage());
            throw new Exception('Submission protection is unavailable. Ensure the idempotency database migration has been applied.', 503);
        }
        $duplicate = true;
    } finally {
        $stmt->close();
    }

    if (!$duplicate) return ['id' => $id, 'replay' => false];

    try {
        $lookup = $conn->prepare('SELECT request_hash, state, response_status, response_body FROM api_idempotency_requests WHERE user_id = ? AND route = ? AND request_key = ? LIMIT 1');
    } catch (Throwable $e) {
        error_log('Idempotency lookup error: ' . $e->getMessage());
        throw new Exception('Unable to verify the previous submission.', 503);
    }
    if (!$lookup) throw new Exception('Unable to verify the previous submission.', 503);
    $lookup->bind_param('iss', $userId, $route, $key);
    $lookup->execute();
    $previous = $lookup->get_result()->fetch_assoc();
    $lookup->close();

    if (!$previous) throw new Exception('Unable to verify the previous submission. Refresh to check its status.', 409);
    if (!hash_equals((string)$previous['request_hash'], $fingerprint)) {
        throw new Exception('This submission key was already used for different content.', 409);
    }
    if ($previous['state'] === 'completed' && $previous['response_body'] !== null && (int)$previous['response_status'] < 400) {
        return [
            'replay' => true,
            'status' => (int)$previous['response_status'],
            'body' => (string)$previous['response_body'],
        ];
    }
    if ($previous['state'] === 'completed') {
        // A refused attempt recorded before this fix: release it and run the request normally.
        $release = $conn->prepare("DELETE FROM api_idempotency_requests WHERE user_id = ? AND route = ? AND request_key = ? AND state = 'completed'");
        if ($release) {
            $release->bind_param('iss', $userId, $route, $key);
            $release->execute();
            $release->close();
            return idempotencyBegin($conn, $userId, $route, $body);
        }
    }
    throw new Exception('This submission was already received and may still be processing. Wait a minute, then open the appraisal list to check whether it was saved before sending it again.', 409);
}

function idempotencyFinish($conn, int $id, int $responseStatus, string $body): void
{
    // 4xx = the request was refused (validation, permission, not found, expired session…) and
    // nothing was written: free the key so the user can correct the problem and submit again.
    // Replaying a stored 4xx used to repeat the same error until the page was refreshed.
    if ($responseStatus >= 400 && $responseStatus < 500) {
        try {
            $stmt = $conn->prepare("DELETE FROM api_idempotency_requests WHERE id = ? AND state = 'processing'");
            if ($stmt) {
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('Idempotency receipt release failed for ID ' . $id . ': ' . $e->getMessage());
        }
        return;
    }

    // 2xx = done; a repeat of the same submission gets the same successful response.
    // 5xx may occur AFTER a write/email. Preserve uncertainty rather than
    // freeing the key for another unsafe attempt.
    $state = $responseStatus >= 500 ? 'uncertain' : 'completed';
    $stmt = $conn->prepare('UPDATE api_idempotency_requests SET state = ?, response_status = ?, response_body = ?, completed_at = NOW() WHERE id = ? AND state = \'processing\'');
    if (!$stmt) {
        error_log('Idempotency receipt update could not be prepared for ID ' . $id);
        return;
    }
    try {
        $stmt->bind_param('sisi', $state, $responseStatus, $body, $id);
        if (!$stmt->execute()) error_log('Idempotency receipt update failed for ID ' . $id);
    } catch (Throwable $e) {
        // Leave the receipt in 'processing' so an uncertain submission cannot
        // run twice merely because writing the receipt failed.
        error_log('Idempotency receipt error: ' . $e->getMessage());
    } finally {
        $stmt->close();
    }
}
