<?php

declare(strict_types=1);

require_once __DIR__ . '/contact-helpers.php';

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
