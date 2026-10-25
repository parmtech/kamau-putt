-- Append-only audit for owner STOP and resume decisions; apply after migration 004.
CREATE TABLE IF NOT EXISTS kp_owner_control_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  actor_owner_id TINYINT UNSIGNED NOT NULL,
  event_type ENUM('stop_applied','stop_already_stopped','resume_challenge_issued','resume_confirmed') NOT NULL,
  dispatch_epoch BIGINT UNSIGNED NOT NULL,
  summary_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  occurred_epoch BIGINT UNSIGNED NOT NULL,
  previous_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  CONSTRAINT fk_owner_control_audit_actor FOREIGN KEY (actor_owner_id) REFERENCES kp_owner_accounts(id),
  INDEX idx_owner_control_audit_epoch (dispatch_epoch,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER kp_owner_control_audit_no_update BEFORE UPDATE ON kp_owner_control_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Owner control audit is append-only';
CREATE TRIGGER kp_owner_control_audit_no_delete BEFORE DELETE ON kp_owner_control_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Owner control audit is append-only';
