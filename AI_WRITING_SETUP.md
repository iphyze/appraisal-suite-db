# AppraisalSuite AI Batch 4 — Guided Writing Assistant

Install on top of AI Foundation, AI Assistant, Super Admin feature controls (migration 14), and AI Management Insights.

- Copy the files from the delivery ZIP to the matching project paths.
- No new SQL migration. Migration 14 must already have created the `writing_assistant` feature setting.
- Keep the backend API key in `.env` and `AI_ASSISTANT_ENABLED=true`; never put it in the frontend.
- Sign in as Super Admin, go to **AI Settings**, and enable **Writing Assistant** (with an audit reason).
- Supervisors can use **Improve with AI** beside rating comments; employees can use it beside their appraisal feedback.
- The modal collects the user's observations and supporting examples before generating a suggested draft. **Use this draft** copies text to the existing field only. The user must still submit their original appraisal/feedback form.
- No question, score, weight, management decision or database record is automatically changed by this endpoint.
- The backend feature switch and role checks are enforced for every generation request. Usage tracking (migration 13) applies.
- Confirm the layout and field interactions on desktop/mobile and light/dark mode; live OpenAI and database testing are still necessary.

To run targeted PHP validation: `php backend/tests/ai_writing_tests.php`.
