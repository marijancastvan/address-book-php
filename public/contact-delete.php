<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/csrf.php';
require_once dirname(__DIR__) . '/app/history-helpers.php';

if (!isAuthenticated()) {
    redirectTo('/login.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

$returnContext = contactListReturnContext($_GET);
if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
    redirectTo('/contacts.php?' . http_build_query(['error' => 'csrf'] + $returnContext));
}

$contactId = filter_var($_POST['contact_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($contactId === false || $contactId === null) {
    redirectTo('/contacts.php?' . http_build_query(['error' => 'not_found'] + $returnContext));
}

try {
    $result = transitionContactDeletedState(db(), (int) currentUserId(), $contactId, true);
    if ($result === 'not_found') {
        redirectTo('/contacts.php?' . http_build_query(['error' => 'not_found'] + $returnContext));
    }

    redirectTo('/contacts.php?' . http_build_query([
        'success' => $result === 'changed' ? 'deleted' : 'already_deleted',
    ] + $returnContext));
} catch (Throwable $exception) {
    error_log('Contact soft deletion failed.');
    http_response_code(500);
    exit('Kontakt trenutno nije moguće premestiti u obrisane kontakte. Pokušajte ponovo kasnije.');
}
