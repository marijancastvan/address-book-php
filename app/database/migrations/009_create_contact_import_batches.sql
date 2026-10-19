-- Private, short-lived server-side staging for one-time CSV contact imports.
-- Expired or consumed batches are deleted by the import flow; contact data is
-- never placed in public files or trusted from hidden form fields.
CREATE TABLE contact_import_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload LONGTEXT NOT NULL,
    review_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_contact_import_batches_token (token_hash),
    KEY idx_contact_import_batches_owner_expiry (user_id, expires_at),
    KEY idx_contact_import_batches_expiry (expires_at),
    CONSTRAINT fk_contact_import_batches_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
