<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/AuthSecurity.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, private');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Bad Request: Only GET method is allowed.', 405);
    }

    $user = authenticateUser();

    /*
     * Reuse the current browser CSRF token when it is still valid. Rotating it
     * on every session probe makes another open tab's in-memory token stale.
     */
    $csrfToken = currentOrIssueCsrfToken();

    // Cookie renewal is allowed only after authenticateUser() has verified the
    // signature, expiry, live account, and token_version against the database.
    // Bearer-only clients are not silently issued a browser session cookie.
    $sessionExpiresAt = null;
    if (!empty($_COOKIE['lambert_session'])) {
        $claims = JWT::decode(
            (string)$_COOKIE['lambert_session'],
            new Key(securityJwtSecret(), 'HS256')
        );
        $sessionExpiresAt = (int)($claims->exp ?? 0);
        $renewBeforeSeconds = min(30 * 60, max(60, (int)(securityExpirySeconds() / 4)));

        if ($sessionExpiresAt > 0 && ($sessionExpiresAt - time()) <= $renewBeforeSeconds) {
            $sessionExpiresAt = renewValidatedCookieSession($user);
        }
    }

    unset($user['token_version'], $user['identity_token_version'],
          $user['identity_is_active'], $user['company_is_active']);

    echo json_encode([
        'status'  => 'Success',
        'message' => 'Session is valid.',
        'data'    => array_merge($user, [
            'csrf_token' => $csrfToken,
        ]),
    ]);
} catch (Exception $e) {
    $code = (int) $e->getCode();
    $code = $code >= 400 && $code <= 599 ? $code : 401;

    http_response_code($code);

    echo json_encode([
        'status'  => 'Failed',
        'message' => $e->getMessage(),
    ]);
}
