-- Durable dispatch outbox. No payload or credential material is stored here.
-- Apply after financial reservation and owner-control migrations.
CREATE TABLE IF NOT EXISTS kp_durable_jobs (
  id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  job_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  reservation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  action_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  payload_ref VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  authorization_epoch BIGINT UNSIGNED NOT NULL,
  status ENUM('queued','leased','cancel_requested','uncertain','completed','failed','canceled') NOT NULL DEFAULT 'queued',
  attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  lease_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  lease_expires_epoch BIGINT UNSIGNED NULL,
  external_reference VARCHAR(255) NULL,
  created_epoch BIGINT UNSIGNED NOT NULL,
  updated_epoch BIGINT UNSIGNED NOT NULL,
  CONSTRAINT fk_durable_job_reservation FOREIGN KEY (reservation_id) REFERENCES kp_spend_reservations(id),
  CONSTRAINT chk_durable_job_lease CHECK ((status IN ('leased','cancel_requested')) = (lease_token_hash IS NOT NULL AND lease_expires_epoch IS NOT NULL)),
  CONSTRAINT chk_durable_job_attempt CHECK (attempt_count <= 1),
  INDEX idx_durable_job_claim (status,created_epoch),
  INDEX idx_durable_job_epoch (authorization_epoch,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kp_durable_job_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  job_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  actor_kind ENUM('owner','system','worker') NOT NULL,
  actor_owner_id TINYINT UNSIGNED NULL,
  event_type ENUM('queued','lease_issued','stop_canceled','stop_cancel_requested','provider_completed','provider_failed_no_effect','provider_outcome_uncertain','lease_expired_uncertain') NOT NULL,
  detail_json JSON NOT NULL,
  occurred_epoch BIGINT UNSIGNED NOT NULL,
  previous_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  CONSTRAINT fk_durable_job_event_job FOREIGN KEY (job_id) REFERENCES kp_durable_jobs(id),
  CONSTRAINT fk_durable_job_event_owner FOREIGN KEY (actor_owner_id) REFERENCES kp_owner_accounts(id),
  INDEX idx_durable_job_events_job (job_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER kp_durable_job_events_no_update BEFORE UPDATE ON kp_durable_job_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Durable job audit is append-only';
CREATE TRIGGER kp_durable_job_events_no_delete BEFORE DELETE ON kp_durable_job_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Durable job audit is append-only';
