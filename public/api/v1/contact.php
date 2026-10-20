<?php

declare(strict_types=1);

define('ADDRESS_BOOK_SKIP_SESSION', true);
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/api-token-helpers.php';
require_once dirname(__DIR__, 3) . '/app/api.php';
require_once dirname(__DIR__, 3) . '/app/api-contact-helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiErrorResponse(405, 'method_not_allowed', 'Dozvoljen je samo GET zahtev.', ['Allow' => 'GET']);
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
    $contact = apiContactReadById($pdo, $userId, $contactId);
    if ($contact === null) {
        apiErrorResponse(404, 'contact_not_found', 'Kontakt nije pronađen.');
    }

    apiJsonResponse(200, ['data' => $contact]);
} catch (Throwable $exception) {
    error_log('API single contact request failed.');
    apiErrorResponse(500, 'server_error', 'API zahtev trenutno nije moguće obraditi.');
}
