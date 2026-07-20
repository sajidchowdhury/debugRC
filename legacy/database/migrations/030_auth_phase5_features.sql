-- Phase 5: Auth feature completeness (last login tracking, account lockout, password reset tokens).

ALTER TABLE users
  ADD COLUMN last_login_ip VARCHAR(45) DEFAULT NULL AFTER last_login,
  ADD COLUMN last_login_user_agent VARCHAR(255) DEFAULT NULL AFTER last_login_ip,
  ADD COLUMN failed_login_count INT(11) NOT NULL DEFAULT 0 AFTER last_login_user_agent,
  ADD COLUMN locked_until DATETIME DEFAULT NULL AFTER failed_login_count;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id INT(11) NOT NULL AUTO_INCREMENT,
  user_id INT(11) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_prt_user (user_id),
  KEY idx_prt_hash (token_hash),
  KEY idx_prt_expires (expires_at),
  CONSTRAINT fk_prt_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
