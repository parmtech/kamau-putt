-- Encrypted owner-managed provider credentials and append-only lifecycle audit.
-- Apply after owner-auth migration 004 using the trusted migration runner.
CREATE TABLE IF NOT EXISTS kp_provider_credentials (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  provider_slug VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  label VARCHAR(128) NOT NULL,
  fingerprint_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  secret_nonce BINARY(24) NOT NULL,
  secret_ciphertext VARBINARY(2064) NOT NULL,
  masked_suffix CHAR(4) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_epoch BIGINT UNSIGNED NOT NULL,
  UNIQUE KEY uq_provider_credential_fingerprint (provider_slug,fingerprint_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kp_credential_vault_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  credential_id BIGINT UNSIGNED NOT NULL,
  actor_owner_id TINYINT UNSIGNED NOT NULL,
  event_type ENUM('stored_pending','smoke_test_passed','smoke_test_failed','activated','revoked') NOT NULL,
  detail_json JSON NOT NULL,
  occurred_epoch BIGINT UNSIGNED NOT NULL,
  previous_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  CONSTRAINT fk_vault_event_credential FOREIGN KEY (credential_id) REFERENCES kp_provider_credentials(id),
  CONSTRAINT fk_vault_event_actor FOREIGN KEY (actor_owner_id) REFERENCES kp_owner_accounts(id),
  INDEX idx_vault_events_credential (credential_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER kp_provider_credentials_no_update BEFORE UPDATE ON kp_provider_credentials FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Provider credentials are immutable; add a new version';
CREATE TRIGGER kp_provider_credentials_no_delete BEFORE DELETE ON kp_provider_credentials FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Provider credentials are retained for audit';
CREATE TRIGGER kp_credential_vault_events_no_update BEFORE UPDATE ON kp_credential_vault_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Credential vault audit is append-only';
CREATE TRIGGER kp_credential_vault_events_no_delete BEFORE DELETE ON kp_credential_vault_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Credential vault audit is append-only';
