<?php
/** Consume an emailed one-time reset token; never sign in automatically. */
require_once __DIR__ . '/../../includes/ApiException.php';
require_once __DIR__ . '/../../includes/PasswordRecovery.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new Exception('Method not allowed.', 405);
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) throw new Exception('Invalid request format.', 400);
    $token = (string)($data['token'] ?? '');
    $password = (string)($data['new_password'] ?? '');
    $confirmation = (string)($data['confirm_password'] ?? '');
    if ($password !== $confirmation) throw new Exception('New password and confirmation do not match.', 422);
    passwordRecoveryConsume($conn, $token, $password);
    clearAuthCookies();
    echo json_encode(['status' => 'Success', 'message' => 'Password reset. Sign in with your new password.',
        'message_key' => 'auth.recoveryCompleted', 'message_params' => new stdClass()]);
} catch (Throwable $error) {
    $status = (int)$error->getCode();
    $status = ($status >= 400 && $status <= 599) ? $status : 500;
    http_response_code($status);
    $message = $error->getMessage();
    $key = match ($message) {
        'New password and confirmation do not match.' => 'auth.confirm_mismatch',
        'Password must contain 8 to 128 characters.' => 'auth.recoveryPasswordLength',
        'Choose a password different from your current password.' => 'auth.recoveryPasswordDifferent',
        default => 'auth.recoveryInvalid',
    };
    echo json_encode(['status' => 'Failed',
        'message' => $status >= 500 ? 'Unable to process your request.' : $message,
        'message_key' => $status >= 500 ? 'auth.recoveryFailed' : $key,
        'message_params' => new stdClass()]);
}
