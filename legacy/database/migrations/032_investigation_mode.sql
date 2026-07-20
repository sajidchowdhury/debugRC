-- Phase 7: Investigation access window (QR activate, email OTP deactivate).

CREATE TABLE IF NOT EXISTS investigation_windows (
  id INT(11) NOT NULL AUTO_INCREMENT,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at DATETIME DEFAULT NULL,
  started_by_user_id INT(11) NOT NULL,
  ended_by_user_id INT(11) DEFAULT NULL,
  effective_role VARCHAR(50) NOT NULL DEFAULT 'admin',
  read_only TINYINT(1) NOT NULL DEFAULT 1,
  notes VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_inv_window_active (ended_at),
  KEY idx_inv_window_started_by (started_by_user_id),
  KEY idx_inv_window_ended_by (ended_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS investigation_activators (
  id INT(11) NOT NULL AUTO_INCREMENT,
  user_id INT(11) NOT NULL,
  label VARCHAR(100) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_inv_activator_user (user_id),
  KEY idx_inv_activator_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS investigation_deactivation_otps (
  id INT(11) NOT NULL AUTO_INCREMENT,
  window_id INT(11) NOT NULL,
  otp_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME DEFAULT NULL,
  requested_by_user_id INT(11) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_inv_deact_window (window_id),
  KEY idx_inv_deact_expires (expires_at),
  KEY idx_inv_deact_user (requested_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
