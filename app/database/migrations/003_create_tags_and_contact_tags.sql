-- Private contact tags with database-enforced same-user ownership.
-- Apply after 001_create_initial_tables.sql and 002_create_password_reset_tokens.sql.

-- Required as the referenced key for the composite ownership foreign key below.
ALTER TABLE contacts
    ADD UNIQUE KEY uq_contacts_id_user_id (id, user_id);

CREATE TABLE tags (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tags_user_name (user_id, name),
    UNIQUE KEY uq_tags_id_user_id (id, user_id),
    CONSTRAINT fk_tags_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contact_tags (
    user_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    tag_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (contact_id, tag_id),
    KEY idx_contact_tags_contact_owner (contact_id, user_id),
    KEY idx_contact_tags_tag_owner (tag_id, user_id),
    CONSTRAINT fk_contact_tags_contact_owner
        FOREIGN KEY (contact_id, user_id) REFERENCES contacts (id, user_id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT,
    CONSTRAINT fk_contact_tags_tag_owner
        FOREIGN KEY (tag_id, user_id) REFERENCES tags (id, user_id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
