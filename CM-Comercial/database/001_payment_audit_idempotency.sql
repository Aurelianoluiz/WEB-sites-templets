ALTER TABLE payment_audit_log
    ADD COLUMN idempotency_key VARCHAR(255) NULL AFTER actor,
    ADD UNIQUE KEY uq_payment_audit_idempotency (idempotency_key);
