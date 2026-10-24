-- Kamau Putt: FOUNDATION financial reservation schema for MySQL 8+/MariaDB 10.6+
-- Dedicated staging DB only pending independent audit. No external charge occurs.
-- NEVER run via public web endpoint. Use InnoDB row-level locking.
CREATE TABLE IF NOT EXISTS kp_finance_control (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  operating_mode ENUM('growth','survival','shutdown') NOT NULL DEFAULT 'survival',
  owner_emergency_stopped TINYINT(1) NOT NULL DEFAULT 1,
  external_seed_ceiling_cents BIGINT UNSIGNED NOT NULL DEFAULT 50000,
  external_seed_received_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_seed_cap CHECK (external_seed_received_cents <= external_seed_ceiling_cents)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kp_financial_position (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  settled_cash_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  customer_liabilities_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tax_reserve_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  refund_reserve_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  discretionary_reserved_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kp_budget_envelopes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  scope VARCHAR(128) NOT NULL UNIQUE,
  approved_limit_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  per_action_cap_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  spent_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  reserved_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_budget_cap CHECK (spent_cents + reserved_cents <= approved_limit_cents)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kp_spend_reservations (
  id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  scope VARCHAR(128) NOT NULL,
  action_type VARCHAR(100) NOT NULL,
  amount_cents BIGINT UNSIGNED NOT NULL,
  status ENUM('reserved','uncertain','committed','released') NOT NULL DEFAULT 'reserved',
  external_reference VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_scope_status (scope, status),
  CONSTRAINT fk_spend_scope FOREIGN KEY (scope) REFERENCES kp_budget_envelopes(scope),
  CONSTRAINT chk_reservation_amount CHECK (amount_cents > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kp_financial_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reservation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_type ENUM('reserved','uncertain','committed','released') NOT NULL,
  amount_cents BIGINT UNSIGNED NOT NULL,
  recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_reservation_events (reservation_id, id),
  CONSTRAINT fk_event_reservation FOREIGN KEY (reservation_id) REFERENCES kp_spend_reservations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- New environments ALWAYS start stopped. Only a separate owner-authenticated
-- administrative workflow may later change mode or stop flag.
INSERT IGNORE INTO kp_finance_control (id) VALUES (1);
INSERT IGNORE INTO kp_financial_position (id) VALUES (1);
