# AppraisalSuite — Batch 6: Managing People Across Companies

## Scope (staging only)

This batch builds on identity migration 30 and Batches 3–5. **Do not deploy this delta on its own.** The live installation must continue using the existing routes until the coordinated Batch 7 database-copy regression and release.

- Creating a user whose normalized email belongs to an existing `user_identities` row now reuses that login, password, must-change flag, locale and session version. No new password is requested for that membership. New email identities get their own login and an initial password. No-email staff rows remain unlinked and receive an unpredictable, non-login hash in the mandatory legacy column.
- The server rejects any direct/accidental automatic linking of the two deferred email groups or any email with unlinked memberships; personnel verification is still required. This includes changes *from* the two deferred addresses.
- Editing a linked email updates the identity plus all company membership email fields atomically, and revokes old sessions. An email already held by another identity triggers `IDENTITY_LINK_CONFIRM_REQUIRED` rather than silently joining credentials. Confirmed linking checks the managing administrator's permissions for **all active memberships on both sides**, refuses same-company conflicts, audits every affected company, and revokes sessions on the destination identity. Review the two people's identities before confirming; linking changes their password to the destination login's password.
- Adding an email to a previously email-less user with no existing identity creates a login with a random, unknown password. The administrator **must perform a password reset** before that person can sign in. If the email already belongs to another login, linking requires confirmation.
- Deactivation of a company membership still affects only that `users.is_active`; identity-level activation/deactivation is a distinct Super-Admin-only endpoint and controls login access across memberships without changing company flags.
- The Users list and View User page show "Also in" for other *active* memberships in *active* companies that the viewer can see under existing company grants and staff scope. The API does not disclose identity IDs or unseen company information.
- Preferred locale is already read and dual-written through the identity (Batch 3), and this batch makes personal profile and outgoing email locale reads prefer the identity value explicitly. NULL continues to fall back to the company's default.
- The legacy unregistered `routes/auth/register.php` writer is disabled (410). The single supported authenticated, CSRF-protected creation route is `/users/create`. No existing "added to company" email notification exists in the inspected user-creation route, so no new notification was invented.

## Files

Only this batch's additions and modifications are packaged, under `api/` and `src/`. There are no new database migrations. No `.env` or `includes/connection.php` is included.

## Testing completed locally

- `node --test src/tests/*.test.js src/tests/*.test.mjs` — **102 passed, 0 failed** (with the existing test harness's expected backend sibling path).
- `php api/tests/user_identity_batch6_rules_test.php` — **15 passed**.
- `php api/tests/user_identity_batch6_static_test.php` — **17 passed**.
- `php -l` on all changed/added PHP files — passed.
- TypeScript's TSX parser on modified React/JS files — passed.
- English/French `users.json` keys — **0 missing**.

The development environment has **no PHP `mysqli` extension / MariaDB service** and lacks a working Vite executable. A real migration/backfill, database-copy integration test, production frontend build and browser testing are **not verified**.

## Required database-copy validation before release

Apply migration 30 on a fresh, backed-up database copy with phpMyAdmin UTF-8 connection. For the approved copy, configure the existing `api/includes/connection.php` appropriately (not supplied in this package), then run:

```shell
APPRAISAL_IDENTITY_TEST_COPY_DB=appraisalsuite_identity_copy php tests/user_identity_batch6_db_test.php --rollback-only
```

The integration test refuses any database not ending `_copy` and rolls back all writes. Run existing copy-only tests from Batches 2, 3, 4 and 5, plus full cross-company permission and user-lifecycle tests. Check simultaneous password reset vs. membership create, duplicate email collisions, identity rename, explicit login linking, role and grant revocation races, and inactive identity/membership/company enforcement. Verify the second run of migration 30 is a no-op.

## Outstanding / Batch 7

1. Personnel confirmation for `h.abinassif@lambertelectromec.com` and `h.eldine@lambertelectromec.com`. **Neither is automatically linked in this batch.** Do not modify migration 30.
2. Run copy-only MariaDB integration and verify no unintended identities, links, orphan memberships or legacy dual-write mismatches.
3. Run production Vite build and English/French browser testing (light/dark, mobile/desktop) for create/edit, confirmation dialogs, identity status, list badges, shared computers, login, forced password change and switching.
4. Full regression on Company Access, Super Admin permissions/View Context, admin peer privacy, staff scope, appraisals and drafts, objective plans, notifications/Email Delivery, AI, CSRF, rate limits and duplicate-submission guard.
5. Coordinate code + schema deployment in one maintenance window with backup, verification checklist and rollback. The legacy columns remain for Phase 1 rollback. However, *intentional identity mergers and email renames* may not be reversible to distinct old passwords without database restore; do not represent rollback as reversing those account-link decisions automatically.

## Do not deploy this batch independently

Batch 6 assumes the previous identity helper, session and shared-password changes are present. Do not deploy any Batches 2–6 to live until Batch 7 is verified and a coordinated release is approved.
