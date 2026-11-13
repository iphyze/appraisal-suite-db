# AI feature switches — follow-up to Batch 2 (Super Admin controls)

1. Apply `database/migration/14 - ai_feature_controls.sql` **before** deploying these updated PHP routes.
2. Copy only the files in this package into the matching frontend/backend paths; keep Batch 1 and Batch 2 files in place.
3. Log in as **Super Admin** and open **AI Administration → AI Settings** (`/ai/settings`). Each change requires a reason and is recorded in `ai_feature_change_log` (no prompt/answer content).
4. The switches are **global**, not per company. The initial master + Assistant switches are ON to preserve the prior environment-controlled behavior. The three unreleased modules remain OFF and cannot be enabled until released.
5. The server settings `AI_ASSISTANT_ENABLED=true` and `OPENAI_API_KEY` are still required. `AI_ASSISTANT_ENABLED=false` is an **emergency kill switch**, even when Super Admin settings are ON.
6. Test Super Admin settings GET/PUT, Admin/Staff 403, a disabled Assistant returning 403 on POST `/ai/ask`, disabling master, and re-enabling. Existing appraisal create/update endpoints are untouched.

**Migration:** Re-runnable via `CREATE TABLE IF NOT EXISTS` and `INSERT IGNORE`, without resetting existing saved switch states.

**Boundary:** The AI Assistant remains the only live AI module. Next: Management Insights, writing and development-plan features. New AI endpoints **must** call `aiRequireFeature($conn, 'feature_key')` before any AI model invocation or authorized-data retrieval.

**Not tested here:** Live MySQL, OpenAI call, browser UI across screen sizes.
