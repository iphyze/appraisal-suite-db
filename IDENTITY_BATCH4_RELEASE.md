# AppraisalSuite — Batch 4 (shared passwords), 2026-11-09

**Staged changes only. Do not deploy this batch in isolation.** The new identity
schema (migration 30) and Batch 3 identity JWT/session support are prerequisites.
Batch 5–7 user management/release validation are not complete. Migration 30 has
not been executed on a MariaDB copy in this development environment.

## New/updated runtime paths

- `includes/IdentityPasswords.php` — shared password authorization, workspace
  lookups, strict per-company audit, and transitional unlinked member writes.
- `routes/auth/ChangePassword.php` — verifies the identity password under lock,
  updates it and every linked legacy membership in one transaction; reissues a
  freshly versioned cookie session with a new CSRF token. Legacy unlinked members
  still change their own password until personnel reconciliation.
- `routes/users/UpdateProfile.php` — removes the separate direct password writer;
  verifies under a lock, writes password/legacy fields and audit atomically;
  returns a freshly versioned session when the password changes. Ordinary profile
  editing remains scoped to the existing users.id membership.
- `routes/users/ResetPassword.php` — Super Admin or a home/granted admin who may
  manage every **active** membership in every **active** company; prevents resetting
  your own identity (including via another workspace) and an admin peer; resets
  the identity and synchronizes every linked row, revokes old sessions, and audits
  one entry per affected company. The temporary password format remains the old
  `Lambert@<year>` convention and requires change at the next sign-in.
- `routes/users/PasswordWorkspaces.php` — read-only lookup of company names for
  the signed-in person's own identity or a separately authorized admin reset
  target. Uses the same live authorization checks as reset. No hash is returned.
  Remains reachable to **self** during forced password change; other-user queries
  are rejected during that gate.
- `index.php` — registers the lookup and the narrowly limited password gate
  exemption; all central JWT/CSRF gates remain in place.
- `src/pages/auth/ChangePassword.jsx`, `src/pages/users/UsersOverview.jsx` —
  reuse existing language/theme/card styles, show company list for multi-company
  passwords; reset requires authorized preflight and disables duplicate submits.
- `src/locales/{en,fr}/{auth,users}.json`, corresponding page CSS,
  `src/stores/useUsersStore.js` — translations and denial reasons.

## Password reset authorization

1. The Super Admin retains cross-company reset authority except for their own
   identity, for which they must use Change Password.
2. An Admin must hold allowed write authority for the selected target membership
   in the current View Context. They cannot reset any admin/super_admin peer.
3. An Admin must additionally have home-company authorization or an **active
   manage grant** for every other active membership's company. The grant's staff
   scope and the existing home-company staff rule are respected. View-only grants
   are insufficient. Inactive memberships/companies do not increase the grant
   requirement, although linked legacy password fields are still synchronized.
4. When one active membership fails the check, no password or audit is written.
   The refusal carries the company's name and a translation-friendly error code.
5. All identity mutations, old-users dual-writes and company audits occur in
   a single InnoDB transaction; audit errors roll back the password change.

## Deployment/testing prerequisites

- Back up the live database and test migration 30/backfill twice on a MariaDB
  COPY, including the 294 identities, 304 linked memberships, and 4 deferred
  rows expected from the 2026-11-09 export. Keep server-side utf8mb4 enabled
  with migration 29's repair already applied.
- Run PHP `php tests/user_identity_batch4_password_test.php` (pure policy checks).
- Run PHP `APPRAISAL_IDENTITY_TEST_COPY_DB=appraisalsuite_identity_copy php
  tests/user_identity_batch4_db_test.php --rollback-only` on a **copy only**.
  The test refuses a DB whose name does not end with `_copy`, and always rolls
  back its own test writes. It validates token version change, hash dual-write,
  per-company audit records, and rollback.
- Run frontend `node --test src/tests/identity-passwords.test.mjs` and the
  existing authentication/localization tests; run `npm install` and `npm run build`
  where the complete Vite dependencies are installed.
- Manually test Super Admin resets, home Admin, granted Admin (manage/view),
  peer-admin protection, staff scopes, inactive memberships, forced/voluntary
  password changes, profile changes and session revocation, in both languages
  and themes, including phone layouts.
- Confirm outstanding personnel-review rows:
  `h.abinassif@lambertelectromec.com` (445/485) and
  `h.eldine@lambertelectromec.com` (444/451). Do not silently merge them.
- Retain all `users` legacy auth columns for the Phase 1 rollback. No changes
  to `.env`, `includes/connection.php`, old migrations, Company Access grants,
  appraisals or their drafts.

## Checks performed here

- PHP syntax: 8/8 changed PHP files/tests passed.
- Pure PHP reset authorization: 17/17 checks passed.
- Frontend + prior workspace tests: 6/6 passed.
- JSX/JS parser: 4 files passed.
- English/French locale key parity: 0 missing across all namespaces.
- MariaDB copy execution: **not run**, because this environment lacks MariaDB
  and the PHP `mysqli` extension.
- Production Vite build: **not run**, because the bundled node_modules does
  not contain the Vite executable.

Next: Batch 5 switch workspace without logout; Batch 6 cross-company person
management; Batch 7 full regression/release, including DB tests.
