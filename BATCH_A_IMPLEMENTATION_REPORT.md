# AppraisalSuite — Batch A: Admin supervisor team exemption

Source audited: user-provided backend/frontend ZIP files and 10 November 2026 MariaDB export.

## Scope audit completed before changes

See `STAFF_SCOPE_INVENTORY_BEFORE_CHANGES.md` for every original `staff_scope` reference, with file name and original line numbers: 58 backend application files (plus 47 backend test files) and 16 frontend files. References span authentication/session payload, company grants, user directory and write permissions, appraisals, objectives, supervisor assignments, dashboard, mail, exports, notifications, AI reporting and frontend forms/context display.

## Implemented

- Added `includes/AdminStaffScope.php`, one shared record-level authorization helper and cycle-aware SQL predicates for people and historical appraisals.
- Home-company Admins can access their own current-cycle appraisees assigned in `supervisor_assignments` even if the staff type differs from their admin-wide scope. For existing appraisal records, `appraisals.supervisor_id` also authorizes a historical appraisal that the admin personally conducted.
- Integrated helper with appraisal candidates and supervisor assignment lists, appraisal form/create/update/view, objective plans, appraisal lists, administrative appraisal actions, management review, admin dashboard widgets/metrics, appraisal and management exports, pending acknowledgement/reminder recipients and relevant AI analytics/read paths.
- Kept all foreign/granted-company scope checks strict. Assignment and recorded-supervisor exceptions always require the Admin's original home company. No changes to `adminAppraiseeVisibilityWhereSql` (peer-admin privacy), existing supervisor role permissions, admin user-directory administration or profile writes.
- Kept all explicit search/department/staff type filters. Frontend has no secondary scope-based candidate/My Team filter: no frontend application changes needed.
- No new user-facing text, database changes, migrations, `.env`, or `includes/connection.php` changes.

## Verification executed

- Real data projection into SQLite **temporary tables** from the supplied MariaDB export: 485 users, 1,268 supervisor assignments, 1,018 appraisals. Executed the actual PHP-generated SQL scope predicates; 12/12 cycle-18 Local appraisees are visible for admin #6; an unassigned Local is hidden, recorded historical-supervisor appraisals remain visible, different-cycle assignment is checked, and forged granted-company assignment cannot bypass home scope.
- New PHP helper/route-wiring regression: 42 assertions passed.
- Existing read-scope contract updated to reflect the requested rule and passed.
- Existing standalone PHP test suites: **40/45 passed**. The remaining five **also failed on the pristine uploaded backend**, because they expect historical migration SQL files in `database/migration/` while this upload stores them in `database/migration/new/`. No migrations or out-of-scope tests were changed to mask these existing failures.
- PHP syntax: every changed PHP file passed.
- Frontend `node --test src/tests/*.test.js`: **88/88 passed**, with **no frontend changes**.
- Locale audit: 0 missing/extra en/fr keys.

## Still requiring environment verification

The local PHP has no `mysqli` extension or MariaDB service. We therefore **could not perform PHP routes end-to-end against a MariaDB copy**, including submitting real appraisals or dispatching actual reminder email. Before live release, run those end-to-end checks on a database copy using admin #6, the 12 assigned Local staff, a different unassigned Local, an All-scope admin, a supervisor-only account, and a strict foreign company grant; verify creation, form, update, submission, objectives, viewing, reminders, dashboard and exports.

## Deployment / rollback

1. Back up the deployed application code and use a database copy for acceptance testing first.
2. Copy only the changed `api/...` paths to the matching live `api/` paths. Do not replace the entire backend; deploy the new helper alongside all callers in a single maintenance window.
3. Clear any PHP opcode cache, then test the flows above before restoring access.
4. Roll back only the changed files from the code backup if needed; no database migration is part of Batch A.

Batch B (rating guide redesign) has **not** been started.
