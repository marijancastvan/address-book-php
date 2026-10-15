-- Store exact, precomputed comparison values for owner-scoped duplicate review.
-- Prefix index on email keeps the composite index within legacy InnoDB limits;
-- queries must still compare the full VARBINARY value.
ALTER TABLE contacts
    ADD COLUMN phone_normalized VARBINARY(32) NULL,
    ADD COLUMN email_normalized VARBINARY(255) NULL,
    ADD KEY idx_contacts_user_phone_normalized (user_id, phone_normalized),
    ADD KEY idx_contacts_user_email_normalized (user_id, email_normalized(191));
