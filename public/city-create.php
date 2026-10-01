<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/city-helpers.php';

$isJsonRequest = ($_GET['format'] ?? '') === 'json';
$sendJson = static function (int $statusCode, array $payload): never {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

if (!isAuthenticated()) {
    if ($isJsonRequest) {
        $sendJson(401, ['status' => 'error', 'error' => 'Sesija je istekla. Prijavite se ponovo.']);
    }
    redirectTo('/login.php');
}
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($requestMethod !== 'POST') {
    if ($isJsonRequest) {
        header('Allow: POST');
        $sendJson(405, ['status' => 'error', 'error' => 'Dozvoljen je samo POST zahtev.']);
    }
    redirectTo('/cities.php?modal=create');
}

$userId = currentUserId();
$nameInput = $_POST['name'] ?? '';
$cityName = is_scalar($nameInput) ? trim((string) $nameInput) : '';
$errors = validateCityName($cityName);

if ($isJsonRequest) {
    if ($errors !== []) {
        $sendJson(422, ['status' => 'error', 'errors' => $errors]);
    }

    try {
        $pdo = db();
        if (cityNameExistsForUser($pdo, $userId, $cityName)) {
            $existingCity = $pdo->prepare(
                'SELECT id, name FROM cities WHERE user_id = :user_id AND name = :name LIMIT 1'
            );
            $existingCity->execute(['user_id' => $userId, 'name' => $cityName]);
            $city = $existingCity->fetch();

            if ($city !== false) {
                $sendJson(200, [
                    'status' => 'exists',
                    'city' => ['id' => (int) $city['id'], 'name' => (string) $city['name']],
                    'message' => 'Ovo mesto već postoji.',
                ]);
            }
        }

        $statement = $pdo->prepare('INSERT INTO cities (user_id, name) VALUES (:user_id, :name)');
        $statement->execute(['user_id' => $userId, 'name' => $cityName]);
        $cityId = (int) $pdo->lastInsertId();

        $sendJson(201, [
            'status' => 'created',
            'city' => ['id' => $cityId, 'name' => $cityName],
            'message' => 'Mesto je uspešno dodato.',
        ]);
    } catch (PDOException $exception) {
        if (isDuplicateCityError($exception)) {
            try {
                $existingCity = db()->prepare(
                    'SELECT id, name FROM cities WHERE user_id = :user_id AND name = :name LIMIT 1'
                );
                $existingCity->execute(['user_id' => $userId, 'name' => $cityName]);
                $city = $existingCity->fetch();

                if ($city !== false) {
                    $sendJson(200, [
                        'status' => 'exists',
                        'city' => ['id' => (int) $city['id'], 'name' => (string) $city['name']],
                        'message' => 'Ovo mesto već postoji.',
                    ]);
                }
            } catch (PDOException $lookupException) {
                error_log('City duplicate lookup database error: ' . $lookupException->getMessage());
            }
        }

        error_log('City creation JSON database error: ' . $exception->getMessage());
        $sendJson(500, ['status' => 'error', 'error' => 'Mesto trenutno nije moguće dodati. Pokušajte ponovo kasnije.']);
    }
}

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
