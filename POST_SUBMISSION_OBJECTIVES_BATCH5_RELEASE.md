# AppraisalSuite — Submitted-objective adjustments, Batch 5 release dossier

**Status: code consolidated for staging; production release NOT approved.** The database-copy end-to-end exercise and authenticated responsive browser checks cannot be run in the current build environment. These are hard release gates, not optional checks.

## Scope and file precedence

The cumulative package includes **only files changed or added in objective batches 2, 3, 4 and 5**, under `backend/api/…` and `frontend/src/…`. Later batch versions take precedence over earlier versions for overlapping paths. Keep previous AppraisalSuite updates (including the admin-own-team staff-scope fix) in place. No old migrations have been changed and no `includes/connection.php` or `.env` is shipped.

Features: one authorization helper; reasoned post-submission unlock; existing plan validation; audit events; `objectives_changed_at` marker; current locked-plan re-rating with a server signature; exact question-ID validation; matched old ratings/comments/results; replaced KPI responses; existing scoring/weights and HSE score cap; one consumed update; new revision and unlock reasons; renewed employee acknowledgement; plan-aware draft merge; English/French UI.

## Required coordinated staging procedure

1. **Back up the full database and application files.** Restore a **fresh, isolated MariaDB 11.4 database copy**. Use separate credentials, disable real email delivery in staging, and never use production cookies or customer notifications during tests.
2. Make sure the earlier installed AppraisalSuite codebase and migrations are in place. In phpMyAdmin, using a `utf8mb4` connection, execute exactly in this order:
   - `api/database/migration/31 - post_submission_objective_adjustments.sql`
   - `api/database/migration/32 - post_submission_objective_revision_evidence.sql`
   Both migrations are plain SQL, MariaDB-specific and repeatable. Run each twice **on the copy** and confirm no data duplication or schema errors. They intentionally do **not** modify existing appraisal responses or revision records.
3. Copy `backend/api/…` contents into the staging backend's `api/` root, and `frontend/src/…` into the staging frontend's `src/`. Do not replace server `includes/connection.php`/`.env`. The latest uploaded backend ZIP did **not** contain `index.php`; verify the staging router already routes `/objective-plans/unlock`, `/objective-plans/save`, `/appraisals/get-form-data`, `/appraisals/update`, and the existing read routes to the correct PHP files. Do not substitute an older router in production.
4. Install frontend dependencies from the project's lockfile, then run `npm run build`. Verify the compiled app loads without console errors. Run the PHP syntax and unit regressions below.
5. Test the **entire flow** below using an otherwise disposable 2026 appraisal with an available update attempt and an already locked plan. Record the appraisal ID, supervisor, staff and baseline `update_count` **before** editing.

## Mandatory end-to-end acceptance sequence (on the staging copy)

| Stage | UI/HTTP action | Required evidence |
|---|---|---|
| 1 | Create/submit a test appraisal with a locked plan (or pick a disposable, already submitted record). | Saved KPI rows and scores; `status='Pending'`; original update count; no objective-change marker |
| 2 | Supervisor opens Adjust objectives and unlocks with a **5–500 character reason**. | Reason in `appraisal_objective_change_events` and normal audit log; update count **unchanged** |
| 3 | Edit current objectives: add one individual objective, remove one, modify a retained objective's target and weight; re-balance weights to the configured total; lock. | Plan is `locked`; marker non-NULL; audit event has before and re-locked snapshot; update count unchanged |
| 4 | Open Update appraisal. | Removed question absent; retained question rating/comment/actual preserved; new question unrated; added/removed/changed summary and banner accurate; new plan signature present |
| 5 | Verify an un-rated new objective is rejected. Then rate it and submit. | Invalid form cannot submit; successful update increments `update_count` by **exactly one**, recalculates KPI and overall scores, clears marker, sets `Pending`, clears acknowledgement; current question IDs equal the locked plan; revision captures before/after and every unlock reason |
| 6 | Open View appraisal and revision history. | Both versions and reasons visible; PDF/export remains correct; no removed objective enters the new score |
| 7 | Employee acknowledges updated appraisal. | Status becomes `Acknowledged`; the historical revision remains intact |
| 8 | Exhaust all update attempts on disposable records. Attempt another unlock. | HTTP 403; no objective edits; action disabled with translated reason. Administrator restores allowance using existing feature; eligible supervisor can unlock again |

**Additional denials and regressions:** non-conducting supervisor, revoked/changed cycle assignment, onboarding not completed, exhausted allowance, inactive/cross-company/granted-company context, admin acting on own assigned appraisee, unlock reason missing/too short/too long, plan still `draft`, stale plan signature, concurrent open edit form/save, management-review data and HSE breach review/score cap. All denials must leave appraisal score, revision, audit association and `update_count` unchanged. Check appraisals created without objective plans still edit from their frozen snapshots.

**Saved-progress drafts:** start an appraisal edit, save a draft, then alter the locked plan. Reopen: locally entered non-objective comments and retained objective progress should persist, but removed custom objectives, stale targets/weights and old objective IDs must not reappear. Confirm normal pre-submission drafts still preserve custom questions.

**Browsers and accessibility:** test English/French at desktop (1440 px), tablet (820 px), phone (390 px and 340 px); light/dark themes; visible focus, keyboard-only unlocking, dialog focus behavior, screen-reader labels, disabled-action explanation, and no horizontal scrolling.

## Read-only database-copy verifier

A *read-only* post-action verifier is provided at `api/tests/PostSubmissionObjectiveBatch5DatabaseCopyVerification.php`. It cannot perform the authenticated UI operations; run it **after each stage** above. It has an explicit opt-in, refuses database names not ending `_test`, `_copy` or `_staging`, requires an appraisal ID and baseline update count, and uses a read-only transaction. Use a read-only database account as an additional protection.

```bash
APPRAISAL_TEST_ALLOW_COPY=1 \
APPRAISAL_TEST_DB_HOST=127.0.0.1 \
APPRAISAL_TEST_DB_NAME=appraisalsuite_copy \
APPRAISAL_TEST_DB_USER=read_only_user \
APPRAISAL_TEST_DB_PASSWORD='FROM_STAGING_SECRET_STORE' \
APPRAISAL_TEST_APPRAISAL_ID=123 \
APPRAISAL_TEST_INITIAL_UPDATE_COUNT=0 \
APPRAISAL_TEST_PHASE=rerated \
APPRAISAL_TEST_ADDED_QUESTION_ID=1234 \
APPRAISAL_TEST_REMOVED_QUESTION_ID=1235 \
APPRAISAL_TEST_RETAINED_QUESTION_ID=1236 \
php api/tests/PostSubmissionObjectiveBatch5DatabaseCopyVerification.php
```

Valid phases: `submitted`, `changed`, `rerated`, `acknowledged`, `exhausted`, `restored`. Record the outputs with staging test evidence. The verifier checks schema, updated state, exact current locked-plan IDs, revision linkage, preserved unlock reasons and snapshots, and optional ID membership and independently calculated expected scores (APPRAISAL_TEST_EXPECTED_KPI_RATING / APPRAISAL_TEST_EXPECTED_OVERALL_SCORE). **The tests do not replace the API/UI exercise or a manual scoring calculation.**

## Local regression results (13 November 2026)

- Backend non-database test scripts: **77/77 passed** after a **test-only** mapping of existing migration files from `database/migration/new/` to historical locations expected by seven legacy tests; those mappings are **not shipped**. Without test-only mapping: 70 passed / 7 failed due to migration-path fixtures.
- Existing frontend Node test suite: **110/110 passed** with a **test-only `backend/index.php`** copied from an older uploaded archive to satisfy a historical test; `index.php` is **not shipped or modified**. Without this router fixture: 109/110 passed.
- PHP syntax: **348 PHP files passed** before the Batch 5 verifier addition; both new Batch 5 test files also pass PHP syntax checks.
- Focused Batch 2: 23 logic assertions / 39 wiring checks passed. Focused Batch 3: 24 diff assertions / 30 wiring checks passed. Admin-own-team helper: 43 assertions passed. Historical translation: 46 assertions passed. HSE, staff-scope and Company Access tests passed.
- i18n report: **0 missing or extra keys** in en/fr.
- `npm ci` and `npm run build`: **NOT VERIFIED** because the npm registry DNS failed (`EAI_AGAIN`) and Vite is not installed. These failures are environmental, not successful builds.
- MariaDB-copy integration and authenticated browser screenshots: **NOT RUN** (no MariaDB server or PHP `mysqli` extension accessible here). Do not claim the end-to-end workflow has passed.

## Go / no-go gate

**Do not deploy** until migrations 31/32 have been tested twice against a restored copy, all read-only post-action assertions and the full real workflow above pass, production build succeeds, and English/French responsive browser checks are approved. Any failures should be corrected in an explicitly reviewed follow-up rather than bypassed.

## Production maintenance (only after sign-off)

1. Schedule maintenance and stop writes; take a **verified full database backup and frontend/backend file snapshot**.
2. Execute migrations 31 then 32 on production using phpMyAdmin `utf8mb4`; confirm schema changes. They do not alter existing appraisals.
3. Publish **both** updated backend and built frontend atomically. Keep the existing router, connection settings and CSRF/session configuration.
4. Run a non-destructive authorized smoke test: login, appraisal list, objective-plan read, appraisal view, ratings, draft load, emails disabled for any test sends. Resume writes only after checks pass.

## Rollback

- **Before any post-submission objective edit:** restore the saved pre-release application files and keep the additive columns/table in place. Do not attempt to hand-edit old migrations or delete audit records. A database restore can also return to the old schema if required.
- **After any post-submission change has been unlocked, re-locked or re-rated:** coordinate a **matching pre-release database backup and code snapshot restore** in a maintenance window. Restoring only old PHP or dropping the new columns would lose or misinterpret historical KPI revisions. Preserve post-cutover records for reconciliation before any restore.
- **Never** ship/overwrite `includes/connection.php` or `.env`. Preserve prior identity, locale, Company Access, HSE and admin-own-team changes.

## Remaining work after this prepared Batch 5 deliverable

The code and staging package can be handed over now, but production approval depends on: MariaDB-copy execution and repeat-migration test; authenticated end-to-end scenario and negative tests; build; browser light/dark EN/FR checks; and deployment sign-off. No additional feature batch was part of the original requested scope.
