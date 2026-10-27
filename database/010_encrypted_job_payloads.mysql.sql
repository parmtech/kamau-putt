-- Encrypted durable-job request payloads. Keep separate from queue metadata and provider credentials.
CREATE TABLE IF NOT EXISTS kp_job_payloads (
  payload_ref CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  payload_nonce VARBINARY(24) NOT NULL,
  payload_ciphertext MEDIUMBLOB NOT NULL,
  created_epoch BIGINT UNSIGNED NOT NULL,
  CONSTRAINT chk_job_payload_nonce CHECK (OCTET_LENGTH(payload_nonce) = 24),
  CONSTRAINT chk_job_payload_ciphertext CHECK (OCTET_LENGTH(payload_ciphertext) BETWEEN 16 AND 65552)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER kp_job_payloads_no_update BEFORE UPDATE ON kp_job_payloads FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Encrypted job payloads are immutable';
