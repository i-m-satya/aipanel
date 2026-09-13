-- The panel no longer authors code.
--
-- Code is written wherever the owner works on their repository — Claude Code
-- or anything else — and aipanel's job starts at the push. That removes the
-- in-panel assistant and its change requests, along with the only components
-- that ever held a model API key.

DROP TABLE IF EXISTS change_requests;
DROP TABLE IF EXISTS plans
