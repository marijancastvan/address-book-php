<?php

declare(strict_types=1);

require_once __DIR__ . '/history-helpers.php';

/** Load the exact contact, city and tag state used by the merge review. */
function loadContactMergeSnapshots(PDO $pdo, int $userId, array $contactIds, bool $forUpdate = false): ?array
{
    $contactIds = array_values(array_unique(array_map('intval', $contactIds)));
    sort($contactIds, SORT_NUMERIC);
    if (count($contactIds) !== 2 || $contactIds[0] < 1 || $contactIds[0] === $contactIds[1]) {
        return null;
    }

    $rows = [];
    foreach ($contactIds as $contactId) {
        $statement = $pdo->prepare(
            'SELECT id, user_id, first_name, last_name, phone, email, phone_normalized,
                    email_normalized, city_id, created_at, updated_at, deleted_at
             FROM contacts WHERE id = :contact_id AND user_id = :user_id'
            . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute(['contact_id' => $contactId, 'user_id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            $rows[] = $row;
        }
    }
    if (count($rows) !== 2) {
        return null;
    }

    $cityIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['city_id'], $rows)));
    $cityNames = [];
    if ($cityIds !== []) {
        $cityPlaceholders = [];
        $cityParameters = ['user_id' => $userId];
        foreach ($cityIds as $index => $cityId) {
            $placeholder = 'city_id_' . $index;
            $cityPlaceholders[] = ':' . $placeholder;
            $cityParameters[$placeholder] = $cityId;
        }
        $cityStatement = $pdo->prepare(
            'SELECT id, name FROM cities WHERE user_id = :user_id AND id IN ('
            . implode(', ', $cityPlaceholders) . ')'
        );
        $cityStatement->execute($cityParameters);
        foreach ($cityStatement->fetchAll(PDO::FETCH_ASSOC) as $city) {
            $cityNames[(int) $city['id']] = (string) $city['name'];
        }
    }
    if (count($cityNames) !== count($cityIds)) {
        return null;
    }

    $tagsByContact = getContactTagSnapshotsByContactIds(
        $pdo,
        $userId,
        array_map(static fn (array $row): int => (int) $row['id'], $rows),
    );
    $snapshots = [];
    foreach ($rows as $row) {
        if ($row['deleted_at'] !== null) {
            return null;
        }
        $contactId = (int) $row['id'];
        $snapshots[$contactId] = [
            'id' => $contactId,
            'first_name' => (string) $row['first_name'],
            'last_name' => (string) $row['last_name'],
            'phone' => (string) $row['phone'],
            'email' => (string) $row['email'],
            'phone_normalized' => $row['phone_normalized'] === null ? null : (string) $row['phone_normalized'],
            'email_normalized' => $row['email_normalized'] === null ? null : (string) $row['email_normalized'],
            'city' => ['id' => (int) $row['city_id'], 'name' => $cityNames[(int) $row['city_id']]],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'tags' => sortContactTagSnapshots($tagsByContact[$contactId] ?? []),
        ];
    }

    return $snapshots;
}

function contactMergeSnapshotHash(array $snapshots, string $sessionSecret): string
{
    ksort($snapshots, SORT_NUMERIC);
    $encoded = json_encode($snapshots, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($encoded)) {
        throw new RuntimeException('Could not encode the contact merge review.');
    }

    return hash_hmac('sha256', $encoded, $sessionSecret);
}

function contactMergeHasDuplicate(array $first, array $second): bool
{
    $firstPhone = normalizeContactPhone($first['phone']);
    $secondPhone = normalizeContactPhone($second['phone']);
    $firstEmail = normalizeContactEmail($first['email']);
    $secondEmail = normalizeContactEmail($second['email']);

    return ($firstPhone !== null && $firstPhone === $secondPhone)
        || ($firstEmail !== null && $firstEmail === $secondEmail);
}

/** Merge one reviewed pair. This function owns its transaction. */
function mergeDuplicateContacts(
    PDO $pdo,
    int $userId,
    int $firstId,
    int $secondId,
    int $keepId,
    string $reviewHash,
    string $sessionSecret,
    array $fieldSources,
): string {
    if ($firstId < 1 || $secondId < 1 || $firstId === $secondId
        || !in_array($keepId, [$firstId, $secondId], true)
    ) {
        return 'invalid';
    }

    $pdo->beginTransaction();
    try {
        $actorEmail = lockUserForHistoryMutation($pdo, $userId);
        $snapshots = loadContactMergeSnapshots($pdo, $userId, [$firstId, $secondId], true);
        if ($snapshots === null) {
            $pdo->rollBack();
            return 'not_available';
        }
        if (!hash_equals(contactMergeSnapshotHash($snapshots, $sessionSecret), $reviewHash)) {
            $pdo->rollBack();
            return 'stale';
        }

        $first = $snapshots[$firstId];
        $second = $snapshots[$secondId];
        if (!contactMergeHasDuplicate($first, $second)) {
            $pdo->rollBack();
            return 'not_duplicates';
        }

        $allowedFields = ['first_name', 'last_name', 'phone', 'email', 'city_id'];
        if (count($fieldSources) !== count($allowedFields)
            || array_diff(array_keys($fieldSources), $allowedFields) !== []
            || array_diff($allowedFields, array_keys($fieldSources)) !== []
        ) {
            $pdo->rollBack();
            return 'invalid_selection';
        }

        $sourceValues = [];
        $fieldLabels = [
            'first_name' => 'Ime',
            'last_name' => 'Prezime',
            'phone' => 'Telefon',
            'email' => 'E-mail',
        ];
        foreach ($fieldLabels as $field => $label) {
            $sourceId = filter_var($fieldSources[$field] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($sourceId === false || !in_array($sourceId, [$firstId, $secondId], true)) {
                $pdo->rollBack();
                return 'invalid_selection';
            }
            $sourceValues[$field] = $snapshots[$sourceId][$field];
        }

        $citySourceId = filter_var($fieldSources['city_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($citySourceId === false || !in_array($citySourceId, [$firstId, $secondId], true)) {
            $pdo->rollBack();
            return 'invalid_selection';
        }
        $cityId = $snapshots[$citySourceId]['city']['id'];
        if (!cityBelongsToUser($pdo, $cityId, $userId)) {
            $pdo->rollBack();
            return 'invalid_selection';
        }
        $sourceValues['city_id'] = (string) $cityId;
        $errors = validateContactValues($sourceValues);
        if ($errors !== []) {
            $pdo->rollBack();
            return 'invalid_selection';
        }

        $kept = $snapshots[$keepId];
        $removedId = $keepId === $firstId ? $secondId : $firstId;
        $removed = $snapshots[$removedId];
        $oldTagIds = array_column($kept['tags'], 'id');
        $mergedTagIds = array_values(array_unique(array_merge(
            $oldTagIds,
            array_column($removed['tags'], 'id'),
        )));
        sort($mergedTagIds, SORT_NUMERIC);
        if (!contactTagsBelongToUser($pdo, $userId, $mergedTagIds)) {
            $pdo->rollBack();
            return 'stale';
        }
        $mergedTags = getContactTagSnapshotsForUser($pdo, $userId, $mergedTagIds);

        // Move comment rows in place: origin_contact_id stays immutable, so repeated
        // merges never clone or lose their original provenance.
        $commentCountQuery = $pdo->prepare(
            'SELECT COUNT(*) FROM contact_comments WHERE user_id = :user_id AND contact_id = :contact_id'
        );
        $commentCountQuery->execute(['user_id' => $userId, 'contact_id' => $removedId]);
        $transferredCommentCount = (int) $commentCountQuery->fetchColumn();

        $updates = [
            'first_name' => $sourceValues['first_name'],
            'last_name' => $sourceValues['last_name'],
            'phone' => $sourceValues['phone'],
            'email' => $sourceValues['email'],
            'phone_normalized' => normalizeContactPhone($sourceValues['phone']),
            'email_normalized' => normalizeContactEmail($sourceValues['email']),
            'city_id' => $cityId,
        ];
        $changes = [];
        foreach ($fieldLabels as $field => $label) {
            if ($kept[$field] !== $updates[$field]) {
                $changes[$field] = ['label' => $label, 'old' => $kept[$field], 'new' => $updates[$field]];
            }
        }
        $selectedCity = $snapshots[$citySourceId]['city'];
        if ($kept['city'] !== $selectedCity) {
            $changes['city'] = ['label' => 'Grad', 'old' => $kept['city'], 'new' => $selectedCity];
        }
        if ($oldTagIds !== $mergedTagIds) {
            $changes['tags'] = ['label' => 'Tagovi', 'old' => $kept['tags'], 'new' => $mergedTags];
        }

        $update = $pdo->prepare(
            'UPDATE contacts
             SET first_name = :first_name, last_name = :last_name, phone = :phone,
                 email = :email, phone_normalized = :phone_normalized,
                 email_normalized = :email_normalized, city_id = :city_id,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :contact_id AND user_id = :user_id AND deleted_at IS NULL'
        );
        $update->execute($updates + ['contact_id' => $keepId, 'user_id' => $userId]);
        synchronizeContactTags($pdo, $userId, $keepId, $mergedTagIds);

        $delete = $pdo->prepare(
            'UPDATE contacts SET deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = :contact_id AND user_id = :user_id AND deleted_at IS NULL'
        );
        $delete->execute(['contact_id' => $removedId, 'user_id' => $userId]);
        if ($delete->rowCount() !== 1) {
            throw new RuntimeException('The removed contact changed during merge.');
        }

        $transferComments = $pdo->prepare(
            'UPDATE contact_comments SET contact_id = :kept_contact_id
             WHERE user_id = :user_id AND contact_id = :removed_contact_id'
        );
        $transferComments->execute([
            'kept_contact_id' => $keepId,
            'user_id' => $userId,
            'removed_contact_id' => $removedId,
        ]);
        if ($transferComments->rowCount() !== $transferredCommentCount) {
            throw new RuntimeException('The contact comments changed during merge.');
        }

        $mergeDescription = 'Kontakt ' . $sourceValues['first_name'] . ' ' . $sourceValues['last_name']
            . ' (ID ' . $keepId . ') ostaje aktivan; kontakt ' . $removed['first_name'] . ' '
            . $removed['last_name'] . ' (ID ' . $removedId . ') prelazi u obrisane kontakte. '
            . 'Broj prenetih komentara: ' . $transferredCommentCount . '.';
        $changes['merge'] = ['label' => 'Spajanje kontakata', 'old' => 'Nije spojeno', 'new' => $mergeDescription];
        $changes['comments_transferred'] = [
            'label' => 'Komentari preneti',
            'old' => '0',
            'new' => (string) $transferredCommentCount,
        ];
        recordContactHistoryEvent(
            $pdo,
            $userId,
            $keepId,
            $userId,
            $actorEmail,
            'contact_merge_kept',
            $changes,
        );
        recordContactHistoryEvent(
            $pdo,
            $userId,
            $removedId,
            $userId,
            $actorEmail,
            'contact_merge_removed',
            [
                'status' => ['label' => 'Status kontakta', 'old' => 'Aktivan', 'new' => 'Obrisan spajanjem'],
                'merge' => ['label' => 'Spajanje kontakata', 'old' => 'Nije spojeno', 'new' => $mergeDescription],
            ],
        );

        $pdo->commit();

        return 'merged';
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
