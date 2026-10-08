<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/csrf.php';
require_once dirname(__DIR__) . '/app/history-helpers.php';

if (!isAuthenticated()) {
    redirectTo('/login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}
if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
    redirectTo('/contacts.php?error=csrf');
}

$contactId = filter_var($_POST['contact_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($contactId === false || $contactId === null) {
    redirectTo('/contacts.php?error=not_found');
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    lockUserForHistoryMutation($pdo, (int) currentUserId());
    $statement = $pdo->prepare(
        'DELETE FROM contacts WHERE id = :contact_id AND user_id = :user_id'
    );
    $statement->execute([
        'contact_id' => $contactId,
        'user_id' => currentUserId(),
    ]);
    $pdo->commit();

    redirectTo($statement->rowCount() === 1
        ? '/contacts.php?success=deleted'
        : '/contacts.php?error=not_found');
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('Contact deletion failed.');
    http_response_code(500);
    exit('Kontakt trenutno nije moguće obrisati. Pokušajte ponovo kasnije.');
}
