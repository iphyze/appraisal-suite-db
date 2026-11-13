# AppraisalSuite — Batch 7: consolidated release and verification gate

**Date:** 9 November 2026  
**Status:** Release candidate prepared; **NOT production-certified or deployed**.  
**Source:** latest user-supplied MariaDB SQL dump (485 `users` memberships), Part 1 and Part 2 changes, and the staged Batch 2–6 code.

## 1. What Batch 7 actually completed

- Reviewed the staged Batch 2–6 ZIPs in order. There are 66 distinct changed/added *code* paths across those ZIPs (two early Batch 2 test files do not occur in the subsequent working-tree copy but are included in the cumulative distribution). Where files overlap, the latest batch wins. No divergent overwrites were detected between the final staged working tree and the previous batch packages.
- Re-analysed the latest SQL export: **485 memberships**, **296 unique normalized nonempty emails**, **177 membership rows without email**, **12 email groups across companies** (24 memberships). The migration 30 forecast remains **294 identity rows and 304 linked memberships** with four deferred records.
- Fixed a newly discovered cross-company deletion regression in `api/routes/users/deleteUsers.php`: deleting the last company membership now also deletes the unused login identity *inside the same transaction*, while deleting one of several memberships retains the shared identity. The route locks the relevant identity and membership rows in a consistent order and rechecks the membership links before deletion. This prevents a later user created with the same email from accidentally inheriting an orphaned login/password. A focused structural regression test checks this route; real DB concurrency testing remains required.
- Created a **read-only PHP database-copy release gate**, `api/tests/user_identity_batch7_copy_verification.php`, which refuses non-`_copy` database names, verifies the active database and `utf8mb4` connection, and checks schema, mapping, normalization, legacy dual-write consistency, ten expected linked pairs and four deferred records.
- Created a **plain read-only SQL verification report**, `api/tests/user_identity_batch7_release_checks.sql`, for use directly in phpMyAdmin on a database copy after migration 30.
- Re-ran all **102 frontend Node tests**, focused identity security tests and PHP lint across the staged backend; added a focused Batch 7 deletion-route static test. Completed a key-for-key English/French locale audit.
- Prepared the deployment and rollback sequence below. **No changes were made to production, to the SQL dump, to old migrations, or to server-specific files**. No server-specific `includes/connection.php` or `.env` files are distributed.

## 2. Identity reconciliation — all 12 shared-email groups

The 2026-11-09 source dump contains the following cross-company pairs. The ten auto-linked groups are each one identity with two separate existing `users.id` membership records; **names, roles, appraisal records and membership activation status remain per company**.

| Email | Membership user IDs and companies | Backfill status |
|---|---|---|
| a.busari@lambertelectromec.com | 135 Lambert / 365 Lem Maintenance | Auto-link |
| a.lawal@lambertelectromec.com | 218 Lambert / 391 Lem Maintenance | Auto-link (both memberships inactive in source) |
| b.ali@lambertelectromec.com | 125 Lambert / 455 LEM CIV | Auto-link |
| c.okonkwo@lambertelectromec.com | 255 Lambert / 404 Lem Maintenance | Auto-link (both memberships inactive in source) |
| h.abinassif@lambertelectromec.com | 445 Lambert / 485 LEM CIV | **Manual review: unlinked** |
| h.eldine@lambertelectromec.com | 444 Lambert / 451 Lem Maintenance | **Manual review: unlinked** |
| k.elmouslemani@lambertelectromec.com | 211 Lambert / 344 Lem Maintenance | Auto-link (both memberships inactive in source) |
| l.habchi@lambertelectromec.com | 446 Lambert / 454 LEM CIV | Auto-link |
| m.abdulhaq@lambertelectromec.com | 229 Lambert / 346 Lem Maintenance | Auto-link |
| n.ibrahim@lambertelectromec.com | 24 Lambert / 347 Lem Maintenance | Auto-link |
| p.almawi@lambertelectromec.com | 29 Lambert / 452 LEM CIV | Auto-link |
| r.mikhael@lambertelectromec.com | 299 Lambert / 453 LEM CIV | Auto-link |

**Review queue:** The Hussein Abi Nassif and Hussein Shams El Dine records have name-format variations and **different password hashes** with no distinguishing password-change or last-login timestamps. They may refer to the same people but require independent personnel confirmation. They deliberately remain four legacy memberships with independent logins; their accounts must not be automatically joined. Do not edit migration 30 to bypass this review. When approved, create a separately reviewed, numbered follow-up migration and verify both users' intended access.

The reported 12 groups include inactive memberships; the Switch Workspace UI must only offer **active user memberships in active companies**. A person with two database memberships is not automatically entitled to two currently available workspaces.

## 3. Verification already run (no live database)

| Verification | Result |
|---|---|
| `node --test src/tests/*.test.js src/tests/*.test.mjs` | **102/102 passed** from the combined Batch 6 working tree |
| Focused PHP tests for Batches 3–6 (session, authorization, workspace, static rules) | All six scripts exited successfully |
| `php -l` across staged backend | **333 PHP files passed** |
| Recursive English/French JSON key comparison | **0 missing / extra keys**, all JSON valid |
| Schema-name and sample-membership audit against supplied SQL dump | Passed as a **source-file analysis**, not executed MariaDB SQL |
| Batch 7 deletion-route static regression | **14/14 checks passed** (source structure only) |
| Migration 30 executed on MariaDB | **NOT RUN** — no MariaDB service or mysqli extension in this environment |
| Batch 2–6 rollback-only DB integration tests | **NOT RUN** — same blocker |
| Second migration execution and no-op verification | **NOT RUN** — needs test database copy |
| `npm run build` / Vite | **NOT RUN** — installed dependency tree lacks Vite executable |
| Browser visual, responsive, CSRF, email, AI and appraisal smoke tests | **NOT RUN** — need running staging app and services |

**Do not equate static tests with production validation. This is a staged release candidate, not a production release approval.**

## 4. Mandatory database-copy verification — stop on any failure

1. Create a fresh, isolated MariaDB 11.4 **`*_copy`** database from the latest protected backup. Never use the live DB for first execution. Ensure the copy's server-side `includes/connection.php` uses `mysqli_set_charset($conn, 'utf8mb4');`; keep the server-specific file out of the ZIP.
2. Ensure Part 1's migration 29 was applied to source data where required, and the connection setting and repair were deployed together. Ensure Part 2's language-choice code is present; Batch 3–6 builds upon it.
3. Run **`api/database/migration/30 - user_identities_and_memberships.sql`** in phpMyAdmin on the **copy**, using an `utf8mb4` phpMyAdmin session. Check the migration's *before* and *after* SELECTs. Expected fresh-source figures: 294 identities, 304 linked, 4 unlinked email memberships, 177 no-email memberships, 0 mismatches.
4. Run the supplied `api/tests/user_identity_batch7_release_checks.sql` on the copy and check **all counts**. If your source dump has changed, update the expectations from the latest data rather than treating the old 2026-11-09 totals as universal.
5. From the `api/` directory on the **test** server, set `APPRAISAL_IDENTITY_TEST_COPY_DB=YOUR_DATABASE_copy` and run `php tests/user_identity_batch7_copy_verification.php`. It must report zero failures. Never copy `connection.php` into a release package.
6. Execute the database integration tests from **Batches 2–6**, honoring each test's `_copy` guard and rollback-only mode. Confirm no test writes persist.
7. On a **disposable copy**, run migration 30 **a second time**. Confirm counts are unchanged and every linked record still matches its identity; record the before-and-after output. Do not re-run an already deployed production migration just to perform this test.
8. Conduct real concurrent reset/create, email-link/rename, permission-revocation, company-deactivation and workspace-switch tests on staging. Confirm no cross-company data leak or stale JWT acceptance.

## 5. Mandatory functional/browser regression matrix

Use an isolated **staging** instance with copies of real role and company configurations. At minimum test both **English and French**, desktop/mobile width, light/dark themes where the UI uses them:

- **Authentication:** new single-company login, multiple-company selection, last workspace remembered, wrong credentials, normalized-email rate limit, inactive identity, inactive membership, inactive company, normal sign-out, sign-out-everywhere, expired/revoked sessions, cookie and CSRF enforcement.
- **Passwords:** forced first sign-in change, voluntary change from password screen and profile, wrong current password handling, repeat submissions, new JWT issued and old JWT revoked, administrator reset with every-company permission, denial naming another company, administrator peer privacy, all-company audit entries.
- **Personal workspace switch:** own active membership accepted; other identity's user ID rejected 403; inactive membership/company rejected; CSRF rotated; old cache cleared; user-scoped saved appraisal drafts remain intact; repeated quick switches; network-loss session reconciliation.
- **Company Access/View Context:** Super Admin continues to view across companies; granted administrators view/manage only within their grant, staff scope and active company; personal workspace change never becomes a Company Access grant; admin peer privacy preserved.
- **User management:** create new identity, add membership to existing identity without password field, no-email staff row, edit member role and activation, identity-level email rename, required confirmation before linking two identities, same-company conflict refusal, inactive identity Super-Admin-only controls, visible-only “Also in” badge, reviewer-protected emails. **Deletion regression:** deleting one of two memberships retains the person's identity and other workspace; deleting the final membership removes the orphaned identity; an FK-protected historical record still refuses deletion; concurrent create/delete never leaves an unowned credential.
- **Appraisal/data isolation:** drafts, assignments, objective plans, management review, notifications, export/report filters, AI history, audit trails and saved results; verify each `users.id` still controls its own company records.
- **Email and localization:** Email Delivery and notifications, onboarding/reset emails, shared login language across workspaces, company-default language fallback for NULL, one-time first-login language dialog, English/French locale key synchronization.
- **UI:** browser refresh/back, profile menu, dialogs, keyboard focus and Escape, responsive phone layout, light/dark readability and duplicate-submission guard.

Automated tests above do **not** establish these browser/service results. Record screenshots, HTTP responses and test IDs in the staging signoff.

## 6. Coordinated production deployment (ONLY after signed-off gates)

1. Announce a scheduled maintenance window. Stop new sign-ins and app writes; prevent background jobs/cron from writing during schema and code cutover. Back up the **live database**, **live code**, and deployment configuration separately. Verify the backups can be restored.
2. Check Part 1 is already complete: connection charset is `utf8mb4` and migration 29 repaired the relevant legacy text together. Ensure Part 2 is installed or include it in the wider release plan; the Batches 2–7 cumulative package does **not** replace those earlier packages.
3. Validate the current source data has not diverged from the 2026-11-09 backfill report. If people have been created, renamed, or passwords changed since the supplied export, retest the *new* snapshot. Resolve unknown person-identity collisions before any automatic link.
4. With writes stopped, run migration 30 against the backed-up **live** DB using phpMyAdmin's `utf8mb4` connection. Check after-run diagnostics **before** enabling login traffic.
5. Deploy the **single cumulative** Batches 2–7 package (`api/…` and `src/…`) rather than deploying the five intermediate ZIPs separately. Keep existing `includes/connection.php` and `.env` on the server. Run frontend `npm ci` and `npm run build` with a working Node/Vite installation and deploy the built app in the established manner.
6. Verify all routes are registered; validate one login, one linked multi-company login, an unlinked review-queue login, forced password change, password reset, profile locale save, workspace switch, Company Access, a saved appraisal draft and one Email Delivery notification.
7. Re-enable application writes only after health checks pass. Log the release window, versions, migration outputs and approvals; continue watching PHP logs, login failures and permission-denied rates during rollout.

**Expected user-visible change:** for the ten linked groups (subject to active memberships), one password and a personal Switch Workspace menu instead of needing to sign out; the two deferred groups remain on their legacy separate-credential path until manually reviewed. Normal staff with one membership sign in as before. Company roles and appraisal history are not merged.

## 7. Rollback limitations — read before deployment

- Stop writes and take a fresh incident backup before any rollback.
- Rolling back **code only** to the pre-identity release is possible because Phase 1 keeps `users.password_hash`, `must_change_password`, `token_version`, `last_login_at` and `preferred_locale` synchronized for every linked identity. It may require users to sign in again. **Do not drop `user_identities` or `users.identity_id` as part of an automatic code rollback**.
- Explicit identity mergers, account-email renames and links performed after release are person-data decisions; code rollback cannot reconstruct two different original passwords or restore all previous email-account boundaries. If such changes must be undone, use a controlled per-person reverse process or restore a separately verified pre-deployment backup, carefully accounting for subsequent appraisal and audit data.
- A **full database restore** rolls back post-backup writes; it is an emergency operation requiring data-loss assessment and management approval, not a routine version downgrade.
- Do not remove legacy auth columns in Phase 1. That would destroy the agreed code rollback path.

## 8. Remaining gates and decisions

**Known inherited security concern:** `routes/users/ResetPassword.php` currently generates a predictable annual temporary password (`Lambert@` plus the year), following the existing reset behavior. Shared identities increase its impact because the temporary password affects all linked workspaces. This behavior was not silently changed in Batch 7, to avoid an unapproved password-policy and notification change. **Review and approve a secure one-time random reset credential or reset-link flow before production release.**

**Before any production deployment:** (a) actual MariaDB copy execution of migration and integration tests, including a second-run no-op; (b) production Vite build; (c) English/French responsive and service integration testing; (d) refreshed live snapshot diff and written deployment/rollback authorization. **Personnel follow-up:** confirm whether the two deferred email pairs are the same people and obtain explicit instruction for separate linking; their exclusion does not prevent staging of the ten verified pairs.

No Batch 8 feature work is planned in the original scope. The next step after this staged Batch 7 package is **running the outstanding release gates on staging**, not adding new features or deploying to production blindly.
