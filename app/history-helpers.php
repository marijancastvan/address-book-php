<?php

declare(strict_types=1);

require_once __DIR__ . '/contact-helpers.php';

/** All contact, tag and city mutations for an account take this lock first. */
function lockUserForHistoryMutation(PDO $pdo, int $userId): string
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('The account lock requires an active transaction.');
    }

    $statement = $pdo->prepare('SELECT email FROM users WHERE id = :user_id FOR UPDATE');
    $statement->execute(['user_id' => $userId]);
    $email = $statement->fetchColumn();
    if (!is_string($email)) {
        throw new RuntimeException('The active account no longer exists.');
    }

    return $email;
}

/** Lock and load one contact plus the current names of its city and tags. */
function lockContactHistorySnapshot(PDO $pdo, int $userId, int $contactId): ?array
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('The contact snapshot requires an active transaction.');
    }

    $statement = $pdo->prepare(
        'SELECT contacts.id, contacts.first_name, contacts.last_name, contacts.phone, contacts.email,
                contacts.city_id, cities.name AS city_name
         FROM contacts
         INNER JOIN cities ON cities.id = contacts.city_id
         WHERE contacts.id = :contact_id AND contacts.user_id = :user_id
           AND contacts.deleted_at IS NULL
         FOR UPDATE'
    );
    $statement->execute(['contact_id' => $contactId, 'user_id' => $userId]);
    $contact = $statement->fetch();
    if ($contact === false) {
        return null;
    }

    $tagsByContact = getTagsForContacts($pdo, $userId, [$contact]);

    return [
        'id' => (int) $contact['id'],
        'first_name' => (string) $contact['first_name'],
        'last_name' => (string) $contact['last_name'],
        'phone' => (string) $contact['phone'],
        'email' => (string) $contact['email'],
        'city' => ['id' => (int) $contact['city_id'], 'name' => (string) $contact['city_name']],
        'tags' => sortContactTagSnapshots($tagsByContact[(int) $contact['id']] ?? []),
    ];
}

/** Soft-delete or restore one owned contact and record the transition atomically. */
function transitionContactDeletedState(PDO $pdo, int $userId, int $contactId, bool $delete): string
{
    $pdo->beginTransaction();

    try {
        $actorEmail = lockUserForHistoryMutation($pdo, $userId);
        $contactQuery = $pdo->prepare(
            'SELECT deleted_at FROM contacts WHERE id = :contact_id AND user_id = :user_id FOR UPDATE'
        );
        $contactQuery->execute(['contact_id' => $contactId, 'user_id' => $userId]);
        $contact = $contactQuery->fetch(PDO::FETCH_ASSOC);
        if ($contact === false) {
            $pdo->rollBack();
            return 'not_found';
        }

        $isDeleted = $contact['deleted_at'] !== null;
        if ($isDeleted === $delete) {
            $pdo->rollBack();
            return 'unchanged';
        }

        $updateSql = $delete
            ? 'UPDATE contacts SET deleted_at = UTC_TIMESTAMP() WHERE id = :contact_id AND user_id = :user_id AND deleted_at IS NULL'
            : 'UPDATE contacts SET deleted_at = NULL WHERE id = :contact_id AND user_id = :user_id AND deleted_at IS NOT NULL';
        $update = $pdo->prepare($updateSql);
        $update->execute(['contact_id' => $contactId, 'user_id' => $userId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('The contact status changed during the operation.');
        }

        $oldStatus = $delete ? 'Aktivan' : 'Obrisan';
        $newStatus = $delete ? 'Obrisan' : 'Aktivan';
        recordContactHistoryEvent(
            $pdo,
            $userId,
            $contactId,
            $userId,
            $actorEmail,
            $delete ? 'contact_deleted' : 'contact_restored',
            ['status' => ['label' => 'Status kontakta', 'old' => $oldStatus, 'new' => $newStatus]],
        );

        $pdo->commit();
        return 'changed';
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/** @param array<int, array{id: int, name: string}> $tags
 *  @return array<int, array{id: int, name: string}>
 */
function sortContactTagSnapshots(array $tags): array
{
    usort($tags, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

    return array_values($tags);
}

/** Load checked tag IDs as stable snapshots belonging to the current account. */
function getContactTagSnapshotsForUser(PDO $pdo, int $userId, array $tagIds): array
{
    $tagIds = array_values(array_unique(array_map('intval', $tagIds)));
    sort($tagIds, SORT_NUMERIC);
    if ($tagIds === []) {
        return [];
    }

    $placeholders = [];
    $parameters = ['user_id' => $userId];
    foreach ($tagIds as $index => $tagId) {
        $placeholder = 'tag_id_' . $index;
        $placeholders[] = ':' . $placeholder;
        $parameters[$placeholder] = $tagId;
    }

    $statement = $pdo->prepare(
        'SELECT id, name FROM tags
         WHERE user_id = :user_id AND id IN (' . implode(', ', $placeholders) . ')
         ORDER BY id ASC'
    );
    $statement->execute($parameters);
    $snapshots = array_map(static fn (array $tag): array => [
        'id' => (int) $tag['id'],
        'name' => (string) $tag['name'],
    ], $statement->fetchAll());

    if (count($snapshots) !== count($tagIds)) {
        throw new RuntimeException('A selected tag changed during the contact update.');
    }

    return $snapshots;
}

/** Load each contact's current tag snapshots in one query. */
function getContactTagSnapshotsByContactIds(PDO $pdo, int $userId, array $contactIds): array
{
    $contactIds = array_values(array_unique(array_map('intval', $contactIds)));
    if ($contactIds === []) {
        return [];
    }
    $contacts = array_map(static fn (int $id): array => ['id' => $id], $contactIds);
    $tagsByContact = getTagsForContacts($pdo, $userId, $contacts);
    foreach ($tagsByContact as $contactId => $tags) {
        $tagsByContact[$contactId] = sortContactTagSnapshots($tags);
    }
    return $tagsByContact;
}

function contactHistoryStoredValue(mixed $value): string
{
    if (is_array($value)) {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($encoded)) {
            throw new RuntimeException('Could not encode a contact history snapshot.');
        }

        return $encoded;
    }

    return is_scalar($value) ? (string) $value : '';
}

/** @param array<string, array{label: string, old: mixed, new: mixed}> $changes */
function recordContactHistoryEvent(
    PDO $pdo,
    int $userId,
    int $contactId,
    int $actorUserId,
    string $actorEmail,
    string $action,
    array $changes,
): void {
    if ($changes === []) {
        return;
    }
    if (!$pdo->inTransaction()) {
        throw new LogicException('Contact history must be written in the owning transaction.');
    }

    $event = $pdo->prepare(
        'INSERT INTO contact_history_events
            (user_id, contact_id, actor_user_id, actor_email_snapshot, action, occurred_at)
         VALUES (:user_id, :contact_id, :actor_user_id, :actor_email, :action, UTC_TIMESTAMP())'
    );
    $event->execute([
        'user_id' => $userId,
        'contact_id' => $contactId,
        'actor_user_id' => $actorUserId,
        'actor_email' => $actorEmail,
        'action' => $action,
    ]);
    $eventId = (int) $pdo->lastInsertId();

    $change = $pdo->prepare(
        'INSERT INTO contact_history_changes
            (event_id, user_id, field_key, field_label, old_value, new_value)
         VALUES (:event_id, :user_id, :field_key, :field_label, :old_value, :new_value)'
    );
    foreach ($changes as $fieldKey => $snapshot) {
        $change->execute([
            'event_id' => $eventId,
            'user_id' => $userId,
            'field_key' => $fieldKey,
            'field_label' => $snapshot['label'],
            'old_value' => contactHistoryStoredValue($snapshot['old']),
            'new_value' => contactHistoryStoredValue($snapshot['new']),
        ]);
    }
}
