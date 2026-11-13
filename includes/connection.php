<?php

// Use the same backend .env path as api/index.php and the auth/AI helpers.
// No database credentials are kept in PHP source code.
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/DatabaseConfig.php';

try {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
    $dbConfig = appraisalDatabaseConfig();

    // Preserve the existing $conn variable expected by all API routes.
    $conn = mysqli_connect(
        $dbConfig['host'],
        $dbConfig['username'],
        $dbConfig['password'],
        $dbConfig['database'],
        $dbConfig['port']
    );

    if (!$conn) {
        throw new RuntimeException('Database connection failed.', 500);
    }

    // Ensure MariaDB communicates using UTF-8.
    mysqli_set_charset($conn, 'utf8mb4');

} catch (Throwable $e) {
    error_log('Database Connection Error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Internal Server Error',
        'details' => 'Unable to connect to the database',
    ]);
    exit;
}
