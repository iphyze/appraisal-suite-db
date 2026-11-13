# Database connection configuration

1. In the backend `api/` folder (the same folder as `index.php` and `composer.json`), create or edit **`.env`**.
2. Add the five settings shown in **`.env.example`** (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`). Replace placeholders with your *existing* database connection values. Do **not** overwrite other `.env` settings such as `JWT_SECRET`, AI, or email delivery.
3. Ensure Composer dependencies are installed in the backend (`composer install`) because this application already uses `vlucas/phpdotenv`.
4. Confirm that Apache blocks direct requests for `.env`. The included `.htaccess` adds this rule. On Nginx, explicitly deny hidden environment files in the Nginx server configuration.
5. Restart the PHP server or PHP-FPM service as needed, and test authentication and a database-backed endpoint. The API should return a generic 500 JSON response when settings are missing or invalid.

The configuration is used by `api/includes/connection.php` and continues to expose the existing `$conn` mysqli variable to the rest of the application. `DB_PORT` defaults to `3306` if omitted. All other settings must be present; `DB_PASSWORD` may be explicitly empty for a local development database.

**Never upload or commit a real `.env`.** `api/.gitignore` ignores `.env` and related environment variants, while allowing the nonsecret `.env.example` to be versioned. Rotate any previously hard-coded credentials that may have been shared or committed.

**Local test**: `php api/tests/database_env_config_tests.php` (does not need MariaDB). A real connection test requires the PHP `mysqli` extension, Composer dependencies, and database access; it cannot be simulated by the configuration test.
