-- Supports owner-scoped contact creation-date range filters.
ALTER TABLE contacts
    ADD KEY idx_contacts_user_created_at (user_id, created_at);
