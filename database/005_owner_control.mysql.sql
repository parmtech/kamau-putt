-- Owner-authenticated STOP and reviewed, one-use resume challenges.
-- Apply only with the trusted migration runner after migrations 001 and 004.
CREATE TABLE IF NOT EXISTS kp_owner_resume_challenges (
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  dispatch_epoch BIGINT UNSIGNED NOT NULL,
  summary_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_epoch BIGINT UNSIGNED NOT NULL,
  expires_epoch BIGINT UNSIGNED NOT NULL,
  consumed_epoch BIGINT UNSIGNED NULL,
  INDEX idx_resume_challenge_expiry (expires_epoch),
  INDEX idx_resume_challenge_session (session_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
