-- Contact notes remain attached to the active holder after a merge.
-- origin_contact_id is immutable provenance; contact_id is the current holder.
CREATE TABLE contact_comments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    origin_contact_id BIGINT UNSIGNED NOT NULL,
    author_user_id BIGINT UNSIGNED NOT NULL,
    author_email_snapshot VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_contact_comments_owner_contact_time (user_id, contact_id, created_at, id),
    KEY idx_contact_comments_contact_owner (contact_id, user_id),
    KEY idx_contact_comments_origin_owner (origin_contact_id, user_id),
    KEY idx_contact_comments_author (author_user_id),
    CONSTRAINT fk_contact_comments_contact_owner
        FOREIGN KEY (contact_id, user_id) REFERENCES contacts (id, user_id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    CONSTRAINT fk_contact_comments_origin_owner
        FOREIGN KEY (origin_contact_id, user_id) REFERENCES contacts (id, user_id)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT,
    CONSTRAINT fk_contact_comments_author
        FOREIGN KEY (author_user_id) REFERENCES users (id)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
