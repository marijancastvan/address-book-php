<?php

declare(strict_types=1);

require_once __DIR__ . '/contact-helpers.php';
require_once __DIR__ . '/history-helpers.php';

const API_CONTACT_MAX_BODY_BYTES = 65536;

function apiContactInvalidParameter(string $message): never
{
    apiErrorResponse(400, 'invalid_parameter', $message);
}

function apiContactQueryString(array $query, string $name, string $default = ''): string
{
    if (!array_key_exists($name, $query)) {
        return $default;
    }
    if (!is_string($query[$name]) || preg_match('//u', $query[$name]) !== 1) {
        apiContactInvalidParameter('Parametar „' . $name . '“ mora biti jedna ispravna tekstualna vrednost.');
    }

    return $query[$name];
}

function apiContactQueryPositiveInt(array $query, string $name, ?int $default = null): ?int
{
    if (!array_key_exists($name, $query)) {
        return $default;
    }

    $value = apiContactQueryString($query, $name);
    if (preg_match('/\A[0-9]+\z/D', $value) !== 1) {
        apiContactInvalidParameter('Parametar „' . $name . '“ mora biti pozitivan ceo broj.');
    }

    $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!is_int($integer)) {
        apiContactInvalidParameter('Parametar „' . $name . '“ mora biti pozitivan ceo broj.');
    }

    return $integer;
}

/** Validate and resolve all filters for the list and COUNT queries. */
function apiContactListFilters(PDO $pdo, int $userId, array $query): array
{
    $view = apiContactQueryString($query, 'view', 'active');
    if (!in_array($view, ['active', 'deleted'], true)) {
        apiContactInvalidParameter('Parametar „view“ može biti „active“ ili „deleted“.');
    }

    $search = trim(apiContactQueryString($query, 'search'));
    $cityId = apiContactQueryPositiveInt($query, 'city_id');
    $tagId = apiContactQueryPositiveInt($query, 'tag_id');
    $page = apiContactQueryPositiveInt($query, 'page', 1);
    $perPage = apiContactQueryPositiveInt($query, 'per_page', 25);
    if ($perPage > 100) {
        apiContactInvalidParameter('Parametar „per_page“ ne može biti veći od 100.');
    }
    if ($page > intdiv(PHP_INT_MAX, $perPage)) {
        apiContactInvalidParameter('Parametar „page“ je van podržanog opsega.');
    }

    $sort = apiContactQueryString($query, 'sort', 'first_name');
    if (!array_key_exists($sort, contactSortColumns())) {
        apiContactInvalidParameter('Parametar „sort“ mora biti first_name, last_name, phone ili email.');
    }
    $direction = strtolower(apiContactQueryString($query, 'direction', 'asc'));
    if (!in_array($direction, ['asc', 'desc'], true)) {
        apiContactInvalidParameter('Parametar „direction“ mora biti asc ili desc.');
    }

    $dateFrom = validateContactDateFilter(apiContactQueryString($query, 'date_from'));
    $dateTo = validateContactDateFilter(apiContactQueryString($query, 'date_to'));
    if ($dateFrom['error'] !== null || $dateTo['error'] !== null) {
        apiContactInvalidParameter($dateFrom['error'] ?? $dateTo['error']);
    }
    if ($dateFrom['value'] !== null && $dateTo['value'] !== null && $dateFrom['value'] > $dateTo['value']) {
        apiContactInvalidParameter('Datum „date_from“ ne može biti posle datuma „date_to“.');
    }

    if ($cityId !== null && !cityBelongsToUser($pdo, $cityId, $userId)) {
        apiContactInvalidParameter('Parametar „city_id“ nije važeći ili nije dostupan ovom nalogu.');
    }
    if ($tagId !== null && !contactTagsBelongToUser($pdo, $userId, [$tagId])) {
        apiContactInvalidParameter('Parametar „tag_id“ nije važeći ili nije dostupan ovom nalogu.');
    }

    return [
        'view' => $view,
        'search' => $search,
        'city_id' => $cityId,
        'tag_id' => $tagId,
        'date_from' => $dateFrom['value'],
        'date_to' => $dateTo['value'],
        'page' => $page,
        'per_page' => $perPage,
        'sorting' => ['sort' => $sort, 'direction' => $direction],
    ];
}

/** Build one owner/status/filter predicate used by both list and count. */
function apiContactWhereClause(array $filters, int $userId): array
{
    $where = [
        'contacts.user_id = :user_id',
        'contacts.deleted_at ' . ($filters['view'] === 'deleted' ? 'IS NOT NULL' : 'IS NULL'),
    ];
    $parameters = ['user_id' => $userId];

    if ($filters['search'] !== '') {
        $where[] = '(contacts.first_name LIKE :search_first_name
                     OR contacts.last_name LIKE :search_last_name
                     OR contacts.phone LIKE :search_phone
                     OR contacts.email LIKE :search_email)';
        $pattern = '%' . $filters['search'] . '%';
        $parameters += [
            'search_first_name' => $pattern,
            'search_last_name' => $pattern,
            'search_phone' => $pattern,
            'search_email' => $pattern,
        ];
    }
    if ($filters['city_id'] !== null) {
        $where[] = 'contacts.city_id = :city_id';
        $parameters['city_id'] = $filters['city_id'];
    }
    if ($filters['tag_id'] !== null) {
        $where[] = 'EXISTS (
            SELECT 1 FROM contact_tags
            WHERE contact_tags.contact_id = contacts.id
              AND contact_tags.user_id = contacts.user_id
              AND contact_tags.tag_id = :tag_id
        )';
        $parameters['tag_id'] = $filters['tag_id'];
    }
    if ($filters['date_from'] !== null) {
        $where[] = 'contacts.created_at >= :date_from';
        $parameters['date_from'] = $filters['date_from'] . ' 00:00:00';
    }
    if ($filters['date_to'] !== null && $filters['date_to'] !== '9999-12-31') {
        $where[] = 'contacts.created_at < :date_to_exclusive';
        $parameters['date_to_exclusive'] = nextContactDateBoundary($filters['date_to']);
    }

    return ['sql' => ' WHERE ' . implode(' AND ', $where), 'parameters' => $parameters];
}

function apiContactBindParameters(PDOStatement $statement, array $parameters): void
{
    foreach ($parameters as $name => $value) {
        $statement->bindValue(
            ':' . $name,
            $value,
            is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR,
        );
    }
}

function apiContactPayload(array $contact, array $tags): array
{
    return [
        'id' => (int) $contact['id'],
        'first_name' => (string) $contact['first_name'],
        'last_name' => (string) $contact['last_name'],
        'phone' => (string) $contact['phone'],
        'email' => (string) $contact['email'],
        'city' => [
            'id' => (int) $contact['city_id'],
            'name' => (string) $contact['city_name'],
        ],
        'tags' => array_map(static fn (array $tag): array => [
            'id' => (int) $tag['id'],
            'name' => (string) $tag['name'],
        ], $tags),
        'created_at' => (string) $contact['created_at'],
        'deleted_at' => $contact['deleted_at'] === null ? null : (string) $contact['deleted_at'],
    ];
}

function apiContactReadById(PDO $pdo, int $userId, int $contactId): ?array
{
    $statement = $pdo->prepare(
        'SELECT contacts.id, contacts.first_name, contacts.last_name, contacts.phone, contacts.email,
                contacts.city_id, cities.name AS city_name, contacts.created_at, contacts.deleted_at
         FROM contacts
         INNER JOIN cities ON cities.id = contacts.city_id AND cities.user_id = contacts.user_id
         WHERE contacts.id = :contact_id AND contacts.user_id = :user_id
         LIMIT 1'
    );
    $statement->execute(['contact_id' => $contactId, 'user_id' => $userId]);
    $contact = $statement->fetch(PDO::FETCH_ASSOC);
    if ($contact === false) {
        return null;
    }

    $tagsByContact = getTagsForContacts($pdo, $userId, [$contact]);

    return apiContactPayload($contact, $tagsByContact[(int) $contact['id']] ?? []);
}

function apiContactReadJsonObject(): stdClass
{
    $contentType = trim((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    $contentTypeParts = array_map('trim', explode(';', $contentType));
    if (strtolower((string) array_shift($contentTypeParts)) !== 'application/json') {
        apiErrorResponse(415, 'unsupported_media_type', 'Content-Type mora biti application/json.');
    }

    foreach ($contentTypeParts as $parameter) {
        if (preg_match('/\Acharset\s*=\s*(?:"utf-8"|utf-8)\z/iD', $parameter) !== 1) {
            apiErrorResponse(415, 'unsupported_media_type', 'Podržan je samo UTF-8 JSON sadržaj.');
        }
    }

    $contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
    if (is_string($contentLength)
        && preg_match('/\A[0-9]+\z/D', $contentLength) === 1
        && (float) $contentLength > API_CONTACT_MAX_BODY_BYTES
    ) {
        apiErrorResponse(413, 'payload_too_large', 'JSON zahtev je veći od dozvoljenih 65536 bajtova.');
    }

    $stream = fopen('php://input', 'rb');
    if ($stream === false) {
        apiErrorResponse(400, 'invalid_json', 'JSON telo zahteva nije moguće pročitati.');
    }

    $rawBody = '';
    while (!feof($stream) && strlen($rawBody) <= API_CONTACT_MAX_BODY_BYTES) {
        $chunk = fread($stream, min(8192, API_CONTACT_MAX_BODY_BYTES + 1 - strlen($rawBody)));
        if (!is_string($chunk) || $chunk === '') {
            break;
        }
        $rawBody .= $chunk;
    }
    fclose($stream);

    if (strlen($rawBody) > API_CONTACT_MAX_BODY_BYTES) {
        apiErrorResponse(413, 'payload_too_large', 'JSON zahtev je veći od dozvoljenih 65536 bajtova.');
    }

    try {
        $body = json_decode($rawBody, false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        apiErrorResponse(400, 'invalid_json', 'JSON telo zahteva nije ispravno.');
    }
    if (!$body instanceof stdClass) {
        apiErrorResponse(400, 'invalid_json', 'JSON telo zahteva mora biti objekat.');
    }

    return $body;
}

function apiContactValidationErrorResponse(array $fieldErrors): never
{
    $messages = array_values(array_unique(array_filter($fieldErrors, 'is_string')));
    apiJsonResponse(422, [
        'error' => [
            'code' => 'validation_error',
            'message' => implode(' ', $messages),
            'fields' => $fieldErrors,
        ],
    ]);
}

/** Validate JSON field types and return only values that may be merged or inserted. */
function apiContactSubmittedValues(stdClass $body, bool $partial): array
{
    $input = get_object_vars($body);
    $allowedFields = ['first_name', 'last_name', 'phone', 'email', 'city_id', 'tag_ids'];
    $errors = [];
    foreach (array_keys($input) as $field) {
        if (!in_array($field, $allowedFields, true)) {
            $errors['_request'] = 'Zahtev sadrži nedozvoljeno polje: ' . $field . '.';
        }
    }
    if ($partial && $input === []) {
        $errors['_request'] = 'PATCH telo mora sadržati bar jedno polje.';
    }

    $values = [];
    foreach (['first_name', 'last_name', 'phone', 'email'] as $field) {
        if (array_key_exists($field, $input)) {
            if (!is_string($input[$field])) {
                $errors[$field] = 'Polje mora biti tekstualna vrednost.';
            } else {
                $values[$field] = trim($input[$field]);
            }
        } elseif (!$partial) {
            $values[$field] = '';
        }
    }

    if (array_key_exists('city_id', $input)) {
        if (!is_int($input['city_id']) || $input['city_id'] < 1) {
            $errors['city_id'] = 'Polje city_id mora biti pozitivan ceo broj.';
        } else {
            $values['city_id'] = (string) $input['city_id'];
        }
    } elseif (!$partial) {
        $values['city_id'] = '';
    }

    $tagIdsProvided = array_key_exists('tag_ids', $input);
    $tagIds = [];
    if ($tagIdsProvided) {
        if (!is_array($input['tag_ids']) || !array_is_list($input['tag_ids'])) {
            $errors['tag_ids'] = 'Polje tag_ids mora biti JSON lista pozitivnih celih brojeva.';
        } else {
            foreach ($input['tag_ids'] as $tagId) {
                if (!is_int($tagId) || $tagId < 1) {
                    $errors['tag_ids'] = 'Svaki tag_ids element mora biti pozitivan ceo broj.';
                    break;
                }
                $tagIds[$tagId] = $tagId;
            }
            $tagIds = array_values($tagIds);
            sort($tagIds, SORT_NUMERIC);
        }
    }

    return [
        'values' => $values,
        'tag_ids' => $tagIds,
        'tag_ids_provided' => $tagIdsProvided,
        'errors' => $errors,
    ];
}

function apiCreateContact(PDO $pdo, int $userId, stdClass $body): array
{
    $submitted = apiContactSubmittedValues($body, false);
    $values = array_replace([
        'first_name' => '',
        'last_name' => '',
        'phone' => '',
        'email' => '',
        'city_id' => '',
    ], $submitted['values']);
    $errors = $submitted['errors'] + validateContactValues($values);
    if ($errors !== []) {
        apiContactValidationErrorResponse($errors);
    }

    $cityId = (int) $values['city_id'];
    $tagIds = $submitted['tag_ids'];
    $pdo->beginTransaction();
    try {
        lockUserForHistoryMutation($pdo, $userId);
        if (!cityBelongsToUser($pdo, $cityId, $userId)) {
            $pdo->rollBack();
            apiContactValidationErrorResponse(['city_id' => 'Izabrani grad nije dostupan ovom nalogu.']);
        }
        if (!contactTagsBelongToUser($pdo, $userId, $tagIds)) {
            $pdo->rollBack();
            apiContactValidationErrorResponse(['tag_ids' => 'Jedan ili više tagova nisu dostupni ovom nalogu.']);
        }

        $insert = $pdo->prepare(
            'INSERT INTO contacts
                (user_id, first_name, last_name, phone, email, phone_normalized, email_normalized, city_id, updated_at)
             VALUES
                (:user_id, :first_name, :last_name, :phone, :email, :phone_normalized, :email_normalized, :city_id, UTC_TIMESTAMP())'
        );
        $insert->execute([
            'user_id' => $userId,
            'first_name' => $values['first_name'],
            'last_name' => $values['last_name'],
            'phone' => $values['phone'],
            'email' => $values['email'],
            'phone_normalized' => normalizeContactPhone($values['phone']),
            'email_normalized' => normalizeContactEmail($values['email']),
            'city_id' => $cityId,
        ]);
        $contactId = (int) $pdo->lastInsertId();
        synchronizeContactTags($pdo, $userId, $contactId, $tagIds);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    $contact = apiContactReadById($pdo, $userId, $contactId);
    if ($contact === null) {
        throw new RuntimeException('Created API contact could not be loaded.');
    }

    return $contact;
}

function apiUpdateContact(PDO $pdo, int $userId, int $contactId, stdClass $body): array
{
    $submitted = apiContactSubmittedValues($body, true);
    if ($submitted['errors'] !== []) {
        apiContactValidationErrorResponse($submitted['errors']);
    }

    $pdo->beginTransaction();
    try {
        $actorEmail = lockUserForHistoryMutation($pdo, $userId);
        $state = $pdo->prepare(
            'SELECT deleted_at FROM contacts WHERE id = :contact_id AND user_id = :user_id FOR UPDATE'
        );
        $state->execute(['contact_id' => $contactId, 'user_id' => $userId]);
        $contactState = $state->fetch(PDO::FETCH_ASSOC);
        if ($contactState === false) {
            $pdo->rollBack();
            apiErrorResponse(404, 'contact_not_found', 'Kontakt nije pronađen.');
        }
        if ($contactState['deleted_at'] !== null) {
            $pdo->rollBack();
            apiErrorResponse(409, 'contact_deleted', 'Obrisani kontakt ne može da se menja. Prvo ga vratite u aktivne kontakte.');
        }

        $before = lockContactHistorySnapshot($pdo, $userId, $contactId);
        if ($before === null) {
            throw new RuntimeException('The active contact snapshot could not be loaded.');
        }

        $values = [
            'first_name' => $before['first_name'],
            'last_name' => $before['last_name'],
            'phone' => $before['phone'],
            'email' => $before['email'],
            'city_id' => (string) $before['city']['id'],
        ];
        $values = array_replace($values, $submitted['values']);
        $errors = validateContactValues($values);
        if ($errors !== []) {
            $pdo->rollBack();
            apiContactValidationErrorResponse($errors);
        }

        $cityId = (int) $values['city_id'];
        $cityQuery = $pdo->prepare('SELECT id, name FROM cities WHERE id = :city_id AND user_id = :user_id LIMIT 1');
        $cityQuery->execute(['city_id' => $cityId, 'user_id' => $userId]);
        $city = $cityQuery->fetch(PDO::FETCH_ASSOC);
        if ($city === false) {
            $pdo->rollBack();
            apiContactValidationErrorResponse(['city_id' => 'Izabrani grad nije dostupan ovom nalogu.']);
        }

        $tagIds = $submitted['tag_ids_provided']
            ? $submitted['tag_ids']
            : array_column($before['tags'], 'id');
        if (!contactTagsBelongToUser($pdo, $userId, $tagIds)) {
            $pdo->rollBack();
            apiContactValidationErrorResponse(['tag_ids' => 'Jedan ili više tagova nisu dostupni ovom nalogu.']);
        }
        $newTags = getContactTagSnapshotsForUser($pdo, $userId, $tagIds);

        $changes = [];
        foreach ([
            'first_name' => ['label' => 'Ime'],
            'last_name' => ['label' => 'Prezime'],
            'phone' => ['label' => 'Telefon'],
            'email' => ['label' => 'E-mail'],
        ] as $field => $definition) {
            if ($before[$field] !== $values[$field]) {
                $changes[$field] = [
                    'label' => $definition['label'],
                    'old' => $before[$field],
                    'new' => $values[$field],
                ];
            }
        }

        $newCity = ['id' => (int) $city['id'], 'name' => (string) $city['name']];
        if ($before['city'] !== $newCity) {
            $changes['city'] = ['label' => 'Grad', 'old' => $before['city'], 'new' => $newCity];
        }

        $oldTagIds = array_column($before['tags'], 'id');
        $newTagIds = array_column($newTags, 'id');
        if ($oldTagIds !== $newTagIds) {
            $changes['tags'] = ['label' => 'Tagovi', 'old' => $before['tags'], 'new' => $newTags];
        }

        if ($changes !== []) {
            $update = $pdo->prepare(
                'UPDATE contacts
                 SET first_name = :first_name, last_name = :last_name, phone = :phone,
                     email = :email, phone_normalized = :phone_normalized,
                     email_normalized = :email_normalized, city_id = :city_id,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :contact_id AND user_id = :user_id AND deleted_at IS NULL'
            );
            $update->execute([
                'first_name' => $values['first_name'],
                'last_name' => $values['last_name'],
                'phone' => $values['phone'],
                'email' => $values['email'],
                'phone_normalized' => normalizeContactPhone($values['phone']),
                'email_normalized' => normalizeContactEmail($values['email']),
                'city_id' => $cityId,
                'contact_id' => $contactId,
                'user_id' => $userId,
            ]);
            if (isset($changes['tags'])) {
                synchronizeContactTags($pdo, $userId, $contactId, $tagIds);
            }
            recordContactHistoryEvent(
                $pdo,
                $userId,
                $contactId,
                $userId,
                $actorEmail,
                'contact_updated',
                $changes,
            );
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    $contact = apiContactReadById($pdo, $userId, $contactId);
    if ($contact === null) {
        throw new RuntimeException('Updated API contact could not be loaded.');
    }

    return $contact;
}
