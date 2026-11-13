<?php
/**
 * Retired standalone registration writer. The only supported user-creation
 * route is POST /users/create, which checks Company Access, uses the shared
 * login identity and applies CSRF protection in the single index.php router.
 * Keeping an independent legacy INSERT here would create orphan credentials.
 */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
http_response_code(410);
echo json_encode([
    'status' => 'Failed',
    'message' => 'Registration moved to the authenticated users creation endpoint.',
]);
