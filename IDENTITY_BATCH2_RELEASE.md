# AppraisalSuite — Login identities, Batch 2 (schema + helper only)

**Status:** Prepared, **NOT DEPLOYED**. Batch 2 deliberately does not change any live login, session, CSRF, email, language, password, company-access or frontend routes. Do not install the migration on the live database by itself. The old code still writes individual `users` authentication columns; installing the backfill now would (a) invalidate existing per-row tokens, (b) change the password on some duplicate memberships, and (c) let old routes diverge from the new identities again. Stage the migration together with compatible code from subsequent batches, in a maintenance window.

## Delivered files

- `api/database/migration/30 - user_identities_and_memberships.sql`: MariaDB 11.4, ordinary SQL for phpMyAdmin, no `PREPARE` or `EXECUTE`. Creates `user_identities`, nullable `users.identity_id`, index and a foreign key, then inserts one identity per eligible normalised email and links/synchronises membership credentials. Old columns remain. Migration guards leave an existing identity or membership link unchanged on repeated execution.
- `api/includes/UserIdentity.php`: email and identity lookup; active-company/active-membership list (not Company Access grants); identity password update, revocation, locale preference and last-login operations; transactional dual-write of `users` compatibility fields. Password inputs **must be hashes**, not plaintext. `*InTransaction` methods may be composed with caller-owned company audit writes; wrapper methods manage their own transactions.
- `api/tests/user_identity_batch2_static_test.php`: database-independent checks.
- `api/tests/user_identity_batch2_db_test.php`: integration test designed for a **fresh MariaDB copy**. Checks SQL fixture ranking, initial counts, selection of hash/must-change/tokens/locale, all links, legacy field synchronisation, an unchanged second run, active membership filtering, and transactional helper writes (rolled back).

## Expected from the 9 November 2026 updated SQL export

| Metric | Expected |
|---|---:|
| Total `users` membership rows | 485 |
| Memberships with nonempty email | 308 |
| Distinct normalised email values | 296 |
| Memberships with no email (stay `identity_id = NULL`) | 177 |
| Identity rows created automatically | **294** |
| Memberships linked automatically | **304** |
| Memberships with email, pending manual name review | **4** |
| Linked membership rows with inconsistent legacy auth values | **0** |

**Deferred by explicit Batch 1 review flag:**

- `h.abinassif@lambertelectromec.com` (users.id 445, 485)
- `h.eldine@lambertelectromec.com` (users.id 444, 451)

Both pairs have different password hashes and name-format differences. **Do not automatically link them**, even though they may be the same people, until HR confirms the match. If confirmed, create a separately reviewed follow-up migration to link them and select the agreed hash according to the original selection rule; do **not** modify migration 30. Other ten multi-company groups are eligible.

The supplied dump has all membership `preferred_locale` values NULL; the backfill therefore starts with NULL and uses the active membership's company default when the locale-aware login is subsequently integrated. For future non-NULL values, the most recent `users.updated_at` is the best available proxy for preference time (there is no `preferred_locale_changed_at`).

## Safe database-copy validation steps

1. Back up production and restore the supplied/current production dump to a **separate newly-created database** with a name ending in `_copy`. Never point this script at live production.
2. Configure the existing (not shipped) `api/includes/connection.php`/`.env` locally to use only that copy. Confirm the connection charset is `utf8mb4`.
3. Run static tests: `php api/tests/user_identity_batch2_static_test.php`.
4. From the backend `api/` directory run, using your copy database's **exact name**:

   ```bash
   APPRAISAL_IDENTITY_TEST_COPY_DB=lambert2_appraisalsuite_identity_copy \
     php tests/user_identity_batch2_db_test.php --apply-twice
   ```

   The script checks that the actual selected database name matches the supplied copy name, refuses names without a `_copy` suffix, requires a **pre-migration copy** (identity table must be absent), runs migration 30 **twice** and compares the rows. Helper write tests are wrapped in a transaction and rolled back.
5. Inspect the migration's `before` and `after` SELECT results. The two `after` difference counts must both be zero. Inspect the four remaining memberships, then resolve their HR confirmations before the future authentication cutover.
6. Repeat the copy validation on a freshly restored **latest** production dump before scheduling live deployment.

**Important:** The integration test applies a schema migration on its configured database. This is why it requires an isolated copy. MariaDB DDL autocommits; a copy can be discarded and recreated afterward.

## Work remaining (not part of Batch 2)

- **Batch 3:** identity-based login and identity-aware JWT/session revocation and rate limiting. An identity is inactive if `user_identities.is_active = 0`; active membership and company checks remain mandatory. Plan compatibility for deferred reviews before cutover.
- **Batch 4:** update every password writer (including `UpdateProfile.php`), admin reset cross-company permissions, per-company audits, and en/fr company notice; preserve forced password change.
- **Batch 5:** secure switch-workspace endpoint and frontend menu, CSRF rotation, company context/cache reset and draft preservation.
- **Batch 6:** identity-aware create/edit/email/user management; membership-only deactivation; identity language preference; restricted Also-in badges.
- **Batch 7:** confirm the twelve known duplicates (including two deferred cases), full regression, live maintenance plan, migration verification and rollback documentation.

**Never ship** `api/includes/connection.php` or `.env`. Old `users` authentication columns must remain through the whole first release and beyond until a separately authorised migration removes them.

## Tests available in this package

Local PHP syntax and 14 database-independent checks have passed. The latest uploaded dump was parsed to independently verify the 294/304/4/177 counts and password-hash group choices. **MariaDB SQL execution on a copy is outstanding** because this execution environment has no MariaDB server or PHP `mysqli` extension. Do not mistake the simulation/static checks for a passed database integration run.
