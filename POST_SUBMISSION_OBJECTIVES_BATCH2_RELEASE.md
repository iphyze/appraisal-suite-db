# AppraisalSuite — Post-submission Objectives, Batch 2 (backend)

**Staging only. Do not deploy separately.** Batch 3 must add re-rating, and Batch 4 must add the reason dialog and post-submission UI before enabling this feature for users.

## Changes

- `includes/AppraisalUpdateEligibility.php` is the single policy for existing appraisal updates (conducting supervisor, same-cycle assignment, onboarding, admin home-company staff scope including own appraisees, and the current `2 + extra_update_allowance` limit). It intentionally delegates HSE declaration validation to the existing update route because that validation depends on the submitted payload. No management-review prohibition was added.
- `UpdateAppraisal.php` invokes this policy before saving and again on the `appraisals` row locked with `FOR UPDATE`. Existing scoring/revision/update-count/HSE code is unchanged. As an interim safeguard, it refuses to update while an objective plan is unlocked or its saved KPI snapshot is stale; Batch 3 replaces this safeguard with re-rating.
- `UnlockObjectivePlan.php` requires a valid UTF-8 reason of 5–500 characters **only after submission** and writes it to the existing audit log and an append-only change event. Its transaction locks the appraisal row before the objective plan.
- `SaveObjectivePlan.php` permits the conducting supervisor to save/relock a post-submission draft only while updates remain, rechecks the same eligibility under lock, preserves current validations and does not allow changing the scoring profile. It compares objective IDs, targets, evidence and weights against the last submitted KPI responses, not timestamps. It sets `objectives_changed_at` only when the locked set differs, and clears the marker if the objective set returns to the submitted version.
- Objective plan, appraisal form, candidates, appraisal list and appraisal detail responses expose `can_adjust_objectives`, `can_update_appraisal`, `updates_left`, `reason_key`, and `objectives_changed_since_submission`, as applicable. The list/detail APIs preserve the existing `can_manage_appraisal` flag for older consumers; Batch 4 will use the new exact permission flags.
- New error keys have synchronized English/French translations in `src/locales/*/api.json`.

## Database migration

Run `database/migration/31 - post_submission_objective_adjustments.sql` **on a database copy first**. It is plain SQL for MariaDB 11.4/phpMyAdmin and can be re-run. It adds:

- `appraisals.objectives_changed_at` (`DATETIME NULL`), a re-rating marker, **not** a timestamp comparison.
- `appraisal_objective_change_events`, with immutable unlock reasons, actor, appraisal/plan identifiers, before/locked snapshots and a nullable future `consumed_revision_no` for associating all pending reasons with the next revision in Batch 3.

Neither migration 29 nor migration 30 was changed. Existing saved responses, appraisal revisions, assignments and update counters are not modified by migration 31.

## Request shape

The existing objective unlock endpoint accepts one additional field after submission:

```json
{"staff_user_id": 123, "section_id": 76, "reason": "Targets must reflect the revised delivery schedule."}
```

The existing save/lock endpoint keeps its current shape. The reason is required **only** for an already submitted appraisal. The frontend UI for entering it is scheduled for Batch 4.

## Testing

Local checks:

- `php tests/PostSubmissionObjectiveBatch2Test.php` — 23 assertions passed.
- `php tests/PostSubmissionObjectiveBatch2WiringTest.php` — 39 checks passed.
- `php tests/admin_staff_scope_tests.php` — 43 checks passed, with one structural test adjusted to inspect the new shared helper.
- `php tests/admin_staff_scope_followup_tests.php` — passed.
- `php tests/company_read_scope_tests.php` — passed.
- `php tests/appraisal_breach_regression.php` — passed.
- `php -l` for changed PHP files — passed.
- `node scripts/i18n-report.mjs` — zero missing/extra en/fr keys.
- Frontend existing tests: **101 passed, 1 unrun application assertion** because the uploaded backend archive omitted `index.php`, which the frontend test tries to read; this failure was documented in Batch 1.

**Not verified:** MariaDB integration, actual endpoint HTTP operations, browser tests and production build. This runtime has no MariaDB server or PHP `mysqli` extension. The optional `tests/PostSubmissionObjectiveBatch2DatabaseCopyTest.php` is included; it demands a disposable database name ending in `_copy`, `_test` or `_staging` and explicit environment opt-in, creates only rolled-back test records, and verifies the migration and no-consumed-update invariant.

## Pending for Batch 3

- Render current locked objectives into edit-form KPI section when `objectives_changed_at` is set. Retain ratings/comments/actuals for common IDs; new IDs unrated; removed IDs omitted from current form.
- Validate exact new KPI set, recalculate scoring, preserve old/new per-objective evidence with all unconsumed unlock reasons in the revision, clear marker and consume one update; retain HSE cap, acknowledgement reset, review and notification behavior.
- Ensure a still-unlocked plan cannot be updated.

## Pending for Batch 4 and 5

- New post-submission objective actions, reason dialog, notices, direct re-rate button, draft pruning and en/fr responsive UI.
- Staging database copy end-to-end testing, production build, exports/PDFs, saved progress and full regression; prepare coordinated deployment/rollback.

## Deployment warning

Do **not** apply migration 31 or this partial code in production until the remaining batches are completed and integration-tested. Revert the PHP/frontend code from a deployment snapshot if staging checks fail; reversing schema changes should be planned separately with a database backup. Never include `.env` or `includes/connection.php` in a release ZIP.
