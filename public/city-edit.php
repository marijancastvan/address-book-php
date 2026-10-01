<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/city-helpers.php';

if (!isAuthenticated()) {
    redirectTo('/login.php');
}
$cityId = filter_var($_GET['id'] ?? $_POST['city_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($cityId === false || $cityId === null) {
    redirectTo('/cities.php?error=not_found');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectTo('/cities.php?edit_id=' . $cityId);
}

$userId = currentUserId();
$nameInput = $_POST['name'] ?? '';
$cityName = is_scalar($nameInput) ? trim((string) $nameInput) : '';
$errors = validateCityName($cityName);

try {
    $pdo = db();
    $ownedCity = $pdo->prepare('SELECT id FROM cities WHERE id = :city_id AND user_id = :user_id LIMIT 1');
    $ownedCity->execute(['city_id' => $cityId, 'user_id' => $userId]);
    if ($ownedCity->fetch() === false) {
        redirectTo('/cities.php?error=not_found');
    }
    if ($errors === [] && cityNameExistsForUser($pdo, $userId, $cityName, $cityId)) {
        $errors['name'] = 'Grad sa ovim nazivom već postoji.';
    }
    if ($errors === []) {
        $update = $pdo->prepare('UPDATE cities SET name = :name WHERE id = :city_id AND user_id = :user_id');
        $update->execute(['name' => $cityName, 'city_id' => $cityId, 'user_id' => $userId]);
        redirectTo('/cities.php?success=updated');
    }
} catch (PDOException $exception) {
    if (isDuplicateCityError($exception)) {
        $errors['name'] = 'Grad sa ovim nazivom već postoji.';
    } else {
        error_log('City editing database error: ' . $exception->getMessage());
        $errors['_form'] = 'Grad trenutno nije moguće sačuvati. Pokušajte ponovo kasnije.';
    }
}

$_SESSION['city_form_state'] = ['mode' => 'edit', 'city_id' => $cityId, 'name' => $cityName, 'errors' => $errors];
redirectTo('/cities.php?edit_id=' . $cityId);
