# AppraisalSuite: inactive KPI selections in staff objective plans

## Root cause (verified from source)

- Admin KPI deletion intentionally **deactivates** a `kpi_questions` row if `staff_kpi_assignments` or `appraisal_kpi_responses` references it. This is necessary to preserve foreign keys and historical records.
- `ObjectivePlanHelpers.php::opSelectedObjectives` loads assigned questions without filtering `is_active`; `opAvailableObjectives` loads only active questions.
- `SaveObjectivePlan.php` previously rejected any positive selected question ID missing from the active availability list, even though it was already saved in that employee's draft plan. The generic error prevented locking and saving the draft.

## Snapshot facts (2026-11-16 14:37 SQL export)

- Company 1, cycle 18, KPI section 76, staff user 200 (Jamiu Olaniya Abdulhakeem): plan 223 is in draft.
- The plan selects question IDs 818 (TSU-01) and 819 (TSU-02), each an exact question-text/code/scope match for original question IDs 798 and 799, respectively.
- Those 818/819 IDs are referenced by staff assignments. The export records them as `is_active=1` at export time; a **later deactivation** is consistent with the screenshot error but cannot be proven from this earlier snapshot.
- No existing appraisal row was found for this staff user in this export. The fix does not rely on those identifiers and works for other employee plans as well.

## Fix

1. The API explicitly returns `is_active` with each selected assignment.
2. The editor identifies selected IDs missing from the active, staff-authorized library list, shows an explanation, and offers **Use active copy** only if there is exactly one active, not-already-selected question with identical code, wording and scope. The user must click; nothing is remapped automatically.
3. Replacement changes only the referenced question and its library metadata. Per-employee target, evidence and weight values are preserved.
4. The backend accepts **already assigned, deactivated** questions on **draft save only**, to avoid loss of in-progress work. It never permits adding an inactive ID and **continues rejecting any inactive/unavailable question on lock**. Existing authorization, profile, weight, minimum/maximum, target/evidence, transaction and post-submission update guards remain unchanged.
5. Removed/replaced selections are persisted only when the supervisor saves the draft or locks the plan. There is no blanket table update, automatic reactivation, FK cascade or database migration.

## Steps for the affected staff member

1. Update both the frontend and backend files supplied in this package.
2. Open the 2026 objective setup for the staff member. Unavailable TSU-01 and TSU-02 selections should be identified.
3. Click **Use active copy** for each one (where offered); verify the targets, evidence, and 100% total weights; then click **Lock Objectives**.
4. If no unique active copy is available, remove the unavailable question and choose a suitable active library objective manually; readjust weights if needed.
5. After staff selections no longer reference an old duplicate, an authorized admin can retry deleting it from the KPI library. If historical appraisals still reference it, it must remain inactive instead of being hard-deleted.

## Verification / limitations

- PHP syntax, JSX parsing, English/French key parity, frontend and focused backend static regressions passed in this environment.
- **Not verified:** an authenticated, live lock request against MariaDB 11.4; test on a staging DB copy before production.
- No database changes were performed; the supplied SQL export was read-only.
- This delta is compatible with the separately issued KPI Safe Deletion fix. Keep that earlier fix installed; do not revert its foreign-key protection.
