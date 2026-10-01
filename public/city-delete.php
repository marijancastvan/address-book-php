<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

if (!isAuthenticated()) {
    redirectTo('/login.php');
}

$cityId = filter_var($_POST['city_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($cityId === false || $cityId === null) {
    redirectTo('/cities.php?error=not_found');
}

try {
    $statement = db()->prepare(
        'DELETE FROM cities WHERE id = :city_id AND user_id = :user_id'
    );
    $statement->execute([
        'city_id' => $cityId,
        'user_id' => currentUserId(),
    ]);

    redirectTo($statement->rowCount() === 1
        ? '/cities.php?success=deleted'
        : '/cities.php?error=not_found');
} catch (PDOException $exception) {
    if ((int) ($exception->errorInfo[1] ?? 0) === 1451) {
        redirectTo('/cities.php?error=has_contacts');
    }

    error_log('City deletion database error: ' . $exception->getMessage());
    http_response_code(500);
    exit('Grad trenutno nije moguće obrisati. Pokušajte ponovo kasnije.');
}
