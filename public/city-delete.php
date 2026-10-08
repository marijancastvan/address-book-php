<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/csrf.php';
require_once dirname(__DIR__) . '/app/city-helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

if (!isAuthenticated()) {
    redirectTo('/login.php');
}
if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
    redirectTo('/cities.php?error=csrf');
}

$cityId = filter_var($_POST['city_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($cityId === false || $cityId === null) {
    redirectTo('/cities.php?error=not_found');
}

try {
    $deleted = deleteCityForUser(db(), (int) currentUserId(), $cityId);

    redirectTo($deleted
        ? '/cities.php?success=deleted'
        : '/cities.php?error=not_found');
} catch (PDOException $exception) {
    if ((int) ($exception->errorInfo[1] ?? 0) === 1451) {
        redirectTo('/cities.php?error=has_contacts');
    }

    error_log('City deletion database error: ' . $exception->getMessage());
    http_response_code(500);
    exit('Grad trenutno nije moguće obrisati. Pokušajte ponovo kasnije.');
} catch (Throwable $exception) {
    error_log('City deletion failed.');
    http_response_code(500);
    exit('Grad trenutno nije moguće obrisati. Pokušajte ponovo kasnije.');
}
