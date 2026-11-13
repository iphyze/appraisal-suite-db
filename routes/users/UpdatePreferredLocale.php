<?php
require_once __DIR__ . '/../../includes/ApiException.php';
/** Any signed-in user may update their own language preference. */
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/Locale.php';
require_once __DIR__ . '/../../includes/UserIdentity.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'PUT') {
        throw new Exception('Only PUT requests are allowed.', 405);
    }

    $user = authenticateUser();
    $payload = json_decode(file_get_contents('php://input'), true);
    $locale = is_array($payload) ? appSupportedLocale($payload['locale'] ?? null) : null;
    if ($locale === null) {
        throw new Exception('Choose a supported language: en or fr.', 422);
    }

    $identityId = (int)($user['identity_id'] ?? 0);
    if ($identityId > 0) {
        // Person-wide preference, including Phase 1 dual-write to each
        // membership so the existing language dialog remains one-time.
        userIdentitySaveLocale($conn, $identityId, $locale);
    } else {
        // Only the unlinked accounts awaiting personnel confirmation retain
        // their existing membership-level locale until identity linking.
        $stmt = $conn->prepare('UPDATE users SET preferred_locale = ? WHERE id = ?');
        if (!$stmt) throw new Exception('Could not save language preference.', 500);
        $userId = (int)$user['id'];
        $stmt->bind_param('si', $locale, $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Could not save language preference.', 500);
        }
        $stmt->close();
    }

    echo json_encode([
        'status' => 'Success',
        'message' => 'Language preference updated.',
        'message_key' => 'auth.languageSaved',
        'message_params' => new stdClass(),
        'data' => [
            'preferred_locale' => $locale,
            'effective_locale' => $locale,
        ],
    ]);
} catch (Throwable $error) {
    $code = (int)$error->getCode();
    $status = $code >= 400 && $code <= 599 ? $code : 500;
    http_response_code($status);
    echo json_encode(apiErrorPayload($error,
        $status >= 500 && function_exists('apiIsProduction') && apiIsProduction()
            ? 'Internal server error.' : null));
}
