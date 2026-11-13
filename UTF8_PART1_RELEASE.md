# AppraisalSuite — UTF-8 / Translations page repair (Part 1 only)

This patch changes the translation inventory, bulk preview/export, the standalone test
connection and adds migration 29. **It does not contain** `includes/connection.php`,
`.env`, a frontend update or the Part 2 sign-in language chooser.

## Maintenance window — apply as a single release

1. **Back up the complete MariaDB database**, verify the backup is usable, and pause
   application writes during the repair and connection switch.
2. Confirm phpMyAdmin's current connection is using **utf8mb4**, not latin1. Run
   `api/database/migration/29 - repair_double_encoded_text.sql` in phpMyAdmin.
   Its **first result** counts matching damaged values in each of eight columns.
   Its **final result** should show zero for all eight. The live-copy baseline
   supplied for this release is 19, 7, 1, 1, 2, 4, 1, 1 (36 total); production
   counts can differ if the database has changed. Running migration 29 again is
   expected to make no further changes.
3. **In the same maintenance window**, manually edit the existing **server**
   `api/includes/connection.php` on every application server. Immediately after
   the existing successful-connection check:

   ```php
   if (!$conn) {
       throw new RuntimeException('Database connection failed.', 500);
   }
   mysqli_set_charset($conn, 'utf8mb4');
   ```

   This line is intentionally **not** shipped in the ZIP: the connection file
   contains server-specific settings. Verify the charset-setting call succeeds
   and restart/reload PHP workers if OPcache is enabled.
4. Deploy the accompanying changed `api/includes/…` and test files. Restore
   application writes only once migration 29 **and** the connection update are
   complete. Never release only one half of this repair.
5. Sign in as a Super Admin and open **Translations**: the inventory and Excel
   export/preview should load. View an appraisal whose feedback contains `’`
   (for example `I’m`) and verify it is readable, not `Iâ€™m`. Confirm correctly
   stored French (é, –, « ») still displays correctly. Examine PHP error logs
   for any remaining `Translations inventory skipped invalid UTF-8` entries.

## Scope / safety

- Migration 29 uses plain SQL only, with eight guarded updates and read-only
  per-column counts before and after. It never edits historical migrations.
- If a malformed source is encountered, the Translations inventory or bulk
  export skips it and logs table, column and row reference **without logging
  its text**. Malformed import/edit requests are rejected; no repair is
  attempted during a normal page request.
- The codebase contains no additional *live* application mysqli constructors;
  routes include `includes/connection.php`. A standalone MariaDB integration
  test with its own connection now sets utf8mb4. Existing other database tests
  already set it; `new mysqli()` fakes are not live connections.
- No frontend files or user-facing locale strings were changed in Part 1.

## Verification limitations

The provided archive and isolated PHP unit tests were verified locally. The
production database and PHP mysqli extension were not available for a live
end-to-end test. Confirm the SQL's before/after counts on the deployment
server and complete the two browser checks above before signing off.
