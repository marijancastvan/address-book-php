<?php

declare(strict_types=1);

/** SQL columns are fixed here so request input can never become an ORDER BY identifier. */
function contactSortColumns(): array
{
    return [
        'first_name' => ['column' => 'contacts.first_name', 'label' => 'Ime'],
        'last_name' => ['column' => 'contacts.last_name', 'label' => 'Prezime'],
        'phone' => ['column' => 'contacts.phone', 'label' => 'Telefon'],
        'email' => ['column' => 'contacts.email', 'label' => 'Email'],
    ];
}

function normalizeContactSorting(mixed $sortInput, mixed $directionInput): array
{
    $columns = contactSortColumns();
    $sort = is_string($sortInput) && array_key_exists($sortInput, $columns)
        ? $sortInput
        : 'first_name';
    $direction = is_string($directionInput) ? strtolower($directionInput) : '';
    if (!in_array($direction, ['asc', 'desc'], true)) {
        $direction = 'asc';
    }

    return ['sort' => $sort, 'direction' => $direction];
}

function contactOrderBySql(array $sorting): string
{
    $sorting = normalizeContactSorting($sorting['sort'] ?? null, $sorting['direction'] ?? null);
    $columns = contactSortColumns();
    $column = $columns[$sorting['sort']]['column'];
    $direction = strtoupper($sorting['direction']);
    $order = [$column . ' ' . $direction];

    if ($sorting['sort'] === 'first_name') {
        $order[] = 'contacts.last_name ASC';
    } elseif ($sorting['sort'] === 'last_name') {
        $order[] = 'contacts.first_name ASC';
    } else {
        $order[] = 'contacts.first_name ASC';
        $order[] = 'contacts.last_name ASC';
    }

    $order[] = 'contacts.id ASC';

    return implode(', ', $order);
}

/** Keep list context when delete/restore POST actions return to contacts.php. */
function contactListReturnContext(array $source): array
{
    $context = [];
    foreach (['search', 'city_id', 'tag_id', 'date_from', 'date_to'] as $key) {
        if (isset($source[$key]) && is_scalar($source[$key])) {
            $context[$key] = (string) $source[$key];
        }
    }

    $pageInput = filter_var($source['page'] ?? 1, FILTER_VALIDATE_INT);
    $context += normalizeContactSorting($source['sort'] ?? null, $source['direction'] ?? null);
    $context['page'] = is_int($pageInput) && $pageInput > 0 ? $pageInput : 1;

    return $context;
}

function contactFormValues(array $source): array
{
    $values = [];

    foreach (['first_name', 'last_name', 'phone', 'email', 'city_id'] as $field) {
        $value = $source[$field] ?? '';
        $values[$field] = is_scalar($value) ? trim((string) $value) : '';
    }

    return $values;
}

/** Return a conservative comparison key, or null when the phone format is unsupported. */
function normalizeContactPhone(string $phone): ?string
{
    $phone = trim($phone);
    if ($phone === '' || preg_match('/\A\+?[0-9](?:[0-9 ().-]*[0-9])?\z/D', $phone) !== 1) {
        return null;
    }

    $hasPlus = str_starts_with($phone, '+');
    $digits = str_replace([' ', '(', ')', '.', '-'], '', $hasPlus ? substr($phone, 1) : $phone);
    if ($digits === '' || strlen($digits) > 17) {
        return null;
    }

    if ($hasPlus) {
        if (strlen($digits) < 3 || strlen($digits) > 15 || $digits[0] === '0') {
            return null;
        }
        return 'I:' . $digits;
    }

    if (str_starts_with($digits, '00')) {
        $internationalDigits = substr($digits, 2);
        if (strlen($internationalDigits) < 3 || $internationalDigits[0] === '0' || strlen($internationalDigits) > 15) {
            return null;
        }
        return 'I:' . $internationalDigits;
    }

    return strlen($digits) >= 3 && strlen($digits) <= 15 ? 'L:' . $digits : null;
}

/** Compare valid ASCII email addresses case-insensitively without provider-specific rewriting. */
function normalizeContactEmail(string $email): ?string
{
    $email = trim($email);
    if ($email === ''
        || preg_match('/\A[\x00-\x7F]+\z/D', $email) !== 1
        || filter_var($email, FILTER_VALIDATE_EMAIL) === false
    ) {
        return null;
    }

    return strtolower($email);
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
        'deleted' => 'Kontakt je premešten u obrisane kontakte. Možete ga vratiti u bilo kom trenutku.',
        'already_deleted' => 'Kontakt se već nalazi u obrisanim kontaktima.',
        'restored' => 'Kontakt je vraćen u aktivne kontakte.',
        'already_active' => 'Kontakt je već aktivan.',
        default => null,
    };
}
