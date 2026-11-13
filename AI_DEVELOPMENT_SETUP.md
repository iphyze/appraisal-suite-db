# AppraisalSuite AI Batch 5 — Guided Development Plan Generator

Deploy this batch on top of Batches 1–4 (including migrations 13 and 14).

1. Copy only the included files to matching frontend/backend paths.
2. No new SQL migration is required. The `development_plan` key already exists in migration 14, initially disabled.
3. Set `OPENAI_API_KEY` and `AI_ASSISTANT_ENABLED=true` in the backend environment as before.
4. As **Super Admin**, open **AI Settings** and enable **Development Plan Generator**, providing an audit reason. The master AI switch must also be on.
5. Open a staff appraisal as an authorized appraiser, go to **Recommendations → Development**, and select **Create development plan with AI**.
6. Describe your own observed performance, improvement areas, intended outcome, support available and timeframe. Review/edit the proposed plan, then choose **Use this plan** to copy it to the existing Development field. It is NOT saved until you submit the appraisal.

The backend does not retrieve employee records or modify scores, submissions, histories or permissions. Only the entered fields (and existing Development note, if any) are sent to OpenAI. Requests are authenticated, subject to role and feature checks, rate-limited, logged by request fingerprint (without retaining prompt/answer), and use `store: false`.

### Verification

- `php backend/tests/ai_development_plan_tests.php`
- `php backend/tests/ai_feature_settings_tests.php`
- Test enabled, disabled and master-off feature flags with a real database.
- Test in Chrome using an active OpenAI key, including mobile/dark mode and applying an edited draft.
- Verify employees cannot call the supervisor-only endpoint and only supervisors authorized for the appraisal can open its edit form.
- Verify that applying a plan alone never saves an appraisal and does not change its rating.
