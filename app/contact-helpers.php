<?php

declare(strict_types=1);

function contactFormValues(array $source): array
{
    $values = [];

    foreach (['first_name', 'last_name', 'phone', 'email', 'city_id'] as $field) {
        $value = $source[$field] ?? '';
        $values[$field] = is_scalar($value) ? trim((string) $value) : '';
    }

    return $values;
}

function contactCharacterLength(string $value): int
{
    $count = preg_match_all('/./us', $value);

    return $count === false ? 0 : $count;
}

/** Validate a date-only filter without applying PHP's configured timezone. */
function validateContactDateFilter(mixed $value): array
{
    if ($value === null || $value === '') {
        return ['value' => null, 'error' => null];
    }

    if (!is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value) !== 1) {
        return ['value' => null, 'error' => 'Datum mora biti u formatu YYYY-MM-DD.'];
    }

    [$year, $month, $day] = array_map('intval', explode('-', $value));
    if (!checkdate($month, $day, $year)) {
        return ['value' => null, 'error' => 'Unesite postojeći datum.'];
    }

    return ['value' => $value, 'error' => null];
}

function nextContactDateBoundary(string $date): string
{
    $utcDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
    if (!$utcDate instanceof DateTimeImmutable || $date === '9999-12-31') {
        throw new InvalidArgumentException('Date boundary is outside the supported calendar range.');
    }

    return $utcDate->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
}

function validateContactValues(array $values): array
{
    $errors = [];
    $limits = [
        'first_name' => ['required' => 'Ime je obavezno.', 'label' => 'Ime', 'max' => 100],
        'last_name' => ['required' => 'Prezime je obavezno.', 'label' => 'Prezime', 'max' => 100],
        'phone' => ['required' => 'Telefon je obavezan.', 'label' => 'Telefon', 'max' => 50],
        'email' => ['required' => 'Email je obavezan.', 'label' => 'Email', 'max' => 255],
    ];

    foreach ($limits as $field => $rule) {
        $value = $values[$field] ?? '';

        if ($value === '') {
            $errors[$field] = $rule['required'];
        } elseif (preg_match('//u', $value) !== 1) {
            $errors[$field] = $rule['label'] . ' sadrži nevažeći tekst.';
        } elseif (contactCharacterLength($value) > $rule['max']) {
            $errors[$field] = $rule['label'] . ' može imati najviše ' . $rule['max'] . ' karaktera.';
        }
    }

    if (($values['email'] ?? '') !== ''
        && preg_match('//u', $values['email']) === 1
        && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false
    ) {
        $errors['email'] = 'Unesite ispravnu email adresu.';
    }

    $cityValue = $values['city_id'] ?? '';
    $cityId = filter_var($cityValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($cityValue === '') {
        $errors['city_id'] = 'Izaberite grad.';
    } elseif ($cityId === false) {
        $errors['city_id'] = 'Izaberite grad.';
    }

    return $errors;
}

function getCitiesForUser(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare('SELECT id, name FROM cities WHERE user_id = :user_id ORDER BY name');
    $statement->execute(['user_id' => $userId]);

    return $statement->fetchAll();
}

function cityBelongsToUser(PDO $pdo, int $cityId, int $userId): bool
{
    $statement = $pdo->prepare('SELECT id FROM cities WHERE id = :city_id AND user_id = :user_id LIMIT 1');
    $statement->execute(['city_id' => $cityId, 'user_id' => $userId]);

    return $statement->fetch() !== false;
}

function contactTagIdsFromInput(array $source): array
{
    if (!array_key_exists('tag_ids', $source)) {
        return ['ids' => [], 'error' => null];
    }

    if (!is_array($source['tag_ids'])) {
        return ['ids' => [], 'error' => 'Izaberite samo važeće tagove sa liste.'];
    }

    $ids = [];
    $hasInvalidId = false;
    foreach ($source['tag_ids'] as $value) {
        if (!is_int($value) && !is_string($value)) {
            $hasInvalidId = true;
            continue;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            $hasInvalidId = true;
            continue;
        }

        $ids[(string) $id] = $id;
    }

    return [
        'ids' => array_values($ids),
        'error' => $hasInvalidId ? 'Izaberite samo važeće tagove sa liste.' : null,
    ];
}

function selectedContactTagIds(mixed $values): array
{
    if (!is_array($values)) {
        return [];
    }

    $ids = [];
    foreach ($values as $value) {
        if (!is_int($value) && !is_string($value)) {
            continue;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id !== false) {
            $ids[(string) $id] = $id;
        }
    }

    return array_values($ids);
}

function contactTagsBelongToUser(PDO $pdo, int $userId, array $tagIds): bool
{
    if ($tagIds === []) {
        return true;
    }

    $placeholders = [];
    $parameters = ['user_id' => $userId];
    foreach (array_values($tagIds) as $index => $tagId) {
        $placeholder = 'tag_id_' . $index;
        $placeholders[] = ':' . $placeholder;
        $parameters[$placeholder] = $tagId;
    }

    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM tags WHERE user_id = :user_id AND id IN (' . implode(', ', $placeholders) . ')'
    );
    $statement->execute($parameters);

    return (int) $statement->fetchColumn() === count($tagIds);
}

function getTagsForUser(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare('SELECT id, name FROM tags WHERE user_id = :user_id ORDER BY name ASC');
    $statement->execute(['user_id' => $userId]);

    return $statement->fetchAll();
}

/** @return array<int, array<int, array{id: int, name: string}>> */
function getTagsForContacts(PDO $pdo, int $userId, array $contacts): array
{
    $contactIds = array_values(array_unique(array_map(
        static fn (array $contact): int => (int) $contact['id'],
        $contacts,
    )));
    if ($contactIds === []) {
        return [];
    }

    $placeholders = [];
    $parameters = ['user_id' => $userId];
    foreach ($contactIds as $index => $contactId) {
        $placeholder = 'contact_id_' . $index;
        $placeholders[] = ':' . $placeholder;
        $parameters[$placeholder] = $contactId;
    }

    $statement = $pdo->prepare(
        'SELECT contact_tags.contact_id, tags.id AS tag_id, tags.name AS tag_name
         FROM contact_tags
         INNER JOIN tags ON tags.id = contact_tags.tag_id AND tags.user_id = contact_tags.user_id
         WHERE contact_tags.user_id = :user_id
           AND contact_tags.contact_id IN (' . implode(', ', $placeholders) . ')
         ORDER BY tags.name ASC'
    );
    $statement->execute($parameters);

    $tagsByContact = [];
    foreach ($statement->fetchAll() as $row) {
        $contactId = (int) $row['contact_id'];
        $tagsByContact[$contactId][] = [
            'id' => (int) $row['tag_id'],
            'name' => (string) $row['tag_name'],
        ];
    }

    return $tagsByContact;
}

function synchronizeContactTags(PDO $pdo, int $userId, int $contactId, array $tagIds): void
{
    $delete = $pdo->prepare(
        'DELETE FROM contact_tags WHERE user_id = :user_id AND contact_id = :contact_id'
    );
    $delete->execute(['user_id' => $userId, 'contact_id' => $contactId]);

    if ($tagIds === []) {
        return;
    }

    $insert = $pdo->prepare(
        'INSERT INTO contact_tags (user_id, contact_id, tag_id)
         VALUES (:user_id, :contact_id, :tag_id)'
    );
    foreach ($tagIds as $tagId) {
        $insert->execute([
            'user_id' => $userId,
            'contact_id' => $contactId,
            'tag_id' => $tagId,
        ]);
    }
}

function contactSuccessMessage(): ?string
{
    return match ($_GET['success'] ?? '') {
        'created' => 'Kontakt je uspešno dodat.',
        'updated' => 'Kontakt je uspešno izmenjen.',
        'deleted' => 'Kontakt je uspešno obrisan.',
        default => null,
    };
}
