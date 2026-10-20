<?php

declare(strict_types=1);

define('ADDRESS_BOOK_SKIP_SESSION', true);
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/api-token-helpers.php';
require_once dirname(__DIR__, 3) . '/app/api.php';
require_once dirname(__DIR__, 3) . '/app/api-contact-helpers.php';

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'PATCH', 'DELETE'], true)) {
    apiErrorResponse(405, 'method_not_allowed', 'Dozvoljeni su GET, PATCH i DELETE zahtevi.', ['Allow' => 'GET, PATCH, DELETE']);
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
    $contactId = apiContactQueryPositiveInt($_GET, 'id');
    if ($contactId === null) {
        apiContactInvalidParameter('Parametar „id“ je obavezan.');
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        $contact = apiContactReadById($pdo, $userId, $contactId);
        if ($contact === null) {
            apiErrorResponse(404, 'contact_not_found', 'Kontakt nije pronađen.');
        }
    } elseif ($method === 'PATCH') {
        $contact = apiUpdateContact($pdo, $userId, $contactId, apiContactReadJsonObject());
    } else {
        $transition = transitionContactDeletedState($pdo, $userId, $contactId, true);
        if ($transition === 'not_found') {
            apiErrorResponse(404, 'contact_not_found', 'Kontakt nije pronađen.');
        }
        $contact = apiContactReadById($pdo, $userId, $contactId);
        if ($contact === null) {
            throw new RuntimeException('Deleted API contact could not be loaded.');
        }
    }

    apiJsonResponse(200, ['data' => $contact]);
} catch (Throwable $exception) {
    error_log('API single contact request failed.');
    apiErrorResponse(500, 'server_error', 'API zahtev trenutno nije moguće obraditi.');
}
