# AI Assistant Chat History — Batch 8

1. Deploy after AI batches 1–7 and the chat UI polish patch.
2. Import `database/migration/16 - ai_conversation_history.sql` into the AppraisalSuite MariaDB database using phpMyAdmin.
3. Deploy only the changed frontend and backend files from the patch ZIP.
4. Existing `.env` requirements remain: `AI_ASSISTANT_ENABLED=true`, `OPENAI_API_KEY=...`, `OPENAI_MODEL=gpt-4.1-mini`. The Super Admin must also enable Master AI and AI Assistant in AI Settings.
5. Optional backend `.env`: `AI_CHAT_RETENTION_DAYS=180` (supported 7–365; 180 default). Expired chats are purged during a user's chat-history access. Schedule a maintenance job if deletion must occur independently of user access.
6. Chat history is stored in **your own MariaDB**, not the OpenAI API (`store: false` is unchanged). It can contain sensitive appraisal information: include it in backup protection and your organization's retention/privacy policy.
7. Each conversation belongs to the signed-in user, selected company and permission fingerprint; an altered role/visibility/team assignment hides older transcripts rather than allowing access under different privileges. Chat deletion cascades to saved messages.
8. Test **staff**, **supervisor**, **admin**, **super admin** and a company context change in the browser. Test chat persistence after refresh, rename, search, delete and both themes. Check that stale permissions return HTTP 404 and invalid IDs return 422.
9. The Writing Assistant and Development Plan modal now use shared `AppSelect` instead of native selects. Confirm dropdowns open above sticky modal footers.

**Known limits:** There is no live MariaDB or OpenAI environment inside this development sandbox. Run these integration scenarios on your own server before production deployment. Historical chats created before Batch 8 were browser-only and cannot be recovered.
