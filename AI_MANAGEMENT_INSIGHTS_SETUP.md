# AppraisalSuite AI — Management Insights (Batch 3)

This release **adds or changes only the files in the ZIP**. Apply on top of AI Foundation (migration 13), AI Assistant (Batch 2), and Super Admin Feature Controls (migration 14). Do not replace unrelated application files.

## Enable

1. Ensure migrations 13 and 14 have been executed successfully. **No new SQL migration is required here.**
2. Deploy the changed frontend/backend files preserving their folder paths.
3. Confirm `.env` on the backend has `OPENAI_API_KEY=...` and `AI_ASSISTANT_ENABLED=true` (the server emergency switch).
4. Sign in as **Super Admin → AI Settings**, switch on **Management Insights** with a reason. This setting defaults **off** in migration 14, and the master AI switch also has to be on.
5. Open **Workspace → AI Insights** as Admin or Super Admin. Super Admin must select one company with View Context. The page is read-only and no AI request is made until `Generate AI insights` is clicked.

## Access and accuracy

- Backend `GET /ai/insights?cycle_id=...` returns **authorized aggregated statistics**. Only `admin` and `super_admin` roles can access. Admin data is filtered using the existing admin-to-admin visibility and `staff_scope` rules. Super Admin is restricted to the selected company (no all-company combined totals).
- `POST /ai/insights` uses the same scopes and Super Admin switch, applies existing AI limits and usage audit, then sends only aggregate statistics to OpenAI (no employee names, written comments, individual scores or personal details).
- Department and trend averages involving fewer than three **distinct employees** are hidden. Averages across cycles may not be directly comparable if scoring configurations changed.
- `Pending` means staff acknowledgement is pending, **not** that the employee lacks an appraisal. The dashboard counts submitted records, not appraisal completion across the workforce.
- AI responses are suggestions for human review. The AI cannot alter scores, appraisals, cycles, objectives, feedback or management decisions.

## Checks after deploying

- Staff and Supervisor users cannot access the endpoint (HTTP 403) or view the menu item.
- Admin sees only permitted appraisals within their own company and configured staff scope.
- Super Admin switching companies clears previously loaded figures and responses. Without a selected company, the endpoint refuses private queries.
- Master or Management Insights switch OFF prevents both GET and POST. The Assistant switch remains independent.
- Try an invalid/foreign cycle ID; backend returns a safe 404 rather than cross-company data.
- Open an existing cycle and verify the numbers against authorized appraisal listing, then optionally generate AI commentary.

**Testing limitation:** Static PHP/JS/CSS and pure permission/validation checks were run. Live MariaDB, OpenAI requests, and deployed browser behavior still require verification on your server.
