-- Mark contacts as active or soft deleted without removing their related data.
-- Apply after 005_create_contact_history.sql.
ALTER TABLE contacts
    ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL,
    ADD KEY idx_contacts_user_deleted_at (user_id, deleted_at);
