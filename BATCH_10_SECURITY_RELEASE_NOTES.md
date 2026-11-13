# AppraisalSuite — Batch 10 Final Security, Regression and Deployment Report

**Review date:** 5 November 2026  
**Scope:** Cumulative source after Batches 2–9C, including cross-company access and proposed Lambert CIV (`LAMBERT_CIV`).  
**Release disposition:** **NOT CLEARED FOR PRODUCTION** until the staging MariaDB, actual Vite build and browser/end-to-end gates below pass. No database migration or production deployment was performed in this review.

## Executive conclusion

The submitted original backend/frontend archives and all Batch 2–9C change-only archives were reconstructed **in sequence**. The combined code contains **129 unique, registered file-backed API routes**, with every mapped route file present. The authorization design provides live server-side checks of an admin's role, activation state, company and active grant; role- and staff-type-based filtering; view-only write denials; company-specific peer-admin policies; notification opt-in and verified subjects; and audited exports. These properties were confirmed by source inspection and no-database tests **but are not yet proven against a real MariaDB installation or in-browser deployment**.

Four previously failing AI unit test fixtures were repaired in this final batch (migration location, expanded feature catalog, navigation shape, and revised AI historical-query mock). No production runtime logic was changed in Batch 10. A new final security regression test was added.

## Files delivered in Batch 10

* `api/tests/company_final_batch10_tests.php` — new pure-PHP security, route-inventory and migration invariants.
* `api/tests/ai_chat_history_wiring_tests.php` — corrects the historical migration fixture path; retains cascade assertion.
* `api/tests/ai_feature_settings_tests.php` — requires all six original/core switches while allowing newer supported features.
* `api/tests/ai_navigation_availability_tests.php` — verifies staff lack management insights while retaining the newer tools object.
* `api/tests/ai_timeframe_regression_tests.php` — modernizes the in-memory result mock (`fetch_row`) and verifies current prepared name-token query and 2024–2026 year ordering.
* `api/BATCH_10_SECURITY_RELEASE_NOTES.md` — this report within the backend ZIP.

**Frontend modifications:** None. Existing Batch 6, 7, 8B, 9B, and 9C frontend changes are preserved unchanged.

## Completed checks — actual execution in this environment

| Check | Outcome | Limits |
|---|---|---|
| Cumulative backend syntax (`php -l`) | **267/267 valid** | Syntax, not runtime database authorization |
| Full PHP no-database suites (`*_tests.php`) | **38/38 passed** | Includes repaired historical tests and new final security test |
| Full frontend Node tests | **46/46 passed** | Pure component wiring and helper tests; not browser rendering |
| JS/JSX parse | **242 files parsed; 0 errors** | TypeScript syntax parser, not Vite/Rollup compilation |
| Frontend reachable local imports | **176 reachable files; 0 missing relative imports** | Unused legacy modules have unresolvable imports; not reachable from the current entry files |
| Router inventory | **129 mapped routes, 0 duplicate paths, 0 missing target files** | File inventory; not HTTP endpoint execution |
| MariaDB integration tests | **21 invoked, 21 explicitly SKIPPED** | `mysqli` extension and dedicated MariaDB test database unavailable; exit 0 means SKIP, not PASS |
| Full Vite production build | **NOT RUN** | Installed npm dependencies unavailable; cannot certify bundle |
| Mobile/light/dark browser tests | **NOT RUN** | Browser execution not available |
| Live mail delivery / AI provider tests | **NOT RUN** | Avoided connecting to real services or sending mail |
| Live company creation, code `LAMBERT_CIV` | **NOT RUN** | Requires explicit Super Admin action after deployment |

### Previously failing suites and their disposition

All four failures were present in the Batch 8B and 9A baselines, before Batch 10. The test-only corrections retain substantive checks:

1. `ai_chat_history_wiring_tests.php`: migration 16 is at `database/migration/new/16 - ai_conversation_history.sql`, not the obsolete root location.
2. `ai_feature_settings_tests.php`: expected exactly six feature entries even though the code now ships additional switches. The revised test still requires the original six.
3. `ai_navigation_availability_tests.php`: expected the entire staff navigation object to contain one item despite later `tools` fields. It now explicitly denies the admin-only management feature.
4. `ai_timeframe_regression_tests.php`: the mock lacked the `fetch_row()` method used by the current guarded lookup and expected the old SQL statement order/name-token matching. It now verifies the current historical-year and prepared-parameter behavior.

## Security checklist

**Legend:** `STATIC/PURE PASS` = source checks and isolated tests passed, **not** database-integrated proof. `STAGING REQUIRED` = must execute before enabling cross-company access in production.

| Requirement | Review status | Required staging proof |
|---|---|---|
| Unauthorized admin cannot switch to ungranted company | STATIC/PURE PASS | HTTP 403 with crafted `view_company_id` on list, single, export, AI and mutation endpoints |
| Active grant is checked on each request; revocation takes effect next request | STATIC/PURE PASS | Revoke a live test grant and retry in same session; 403 and frontend home fallback |
| Deactivated / demoted admin loses foreign-company permissions | STATIC/PURE PASS | Change live test user's status and role, retry without re-login |
| View grant cannot perform administrative writes | STATIC/PURE PASS | Exercise all write endpoint families, including legacy direct URLs and CSRF |
| Manage grant can mutate in-scope staff, never out-of-scope staff | STATIC/PURE PASS | Local/Expatriate fixtures including staff, supervisors and admins |
| Staff scope applies to appraisees regardless of role | STATIC/PURE PASS | Appraisals, objectives, management review, exports, analytics, AI and search with cross-role fixtures |
| Home-company scope and Super Admin experience unchanged | STATIC/PURE PASS | Regression with existing appraisers, administrators and unrestricted Super Admin |
| Granted-company peer-admin default is none | STATIC/PURE PASS | No policy, `all`, and `selected` policies in two different companies; verify home policy independent |
| Admin's own and own-team supervisor actions remain record-company-bound | STATIC/PURE PASS | Submit, update, feedback, acknowledgement, objective lock/unlock with foreign View Context |
| Export allowed for view grantees, in scope and audited | STATIC/PURE PASS | Download Excel, inspect rows and audit record company/actor/home description |
| Personal inbox mark-read allowed for view grants, never other users' inbox | STATIC/PURE PASS | Attempt to mark another user's notification; verify 403/empty result |
| Granted notifications opt-in, staff scope, subject and peer privacy | STATIC/PURE PASS | On/off, revoke, scope changes, duplicates; test both in-app and mail |
| Company deletion/deactivation transactionally revokes grants | STATIC/PURE PASS | Revoke operation and audit rollback in DB fixture; verify no surviving active grant |
| Grant uniqueness and revoke/regrant permitted | STATIC/PURE PASS for DDL shape | Run migration 20 twice, prove duplicate active insert rejected and re-grant after revocation allowed |
| Per-company peer policy key and repeatability | STATIC/PURE PASS for DDL shape | Run migration 21 twice, compare old policy rows and new per-company rows |
| Notification metadata migration repeatability | STATIC/PURE PASS for DDL shape | Run migration 22 twice and exercise historic and new notifications |
| Setup copy excludes staff/appraisals/objective plans/staff-specific KPI records | STATIC/PURE PASS | Isolated clone and verify all excluded tables have zero target-company rows |
| Appraisal drafts, HSE review, idempotency, session and CSRF | STATIC/PURE PASS for central wiring | Real HTTP/browser regression, including double-submit and expired sessions |
| MS365/SMTP delivery, AI switches and budget, UI responsive themes | NOT CERTIFIED | Real staging environment and intentional external test accounts |

**Policy decisions to approve before rollout:** Existing design allows a `manage` grantee with `Local` or `Expatriate` scope to edit **company-wide** cycles, section settings, general questions and default KPI weights; staff-specific writes remain restricted. A staff-limited mail grant cannot use unrestricted custom/CC recipients. Audit Log in foreign companies shows the acting admin's own events only. These decisions were disclosed during earlier batches and are not silently changed here.

## Pre-deployment procedure (staging first)

1. **Backup and reproduce:** take a full database snapshot (including schema, stored routines and triggers) and separate backups of the deployed `api/` and frontend build. Restore the database to a disposable *nonproduction* staging instance. Ensure it does not send real notification email or call chargeable AI services during testing.
2. **Reconstruct the exact release:** start from the original backend and frontend archives. Apply every prior batch **in chronological order** (2, 3, 4A–4E, 5A–5E-2C, 6, 7, 8A, 8B, 9A, 9B, 9C), followed by the Batch 10 backend ZIP. These are *overlays*, not independently installable releases. Preserve existing `vendor/`, environment variables and live uploads.
3. **Provision dependencies:** use PHP 8.2-compatible extensions including `mysqli`, `mbstring`, `curl`, `openssl` and dependencies already used by the original app. Restore Composer dependencies from the application's verified lockfile. For frontend, run `npm ci` and `npm run build` using the supplied `package-lock.json`, with normal production build settings.
4. **Apply migrations on staging, in order**, with DB backups: `api/database/migration/20 - admin_company_access.sql`, `21 - admin_visibility_multi_company.sql`, then `22 - notification_subject_metadata.sql`. Inspect all three for compatibility with the real MariaDB version (the submitted dump reports MariaDB 10.4.28). Do not run legacy migrations again without their own separate review. **Migrations 21 and 22 perform DDL and require special care: MariaDB DDL may commit implicitly.**
5. **Run all pure tests:** `cd api && for t in tests/*_tests.php; do php "$t" || exit 1; done`; from frontend root, run `node --test src/tests/*.test.js`.
6. **Run database tests explicitly:** provision `APPRAISAL_TEST_DB_NAME` ending in `_test`, plus `APPRAISAL_TEST_DB_HOST`, `APPRAISAL_TEST_DB_USER`, `APPRAISAL_TEST_DB_PASSWORD` and optional `APPRAISAL_TEST_DB_PORT`, and inspect the fixtures before running `php api/tests/*_db_test.php` individually. **A printed `SKIP` is not success.** Some tests require seeded roles, a configured active appraisal cycle or their own temporary tables. Use only a disposable staging test database.
7. **Perform the security role matrix:** use two real test companies, admin users with `view` and `manage` grants, Local/Expatriate staff, supervisor/admin appraisees and an ungranted admin. Exercise all operations in the matrix above, including crafted API requests and mid-session revocations.
8. **Perform UI and operational regressions:** verify View Context switcher/banner on desktop and mobile, both themes; direct edit-page refresh, data resets, saved appraisal drafts, notification links and 403 recovery; email delivery switch; AI budget/switches; and exports. Test idempotency and CSRF explicitly.
9. **Create Lambert CIV only after staging passes:** log in as Super Admin, use Companies → approved Lambert CIV details (`Lambert CIV`, `LAMBERT_CIV`), create the active company, then preview and approve setup copying from a verified active source cycle. Confirm staff, appraisals, plans and staff-specific KPI records were **not** copied. Verify company branding in emails and AI/Analytics pages.
10. **Create the first grant:** in Users & Access → Company Access, select the relevant active admin (e.g., Reshma), select Lambert CIV, choose `View only`, `Expatriate`, notifications **off** by default, and supply a meaningful 5–500 character reason. Check switcher, in-scope reads, exports, policy defaults and revocation.
11. **Production cutover only after all gates pass:** take a fresh production backup; schedule a maintenance window; apply migrations first, then deploy backend and frontend artifacts as one compatible release; smoke-test read and write access for every role. Monitor authorization errors, audit records, email delivery and application logs.

### Rollback guidance

- If security checks fail before production cutover, **do not deploy**.
- If a live regression is discovered, disable cross-company use/revoke active grants, revert backend and frontend to the pre-release snapshots, and use the database backup/roll-forward strategy approved by the DBA.
- **Do not casually DROP** the new grant table or reverse migration 21's composite primary keys once multiple company-specific visibility policies exist; that can destroy legitimate records. Snapshot and reconcile affected rows with the DBA before any schema rollback.
- Do not assume reverting only frontend files revokes backend grants. Stop the new access at the server/database layer.

## Open blockers / final disposition

1. **Critical verification blocker:** All 21 database-specific suites were skipped due to unavailable `mysqli` and isolated MariaDB. This includes atomic grant audit operations, uniqueness, migration reruns, historical staff-type and peer-privacy SQL, notification subject linking, company deactivation and setup cloning.
2. **Critical build verification blocker:** `npm ci` / Vite production build and browser navigation were not executed in this environment.
3. **Operational gap:** `Lambert CIV` and code `LAMBERT_CIV` are approved and the creation workflow is prepared, but the company has **not** been created on the production database.
4. **Policy approvals:** Company-wide settings under staff-limited manage grants, staff-limited external mail restrictions, and foreign-company Audit Log self-only views require final acceptance.

**Conclusion:** Batch 10 source-level and isolated regression work is completed with all available non-database checks green. The release remains **NOT CLEARED FOR PRODUCTION** pending staging database integration, full build/browser tests, and approved policy decisions. This is a verification limitation, not a claim that any specific untested behavior is broken.
