-- Append-only snapshots for changes to existing contacts.
CREATE TABLE contact_history_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    actor_email_snapshot VARCHAR(255) NOT NULL,
    action VARCHAR(40) NOT NULL,
    occurred_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_contact_history_event_id_user (id, user_id),
    KEY idx_contact_history_contact_owner (contact_id, user_id),
    KEY idx_contact_history_owner_contact_time (user_id, contact_id, occurred_at, id),
    CONSTRAINT fk_contact_history_contact_owner
        FOREIGN KEY (contact_id, user_id) REFERENCES contacts (id, user_id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contact_history_changes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    field_key VARCHAR(50) NOT NULL,
    field_label VARCHAR(100) NOT NULL,
    old_value LONGTEXT NOT NULL,
    new_value LONGTEXT NOT NULL,
    PRIMARY KEY (id),
    KEY idx_contact_history_changes_event (event_id, user_id, id),
    CONSTRAINT fk_contact_history_changes_event_owner
        FOREIGN KEY (event_id, user_id) REFERENCES contact_history_events (id, user_id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARACTER SET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
