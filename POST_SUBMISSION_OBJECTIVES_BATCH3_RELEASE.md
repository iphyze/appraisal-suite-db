# AppraisalSuite — Post-submission objectives, Batch 3

**Status:** Code prepared and static/pure tests passed; NOT production-ready until Batches 4–5 and staging database verification.

## Scope

- `GetAppraisalFormData.php`: when `appraisals.objectives_changed_at` is set, an edited KPI section with a locked objective plan reads the current staff assignments and individual objective IDs. Existing ratings, comments, and actual results are carried forward by `kpi_question_id`; added IDs begin unrated; removed IDs are absent from the editable form. The response includes a required `objective_plan_signature` for changed-plan editing and `objective_change_summary.sections`, `added`, `removed`, and `changed` (before/after target, evidence and weight). An unlocked plan returns HTTP 409. With a clear flag, the original submitted snapshots remain authoritative.
- `AppraisalHelpers.php`: the existing scoring path permits changed KPI IDs **only** when the server-persisted changed flag is set and the section has an objective plan. An exact match to the current locked assignment IDs is mandatory. The server verifies the submitted text/target/evidence and custom weights against the locked plan and then uses current plan values instead of stale client or submitted snapshots. The existing section-weight snapshots, scoring profile, rating validation, general questions, HSE breach cap/review and old-edit snapshots remain unchanged. Only the appraisal update consumes an attempt.
- `UpdateAppraisal.php`: locks the appraisal and relevant objective plans in the same order as objective-save/unlock. Draft plans block updates. Rejects a missing/mismatched `objective_plan_signature` (HTTP 409), preventing stale open forms from submitting after further changes to IDs, text, targets or weights. Saves revised KPI rows, recalculates section and overall scores via the existing scoring helper, re-applies HSE cap, sets `Pending`, clears acknowledgement/feedback, increments `update_count` exactly once, clears the objective-change flag, and associates each outstanding unlock event with the new revision within a single transaction.
- `AppraisalObjectiveChanges.php`: pure change summary; locked-plan source; before/after KPI snapshots; append-only event consumption; revision-history projection with unlock reasons, original submitted snapshot, re-locked plan and newly rated KPI responses. Events retain a section reference even if the plan is subsequently removed.
- English/French API messages and content-translation paths for saved objective questions. Text entered by supervisors in reasons, targets, evidence and comments is not machine-translated.

## Migration order

1. Back up the staging database and restore a fresh copy of the relevant live data.
2. Run **31 - post_submission_objective_adjustments.sql** from Batch 2 in phpMyAdmin using `utf8mb4`, if not already run.
3. Run **32 - post_submission_objective_revision_evidence.sql** (this batch) after 31. This adds `rated_snapshot_json` and `objective_section_id` to `appraisal_objective_change_events`. `ADD COLUMN IF NOT EXISTS` makes it safe to run twice on MariaDB 11.4. Existing revisions and KPI responses are not changed by the SQL.
4. Apply Batch 2 and Batch 3 PHP changes together to staging. The new PHP depends on both migrations.
5. **Do not deploy to production until Batches 4–5 are complete** and the database-copy integration scenario passes.

## Tests run

- `php tests/PostSubmissionObjectiveBatch3Test.php` — **24/24 passed** (diffs, added/removed, targets/evidence/weights, same IDs, equal IDs irrespective of order).
- `php tests/PostSubmissionObjectiveBatch3WiringTest.php` — **30/30 passed** (eligibility, transaction and plan locks, HSE/score path, exact IDs, revisions).
- `php tests/PostSubmissionObjectiveBatch2Test.php` — **23/23 passed**.
- `php tests/PostSubmissionObjectiveBatch2WiringTest.php` — **39/39 passed** (updated to inspect the shared draft-plan lock helper).
- `php tests/admin_staff_scope_tests.php` — **43/43 passed**.
- `php tests/ContentTranslationHistoricalAppraisalsTest.php` — **46/46 passed**.
- HSE and company read-scope regression suites — **passed**.
- Existing frontend `node --test src/tests/*.js src/tests/*.mjs` — **102/102 passed** with a test-only copy of the previously supplied router (`index.php`) and the current `api/routes` available at the relative paths expected by the test. The latest supplied backend ZIP lacks `index.php`, so without this fixture one test reports a missing file; no router file is shipped here.
- `node scripts/i18n-report.mjs` — **0 missing/extra** keys in English/French.
- PHP syntax checks on 193 checked included backend PHP files — **passed**.
- `php tests/PostSubmissionObjectiveBatch3DatabaseCopyTest.php` — **not run**; this environment lacks `mysqli` and a MariaDB test database. The test refuses production DB names and rolls back changes.

## Required staging exercises (not yet verified)

- Submit appraisal > unlock with 5–500-character reason > add individual objective > remove one > change a target and weight > relock > request edit form > verify retained ratings and new empty rating > complete scores > update > verify one consumed attempt, `Pending` status, recalculated KPI/overall/HSE cap and clear change flag.
- Verify the new revision contains every unlock reason and complete before/after KPI rows; subsequent regular updates preserve the new frozen snapshot.
- Retry with invalid/missing rating, mismatched IDs, draft plan, exhausted allowance, removed assignment, unauthorized supervisor, admin own-team scope, Company Access grant and race between plan save and update. Rejections must leave the appraisal and audit association unmodified.
- Confirm management review/history, acknowledgements, exports/PDFs, existing drafts and French/English light/dark/mobile behavior. Frontend workflow and draft pruning are Batch 4; full integration is Batch 5.

## Rollback

Before release, make a full database backup. Because Batch 3 writes objective-change snapshots on new updates, a code-only rollback after users have already re-rated appraisals is **not** a safe way to reverse historical revisions. Restore a matching pre-release database backup as part of a coordinated rollback if required. Do not drop or modify the new migrations in place. Never replace server-specific `includes/connection.php` or `.env`.

## Remaining batches

- **Batch 4:** IMPORTANT: `AppraisalForm.jsx` must include the `objective_plan_signature` supplied by the edit-form API when posting a changed-plan update. Until Batch 4 does so, changed-plan updates are intentionally refused with HTTP 409. Add frontend actions, reason dialog, banners, plan-aware draft merge, responsive and accessible UI.
- **Batch 5:** database-copy end-to-end regression, production build, authenticated browser checks and release notes.

**Open issues:** No new business rule needed. MariaDB-copy and frontend integration testing still required. The deployed `api/index.php` was absent from the uploaded backend ZIP and must be verified on staging.
