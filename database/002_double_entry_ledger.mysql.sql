-- Balanced immutable-posting ledger foundation. Apply after migration 001.
-- Preserve raw source records and correction entries; never rewrite posted rows.
CREATE TABLE IF NOT EXISTS kp_ledger_accounts (
  account_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  account_name VARCHAR(128) NOT NULL,
  account_type ENUM('asset','liability','equity','income','expense') NOT NULL,
  normal_side ENUM('debit','credit') NOT NULL,
  currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'AUD',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_account_normal_side CHECK (
    (account_type IN ('asset','expense') AND normal_side='debit') OR
    (account_type IN ('liability','equity','income') AND normal_side='credit')
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kp_ledger_transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  transaction_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  source_reference VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  actor VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  previous_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  entry_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ledger_created (created_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kp_ledger_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  transaction_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  line_number SMALLINT UNSIGNED NOT NULL,
  account_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  debit_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  credit_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
  currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'AUD',
  memo VARCHAR(255) NOT NULL,
  UNIQUE KEY uq_ledger_line (transaction_id, line_number),
  INDEX idx_ledger_account (account_code, id),
  CONSTRAINT fk_ledger_entry_tx FOREIGN KEY (transaction_id) REFERENCES kp_ledger_transactions(transaction_id),
  CONSTRAINT fk_ledger_entry_account FOREIGN KEY (account_code) REFERENCES kp_ledger_accounts(account_code),
  CONSTRAINT chk_ledger_one_sided CHECK (
    (debit_cents > 0 AND debit_cents <= 9223372036854775807 AND credit_cents = 0) OR
    (credit_cents > 0 AND credit_cents <= 9223372036854775807 AND debit_cents = 0)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Defense in depth: posted facts cannot be edited or removed through ordinary SQL.
CREATE TRIGGER kp_ledger_tx_no_update BEFORE UPDATE ON kp_ledger_transactions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted ledger transactions are immutable';
CREATE TRIGGER kp_ledger_tx_no_delete BEFORE DELETE ON kp_ledger_transactions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted ledger transactions are immutable';
CREATE TRIGGER kp_ledger_entry_no_update BEFORE UPDATE ON kp_ledger_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted ledger entries are immutable';
CREATE TRIGGER kp_ledger_entry_no_delete BEFORE DELETE ON kp_ledger_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted ledger entries are immutable';
CREATE TRIGGER kp_ledger_account_no_update BEFORE UPDATE ON kp_ledger_accounts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ledger account definitions are immutable';
CREATE TRIGGER kp_ledger_account_no_delete BEFORE DELETE ON kp_ledger_accounts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ledger account definitions are immutable';

INSERT IGNORE INTO kp_ledger_accounts(account_code,account_name,account_type,normal_side) VALUES
 ('cash_settled','Settled cash','asset','debit'),
 ('processor_clearing','Payment processor clearing','asset','debit'),
 ('customer_liability','Customer/refund obligations','liability','credit'),
 ('tax_liability','Tax payable/reserve','liability','credit'),
 ('owner_capital','Owner contributed capital','equity','credit'),
 ('sales_income','Sales income','income','credit'),
 ('refunds_contra_income','Refunds and chargebacks','income','debit'),
 ('product_cost','Product cost','expense','debit'),
 ('shipping_cost','Shipping and fulfilment','expense','debit'),
 ('processing_fees','Payment processing fees','expense','debit'),
 ('marketing_cost','Marketing and advertising','expense','debit'),
 ('api_cost','AI/API usage','expense','debit'),
 ('operating_expense','Other authorized operating expenses','expense','debit');

