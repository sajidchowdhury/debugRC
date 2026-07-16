-- Dedicated session invalidation stamp (replaces overloading users.updated_at).

ALTER TABLE users
  ADD COLUMN credential_version INT UNSIGNED NOT NULL DEFAULT 1
  AFTER updated_at;

UPDATE users
SET credential_version = 1
WHERE credential_version = 0;
