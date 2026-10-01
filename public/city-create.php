<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/city-helpers.php';

if (!isAuthenticated()) {
    redirectTo('/login.php');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectTo('/cities.php?modal=create');
}

$userId = currentUserId();
$nameInput = $_POST['name'] ?? '';
$cityName = is_scalar($nameInput) ? trim((string) $nameInput) : '';
$errors = validateCityName($cityName);

try {
    $pdo = db();
    if ($errors === [] && cityNameExistsForUser($pdo, $userId, $cityName)) {
        $errors['name'] = 'Grad sa ovim nazivom već postoji.';
    }
    if ($errors === []) {
        $statement = $pdo->prepare('INSERT INTO cities (user_id, name) VALUES (:user_id, :name)');
        $statement->execute(['user_id' => $userId, 'name' => $cityName]);
        redirectTo('/cities.php?success=created');
    }
} catch (PDOException $exception) {
    if (isDuplicateCityError($exception)) {
        $errors['name'] = 'Grad sa ovim nazivom već postoji.';
    } else {
        error_log('City creation database error: ' . $exception->getMessage());
        $errors['_form'] = 'Grad trenutno nije moguće sačuvati. Pokušajte ponovo kasnije.';
    }
}

$_SESSION['city_form_state'] = ['mode' => 'create', 'name' => $cityName, 'errors' => $errors];
redirectTo('/cities.php?modal=create');
