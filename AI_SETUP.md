# AI Foundation — Batch 1

**Disabled by default.** The new authenticated endpoints do not affect appraisals, reports, scores, or existing menus.

1. Run `database/migration/13 - ai_foundation_usage.sql` against the current AppraisalSuite database.
2. Ensure the PHP web server has `ext-curl`, `ext-mysqli`, and `ext-openssl` enabled.
3. Add these settings to the **backend server-only** `.env` file (never the Vite/frontend `.env`):

```dotenv
AI_ASSISTANT_ENABLED=true
OPENAI_API_KEY=your_server_side_openai_api_key
OPENAI_MODEL=gpt-4.1-mini
AI_HOURLY_LIMIT=12
AI_DAILY_LIMIT=40
```

4. With a valid login cookie, `GET /appraisalsuite-server/api/ai/status` shows whether AI is enabled.
5. `POST /appraisalsuite-server/api/ai/ask` takes JSON `{"message":"How do appraisals work?","request_id":"<new UUID>"}`; it needs the existing CSRF cookie and `X-CSRF-Token` header. The frontend adapter (`src/services/aiAssistant.js`) handles these automatically.

## Safety and rollout

- Batch 1 accepts *general AppraisalSuite usage questions only*; **it cannot retrieve employee data, scores or management insights yet**. No new navigation or feature is exposed until Batch 2.
- Auth, required password change, and CSRF are inherited from the existing central router. A model prompt is not a permission check.
- Each user has a configurable hourly and daily cap. One question per user may run at a time. Request IDs prevent accidental second billing after a duplicated POST.
- Only usage metadata and a SHA-256 request fingerprint are stored; not question text, replies, or API credentials. The OpenAI request uses `store=false`.
- Frontend never receives the OpenAI key. Existing session handling is reused.
- Switch `AI_ASSISTANT_ENABLED=false` to disable instantly if needed.
- For production, set provider spending caps and decide retention/privacy policies before allowing appraisal records into future batches.

## Limitations

The container used to prepare this update has no PHP `curl` or `mysqli` extension; live API/DB integration testing was not possible here. Run a authenticated browser smoke test on your development server after applying the migration and key.
