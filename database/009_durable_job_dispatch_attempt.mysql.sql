-- Durable outbox dispatch attempts. Apply after 008 before starting any provider worker.
-- Provider I/O runs outside DB transactions; dispatching rows retain an attempt identity for recovery.
ALTER TABLE kp_durable_jobs
  DROP CONSTRAINT chk_durable_job_lease,
  MODIFY COLUMN status ENUM('queued','leased','dispatching','cancel_requested','uncertain','completed','failed','canceled') NOT NULL DEFAULT 'queued',
  ADD COLUMN dispatch_attempt_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER lease_expires_epoch,
  ADD UNIQUE INDEX uq_durable_dispatch_attempt (dispatch_attempt_id),
  ADD CONSTRAINT chk_durable_job_lease CHECK ((status IN ('leased','dispatching','cancel_requested')) = (lease_token_hash IS NOT NULL AND lease_expires_epoch IS NOT NULL));

ALTER TABLE kp_durable_job_events
  MODIFY COLUMN event_type ENUM('queued','lease_issued','dispatch_started','stop_canceled','stop_cancel_requested','dispatch_rejected','provider_completed','provider_failed_no_effect','provider_outcome_uncertain','lease_expired_uncertain') NOT NULL;
