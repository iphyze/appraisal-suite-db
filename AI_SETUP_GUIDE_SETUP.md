# Batch 6 — AI Setup Assistant

1. Apply `database/migration/15 - ai_setup_assistant_feature.sql` before deploying this batch.
2. Deploy the changed files over Batch 5, preserving all earlier database migrations, credentials and custom configurations.
3. In **Super Admin → AI Settings**, enable **Setup Assistant** with a reason. The server AI master switch and OpenAI configuration must also be enabled.
4. Open **Create/Edit Cycle**, **Create/Edit Section**, **KPI Questions** or **General Questions** and select **AI setup guide**. The assistant asks for the user's intent and optional constraints before generating read-only guidance. Copy and review the suggestion manually.
5. Verify the cycle and analysis-focus selectors in AI Management Insights use `AppSelect` and work in dark mode.

Access: Admin and Super Admin may request all four types of guidance. Supervisors can request KPI guidance only. Staff have no access. Permissions are checked on the backend. No company, employee or appraisal records are queried or modified by this feature. No AI result is applied automatically.

Deployment compatibility: If the new feature row is absent, only Setup Assistant defaults to disabled; existing AI modules continue to use their previous flags. Apply migration 15 before attempting to enable Setup Assistant.

Testing: `php backend/tests/ai_setup_guide_tests.php`. Full live OpenAI, MariaDB and browser testing is still required.
