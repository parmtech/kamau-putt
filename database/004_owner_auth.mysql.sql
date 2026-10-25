-- Owner-only identity, TOTP MFA, throttling and opaque server-side sessions.
-- Apply from a trusted migration runner; never expose registration endpoints.
CREATE TABLE IF NOT EXISTS kp_owner_accounts (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL UNIQUE,
  password_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  totp_nonce BINARY(24) NOT NULL,
  totp_ciphertext VARBINARY(128) NOT NULL,
  last_totp_step BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_single_owner CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kp_owner_sessions (
  session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  csrf_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_epoch BIGINT UNSIGNED NOT NULL,
  last_seen_epoch BIGINT UNSIGNED NOT NULL,
  expires_epoch BIGINT UNSIGNED NOT NULL,
  revoked_epoch BIGINT UNSIGNED NULL,
  INDEX idx_owner_session_expiry (expires_epoch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kp_owner_auth_attempts (
  attempt_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  failure_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  window_started_epoch BIGINT UNSIGNED NOT NULL,
  blocked_until_epoch BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
