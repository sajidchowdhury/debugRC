-- Backfill NULL users.updated_at so credential-version sessions work for legacy accounts.

UPDATE users
SET updated_at = COALESCE(last_login, created_at, NOW())
WHERE updated_at IS NULL;
