<?php

declare(strict_types=1);

define('ADDRESS_BOOK_SKIP_SESSION', true);
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/api-token-helpers.php';
require_once dirname(__DIR__, 3) . '/app/api.php';
require_once dirname(__DIR__, 3) . '/app/api-contact-helpers.php';

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
    apiErrorResponse(405, 'method_not_allowed', 'Dozvoljeni su GET i POST zahtevi.', ['Allow' => 'GET, POST']);
}

if (!apiRequestUsesSecureTransport()) {
    apiErrorResponse(400, 'https_required', 'API zahtevi zahtevaju HTTPS.');
}

try {
    $pdo = db();
} catch (Throwable $exception) {
    error_log('API database connection failed.');
    apiErrorResponse(500, 'server_error', 'API zahtev trenutno nije moguće obraditi.');
}

$userId = apiAuthenticateRequest($pdo);

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $contact = apiCreateContact($pdo, $userId, apiContactReadJsonObject());
        $baseUrl = $config['app']['base_url'] ?? '';
        if (!is_string($baseUrl) || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('The API base URL is not configured.');
        }
        $location = rtrim($baseUrl, '/') . '/api/v1/contact.php?id=' . rawurlencode((string) $contact['id']);
        apiJsonResponse(201, ['data' => $contact], ['Location' => $location]);
    }

    $filters = apiContactListFilters($pdo, $userId, $_GET);
    $where = apiContactWhereClause($filters, $userId);

    $count = $pdo->prepare('SELECT COUNT(*) FROM contacts' . $where['sql']);
    apiContactBindParameters($count, $where['parameters']);
    $count->execute();
    $total = (int) $count->fetchColumn();
    $totalPages = (int) ceil($total / $filters['per_page']);
    $offset = ($filters['page'] - 1) * $filters['per_page'];

    $statement = $pdo->prepare(
        'SELECT contacts.id, contacts.first_name, contacts.last_name, contacts.phone, contacts.email,
                contacts.city_id, cities.name AS city_name, contacts.created_at, contacts.deleted_at
         FROM contacts
         INNER JOIN cities ON cities.id = contacts.city_id AND cities.user_id = contacts.user_id'
         . $where['sql'] . '
         ORDER BY ' . contactOrderBySql($filters['sorting']) . '
         LIMIT :limit OFFSET :offset'
    );
    apiContactBindParameters($statement, $where['parameters']);
    $statement->bindValue(':limit', $filters['per_page'], PDO::PARAM_INT);
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $statement->execute();
    $contacts = $statement->fetchAll(PDO::FETCH_ASSOC);
    $tagsByContact = getTagsForContacts($pdo, $userId, $contacts);
    $data = array_map(
        static fn (array $contact): array => apiContactPayload(
            $contact,
            $tagsByContact[(int) $contact['id']] ?? [],
        ),
        $contacts,
    );

    apiJsonResponse(200, [
        'data' => $data,
        'meta' => [
            'page' => $filters['page'],
            'per_page' => $filters['per_page'],
            'total' => $total,
            'total_pages' => $totalPages,
        ],
    ]);
} catch (Throwable $exception) {
    error_log('API contact list request failed.');
    apiErrorResponse(500, 'server_error', 'API zahtev trenutno nije moguće obraditi.');
}
