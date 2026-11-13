# AI Batch 7 — release checks

This is a patch-only release. No new database migration is required. Install it **after** AI Batches 1–6 and migrations 13–15.

## Automated, no database/API key required

From the project root, run:

```bash
php backend/tests/ai_foundation_tests.php
php backend/tests/ai_assistant_access_tests.php
php backend/tests/ai_feature_settings_tests.php
php backend/tests/ai_management_insights_tests.php
php backend/tests/ai_writing_tests.php
php backend/tests/ai_development_plan_tests.php
php backend/tests/ai_setup_guide_tests.php
php backend/tests/ai_batch7_security_regression.php
```

## Required live checks (not run in the package-building environment)

1. Apply migrations 13, 14 and 15. Configure OpenAI key and `AI_ASSISTANT_ENABLED=true` in backend `.env`.
2. With AI master switch off, confirm all generative POST endpoints reject requests. Enable a single module and confirm other disabled modules still reject.
3. As staff, verify own appraisal access only; team/company lookups fail. As supervisor, verify only assigned staff. As admin, verify peer-admin visibility. As Super Admin, verify selected-company isolation.
4. Test Management Insights with 1 or 2 distinct appraised employees: local report remains visible but **Generate AI insights** is disabled and direct POST returns HTTP 422. With 3 distinct staff, generation is permitted; sparse departments and score bands are excluded from OpenAI input.
5. Verify Writing, Development Plan and Setup Guide only produce drafts; they never save changes automatically. Disable each feature during a new request and verify the next request is denied.
6. Test session expiry, CSRF recovery, feature toggles, staff feedback, appraisal create/update, follow-ups, scoring and reports in the browser with the live database.

**Limitations:** Syntax and pure-function tests do not prove MariaDB queries, production authentication cookies, OpenAI responses or browser layout. Complete live checks before production approval.
