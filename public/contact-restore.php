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
    redirectTo('/contacts.php?' . http_build_query(['error' => 'csrf', 'view' => 'deleted'] + $returnContext));
}

$contactId = filter_var($_POST['contact_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($contactId === false || $contactId === null) {
    redirectTo('/contacts.php?' . http_build_query(['error' => 'not_found', 'view' => 'deleted'] + $returnContext));
}

try {
    $result = transitionContactDeletedState(db(), (int) currentUserId(), $contactId, false);
    if ($result === 'not_found') {
        redirectTo('/contacts.php?' . http_build_query(['error' => 'not_found', 'view' => 'deleted'] + $returnContext));
    }

    redirectTo('/contacts.php?' . http_build_query([
        'success' => $result === 'changed' ? 'restored' : 'already_active',
    ] + $returnContext));
} catch (Throwable $exception) {
    error_log('Contact restore failed.');
    http_response_code(500);
    exit('Kontakt trenutno nije moguće vratiti. Pokušajte ponovo kasnije.');
}
