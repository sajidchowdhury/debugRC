-- Phase 6: Remember-me tokens + TOTP two-factor authentication.

CREATE TABLE IF NOT EXISTS remember_tokens (
  id INT(11) NOT NULL AUTO_INCREMENT,
  user_id INT(11) NOT NULL,
  selector CHAR(24) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  ip_address VARCHAR(45) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_remember_selector (selector),
  KEY idx_remember_user (user_id),
  KEY idx_remember_expires (expires_at),
  CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE users
  ADD COLUMN totp_secret VARCHAR(64) DEFAULT NULL AFTER locked_until,
  ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secret;
