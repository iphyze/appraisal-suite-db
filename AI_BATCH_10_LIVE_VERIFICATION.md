# AppraisalSuite AI — Batch 10 deployment verification

This is a **regression and deployment-readiness patch**, not a replacement for the existing app.
Deploy after migrations **13–16** and AI batches **1–9**. No new SQL migration.

## Before testing

1. Back up the database and existing frontend/backend files. Deploy the patch to a **staging** copy first.
2. Run the migrations in order: `13 - ai_foundation_usage.sql`, `14 - ai_feature_controls.sql`, `15 - ai_setup_assistant_feature.sql`, `16 - ai_conversation_history.sql`. Confirm they completed without SQL errors.
3. Confirm backend PHP has `mysqli`, `curl`, `openssl`, and the Composer `vendor/` dependencies; confirm frontend dependencies install/build normally. The developer sandbox does **not** have PHP mysqli/curl or the application Composer/npm dependencies, so end-to-end tests cannot be run there.
4. Configure the backend `.env` with `AI_ASSISTANT_ENABLED=true`, `OPENAI_API_KEY=...`, `OPENAI_MODEL=...` and optional usage/retention limits. Never put the API key in Vite/React or share the `.env` in a ZIP.
5. In Super Admin → AI Settings, confirm Master AI and the desired individual modules are enabled. Server configuration and switch settings **both** matter.

## Read-only deployed API smoke test (Node 18+)

Run `node backend/tests/ai_live_smoke.mjs` from any machine that can reach the application.
Supply these environment variables **securely** (for example, from a temporary staging test account; do not save a real session cookie in the codebase):

- `AI_SMOKE_BASE_URL`: HTTPS API root, e.g. `https://example.com/appraisalsuite-server/api`.
- `AI_SMOKE_COOKIE`: cookie header from a **staging test** login (do not log or share it).
- `AI_SMOKE_ROLE`: one of `staff`, `supervisor`, `admin`, `super_admin`.
- `AI_SMOKE_COMPANY_ID`: required for Super Admin conversation scope; optional for other roles.

It sends only **GET** requests and checks authentication, status contracts, history access, menu state and selected role boundaries. It does **not** call OpenAI, create chats, change switches or view message content. Repeat with a staging account for each role, both with Assistant enabled and disabled. Exit code 0 means checks were satisfied, **not** that all user workflows are integrated.

## Manual browser/API acceptance checklist (staging)

- [ ] **Configured on:** AI Settings shows server ready, master and Assistant enabled. Assistant can answer a generic system question.
- [ ] **OpenAI tool call:** Ask for one authorized employee appraisal. Response links open a permitted record and display the correct scores. Try a record outside the account's permitted scope; no private data appears.
- [ ] **Chat history:** Start a chat, send two messages, refresh, reopen, search, rename and delete; history persists appropriately and deletion cannot be reversed.
- [ ] **Cross-user:** Another account must not read, rename or delete the first account's conversation (404 rather than leaking content).
- [ ] **Cross-company:** Change the selected company as Super Admin; prior-company history must disappear, and old conversation IDs must be rejected.
- [ ] **Reduced privileges:** Remove a supervisor assignment or reduce admin visibility; transcripts from the old permission fingerprint must no longer open.
- [ ] **Disable Assistant:** In Super Admin settings, disable Assistant while a test chat is open in another tab; return to the chat tab. Composer becomes unavailable, and direct GET to `/ai/conversations` or `/ai/conversations/messages` is blocked (403). Re-enable and verify a previously allowed chat can load again.
- [ ] **Master off:** Disable Master AI; AI Assistant and Insights become unavailable, AI Settings remains accessible to Super Admin, and writes to `/ai/ask`, `/ai/writing`, `/ai/development-plan`, `/ai/setup-guide` are rejected.
- [ ] **Management Insights:** Admin can see permitted reports; staff and supervisors get 403. Groups with fewer than 3 distinct employees cannot be sent to OpenAI.
- [ ] **AI draft actions:** Writing, Development Plan and Setup Guide return **drafts only**; nothing is applied until the user approves it.
- [ ] **Dropdowns/themes:** AI modal dropdowns use AppSelect, with no clipping behind modal footer; verify light/dark mode and mobile widths.
- [ ] **Session expiry:** Let staging login expire, resume tab, perform a mutation; user is redirected to sign in without duplicate submissions or silent loss of permission checks.
- [ ] **Original appraisal workflows:** Create/update appraisal, safety cap verification, admin update restoration, PDF/Excel export and mail still behave as before.

## Outcomes and limitations

The included PHP/JS/CSS parser and regression checks validate code **without a live network/database**. Keep deployment blocked if any browser/API check fails. Do not test with production employee data unless your privacy review permits it.
