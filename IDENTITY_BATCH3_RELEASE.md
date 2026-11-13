# AppraisalSuite — Batch 3: identity sign-in and sessions

**Status: staged implementation only. DO NOT DEPLOY THIS BATCH ALONE.**

## Scope delivered

- Linked identities: look up by normalized email; verify the **identity password once per request**; list only active memberships in active companies. One membership logs in immediately; two or more return the existing `COMPANY_SELECTION_REQUIRED` response. A second request with the chosen company ID rechecks the identity password and validates company membership on the server.
- Workspace modal: preselect the last workspace actually used when its membership remains available. The last choice is found from existing `audit_log.action = 'login'` records belonging to that identity. No new schema column is introduced. Escape, initial focus and focus trapping supported.
- Session: JWT retains the exact membership `users.id` used throughout AppraisalSuite, and adds `identity_id` and `identity_token_version`. Middleware uses fresh membership/identity/company state, rejects inactive identities, memberships and companies, checks that the claimed identity belongs to the membership, and checks the **identity** token version for revocation. Old membership-only JWTs of linked identities are rejected. `/auth/session` renews validated cookies without changing CSRF and does not disclose token versions.
- Rate limit: normalized email + IP, with the existing `auth_login_attempts.company_id = 0` as a constant, including after workspace selection. Retains the existing 5 attempts/15 minutes settings.
- Sign out: ordinary `/auth/logout` continues to clear this browser only; new `POST /auth/logout-all` requires authenticated session and CSRF and increments the identity's token version via `UserIdentity.php` (Phase 1 dual-write) to revoke sessions everywhere. An unlinked, manual-review membership revokes its own legacy token version.
- Language: `/users/preferred-locale` now uses `userIdentitySaveLocale()` for linked identities, dual-writing membership preferences, to preserve Part 2's one-time login language choice. Unlinked accounts keep the prior membership update behavior.
- **Deferred manual review:** the two email groups excluded from migration 30 (`h.abinassif@lambertelectromec.com`, `h.eldine@lambertelectromec.com`) must remain able to authenticate to their currently available workspaces. When no identity exists, login temporarily validates legacy per-membership passwords; those sessions use per-row token versions until the records are reviewed and linked. This exception MUST be removed when the review is resolved. It never bypasses an identity that already exists for that email.

## Dependencies and deployment blockers

1. Batch 2 `api/includes/UserIdentity.php` and migration **30** must be present, applied and verified on an **isolated MariaDB copy** first. Migration 30 has intentionally not been run on production.
2. **Batch 4 remains mandatory before production activation.** Existing `routes/auth/ChangePassword.php`, `routes/users/ResetPassword.php`, and the password writer in `routes/users/UpdateProfile.php` still modify individual memberships. If Batch 3 were deployed with those unchanged, changing/resetting a password could desynchronize identity and membership records and the forced-password-change flow could issue a membership-only JWT which the new middleware correctly rejects. Batch 4 must update all these writers to use `UserIdentity.php`, issue an identity-aware renewed session, enforce cross-company authorization/audit and test old-session revocation across workspaces. Batch 3 alone is **not safe for live deployment**.
3. Batch 5 adds switch-workspace without signing out and a user-facing sign-out-everywhere action; `POST /auth/logout-all` is backend-only in Batch 3.
4. Batch 6 moves creation/edit/activation/deactivation of memberships to identity-aware operations. Keep identities and membership legacy columns in sync; do not drop any old columns.
5. Batch 7 runs complete live-like regression and approves coordinated release. Existing JWTs for linked accounts will need a fresh login when this new code goes live (intentional revocation due to migration 30 token versions / new claims).

## Tests to execute on a database copy

From `api/` on a PHP 8 server with `mysqli`, the project vendor dependencies and an isolated DB restored from the latest SQL export:

```bash
# FIRST: apply migration 30 twice, verify backfill and dual-write using Batch 2 instructions
APPRAISAL_IDENTITY_TEST_COPY_DB=lambert2_appraisalsuite_identity_copy php tests/user_identity_batch2_db_test.php --apply-twice

# THEN: read-only identity session and active membership checks
APPRAISAL_IDENTITY_TEST_COPY_DB=lambert2_appraisalsuite_identity_copy php tests/user_identity_batch3_db_test.php --read-only

# Fast checks (do not need MariaDB)
php tests/user_identity_batch3_session_test.php
php tests/user_identity_batch3_static_test.php
```

Additional end-to-end coverage still required once Batch 4 is integrated: one membership direct login; multiple-member selection & remembered workspace; failed attempts across different companies counted together; active/inactive membership/company/identity; forced password change; session renewal / CSRF; password-change revocation in all workspaces; locale dialog; sign-out everywhere; retained company scope, Company Access and no cross-workspace data leakage.

Frontend checks in the project root:

```bash
node --test src/tests/identity-login.test.mjs src/tests/languageChoiceBatch2.test.js
node scripts/i18n-report.mjs
npm install
npm run build
```

## Environment verification completed in this batch

- All changed PHP files: `php -l` passed.
- `user_identity_batch3_session_test.php`: 12/12 passed.
- `user_identity_batch3_static_test.php`: 14/14 passed.
- Frontend login + existing Part 2 language-choice tests: 14/14 passed.
- TS/JSX static parser: passes on changed frontend source.
- English/French locale audit: 0 missing/extra keys (no new text keys needed).
- Actual MariaDB integration: **not run**. This execution environment lacks the PHP mysqli extension and a MariaDB server.
- Vite production build: **not run**. Existing node_modules has no Vite executable.

## Files

Only the changed and added files are included in the Batch 3 ZIP; no `includes/connection.php`, `.env`, vendor dependencies or previous migration is included. Apply Batch 2 first; do not run on the live system until the coordinated release is ready.
