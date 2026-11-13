# AI Batch 2 — Read-only Appraisal Assistant

Requires **Batch 1** (`ai_usage_events` migration 13 and the server-only OpenAI key). No new SQL migration is required.

1. Deploy the included frontend and backend files over the Batch 1 installation. Do not remove the prior AI foundation or other API files.
2. On the backend only, set `AI_ASSISTANT_ENABLED=true`, `OPENAI_API_KEY=...`, and `OPENAI_MODEL=gpt-4.1-mini` (or your supported Responses API model).
3. Sign in and open **Workspace → AI Assistant** at `/ai-assistant`.
4. Test as Staff (own only), Supervisor (own/team), Admin (company-scoped and peer-Admin policy), Admin + supervisor, and Super Admin (select a company before retrieving records).
5. Test `/ai/status`, a general question, a search by employee name within an authorized scope, a permitted appraisal ID and an unauthorized appraisal ID.

**Boundary:** AI can read only existing submitted appraisals, limited fields and section scores. It cannot access mail, personnel directory, unsubmitted appraisals, management follow-up text or change records. Live management analytics and writer/development-plan tools are later batches.

**Security:** API key remains on the PHP server; the backend authenticates and applies company/staff/peer-admin scopes for each tool. No arbitrary SQL or write tool is exposed. Conversation history remains in React component memory (not saved in localStorage/database). Provider requests use `store=false`; minimal authorized data and recent messages are sent to OpenAI to answer the user's question. Usage metadata only is logged in the Batch 1 table.

**Operational limits:** A tool-aware answer may require two OpenAI API calls (tool planning + final answer). Usage limits still count one user question. Test `view_company_id` in the portal for superadmins; switching company context does not grant access to other companies' records in the same query. Test rate limiting and errors.

**Not tested here:** Live OpenAI API, MySQL/MariaDB queries and browser UI. Run these before release, using a non-production database/user test accounts.
