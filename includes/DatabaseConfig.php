<?php
/**
 * Resolve database settings from the PHP process environment (including
 * values loaded from api/.env by vlucas/phpdotenv).
 *
 * No database connection is attempted here, so this function can be tested
 * independently of a running MariaDB server.
 */
function appraisalDatabaseConfig(?callable $reader = null): array
{
    $reader ??= static function (string $name): ?string {
        if (array_key_exists($name, $_ENV)) {
            return (string) $_ENV[$name];
        }

        $value = getenv($name);
        return $value === false ? null : (string) $value;
    };

    $host = trim((string) ($reader('DB_HOST') ?? ''));
    $username = trim((string) ($reader('DB_USER') ?? ''));
    $database = trim((string) ($reader('DB_NAME') ?? ''));
    // Preserve passwords exactly, including empty passwords on local setups.
    // The setting itself must be explicit: never fall back to a built-in secret.
    $password = $reader('DB_PASSWORD');
    $portValue = $reader('DB_PORT');
    $portValue = $portValue === null ? '' : trim((string) $portValue);

    if ($host === '' || $username === '' || $database === '' || $password === null) {
        throw new RuntimeException('Database connection is not configured. Check DB_* in the backend .env.', 500);
    }

    if ($portValue !== '' && (!ctype_digit($portValue) || (int) $portValue < 1 || (int) $portValue > 65535)) {
        throw new RuntimeException('Invalid DB_PORT in the backend .env.', 500);
    }

    return [
        'host' => $host,
        'username' => $username,
        'password' => (string) $password,
        'database' => $database,
        'port' => $portValue === '' ? 3306 : (int) $portValue,
    ];
}
