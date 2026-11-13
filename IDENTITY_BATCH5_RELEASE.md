# AppraisalSuite — Batch 5: Switch your own company membership

This package is **only Batch 5's changed/added files**, relative to the working
codebase after Batches 2–4. It is **not** a complete backend or frontend.
Do not deploy this package by itself to production.

## Behavior

- `GET /auth/workspaces` uses the authenticated identity and returns only its
  active memberships in active companies. It returns zero workspaces for the
  four membership rows intentionally left unlinked pending personnel review.
- `POST /auth/switch-workspace` accepts `{ "user_id": <membership users.id> }`.
  The router validates the authenticated session, forced-password gate and
  CSRF. The endpoint also enforces both checks and validates a fresh, locked
  identity plus BOTH source and target memberships in a database transaction.
  No role, company ID, identity ID or token claim comes from client data.
- The existing `startSecureSession()` rotates the signed HttpOnly JWT and CSRF
  cookies. `users.id` in the new session is the selected membership; JWT
  identity/token version is unchanged and still checked by middleware.
- One `switch_workspace` audit entry is inserted for each company, in the same
  transaction. The destination entry is last; the existing login preselection
  now reads latest login **or** workspace switch.
- The profile menu shows this **personal workspace** switcher only when more
  than one eligible membership is returned. It is clearly separated from
  administrator Company Access **View Context**.
- The frontend clears View Context, selected cycle and cached company grants,
  restores the fresh CSRF/user payload, then navigates to `/dashboard` using
  a full reload. If a switch response is lost after cookies rotate, the
  frontend probes `/auth/session` before exposing any other workspace data. The reload resets company-scoped in-memory data, while local
  appraisal drafts remain keyed by the original membership user ID.
- No database migration, email, password, company access grant, or old schema
  column changes are introduced by Batch 5.

## Changed / new files

- `api/index.php`
- `api/includes/UserIdentity.php`
- `api/includes/IdentityWorkspaces.php` (new)
- `api/routes/auth/login.php`
- `api/routes/auth/Workspaces.php` (new)
- `api/routes/auth/SwitchWorkspace.php` (new)
- `api/tests/user_identity_batch5_workspace_test.php` (new)
- `api/tests/user_identity_batch5_db_test.php` (new)
- `api/IDENTITY_BATCH5_RELEASE.md` (new)
- `src/stores/useAuthStore.js`
- `src/stores/usePortalContextStore.js`
- `src/pages/Header.jsx`
- `src/components/MemberWorkspaceSwitcher.jsx` (new)
- `src/components/MemberWorkspaceSwitcher.css` (new)
- `src/utils/memberWorkspaces.js` (new)
- `src/locales/en/nav.json`
- `src/locales/fr/nav.json`
- `src/tests/memberWorkspaces.test.mjs` (new)

## Testing

From the source working copy:

```sh
php api/tests/user_identity_batch5_workspace_test.php
node --test src/tests/memberWorkspaces.test.mjs
node --test src/tests/*.test.js src/tests/*.test.mjs
php -l api/routes/auth/SwitchWorkspace.php
```

Copy-only SQL/audit integration, with `api/includes/connection.php` configured
**for that copy** (do not ship it):

```sh
cd api
APPRAISAL_IDENTITY_TEST_COPY_DB=appraisalsuite_identity_copy \
  php tests/user_identity_batch5_db_test.php --rollback-only
```

The integration test explicitly refuses DBs without a `_copy` suffix, verifies
`SELECT DATABASE()`, and wraps audit assertions in a rollback-only transaction.
The local workspace has no MariaDB service or PHP `mysqli` extension; do not
report this integration test as passed until run on a database copy.

**Manual browser checks still required** (en/fr, light/dark, mobile): successful
Lambert ↔ LEM CIV switch, 403 for another identity's membership, 403 for
suspended membership/company, new CSRF accepted on writes, old View Context
selection and caches gone, and drafts still present when returning to the
original membership. Test a role change across workspaces and a Super Admin.

## Release dependencies / open questions

1. **Do not go live yet**: schema/helper (2), identity login/session (3) and
   shared-password changes (4) must be released together with this batch only
   after database-copy tests and coordinated release verification (7).
2. Personnel must confirm `h.abinassif@lambertelectromec.com` and
   `h.eldine@lambertelectromec.com` before linking their memberships. This
   package intentionally does not change their authentication.
3. Batch 6 remains: cross-company create/edit/deactivate, email identity
   ownership, list badges and account-level locale controls for user management.
4. Batch 7 remains: complete regression, migration/backfill verification,
   deployment/rollback instructions and production release checks.
