<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/history-helpers.php';

$sendJson = static function (int $statusCode, array $payload): never {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

if (!isAuthenticated()) {
    $sendJson(401, ['status' => 'error', 'error' => 'Sesija je istekla. Prijavite se ponovo.']);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    $sendJson(405, ['status' => 'error', 'error' => 'Dozvoljen je samo POST zahtev.']);
}

$countInput = $_POST['count'] ?? null;
$count = is_scalar($countInput) ? filter_var($countInput, FILTER_VALIDATE_INT) : false;
if ($count === false || $count < 0 || $count > 500) {
    $sendJson(422, ['status' => 'error', 'error' => 'Unesite ceo broj od 0 do 500.']);
}
if ($count === 0) {
    $sendJson(422, ['status' => 'error', 'error' => 'Broj dummy kontakata mora biti veći od 0.']);
}

$userId = currentUserId();
if ($userId === null) {
    $sendJson(401, ['status' => 'error', 'error' => 'Sesija je istekla. Prijavite se ponovo.']);
}

$firstNames = [
    'Marko', 'Nikola', 'Petar', 'Luka', 'Stefan',
    'Milan', 'Aleksandar', 'Jovan', 'Filip', 'Nemanja',
    'Andrija', 'Andrej', 'Vuk', 'Uroš', 'Miloš',
    'Danilo', 'Dušan', 'Lazar', 'Ognjen', 'Vojin',
    'Bogdan', 'Matija', 'Vasilije', 'Pavle', 'Veljko',
];
$lastNames = [
    'Petrović', 'Jovanović', 'Nikolić', 'Marković', 'Ilić',
    'Stojanović', 'Pavlović', 'Đorđević', 'Savić', 'Popović',
    'Kostić', 'Ristić', 'Todorović', 'Đukić', 'Lukić',
    'Milić', 'Simić', 'Vasić', 'Radović', 'Vuković',
    'Lazić', 'Perić', 'Blagojević', 'Radulović', 'Obrenović',
];
$namePairs = [];
foreach ($firstNames as $firstName) {
    foreach ($lastNames as $lastName) {
        $namePairs[] = [$firstName, $lastName];
    }
}
shuffle($namePairs);

$pdo = null;
try {
    $pdo = db();
    $pdo->beginTransaction();
    lockUserForHistoryMutation($pdo, (int) $userId);

    $cityStatement = $pdo->prepare('SELECT id FROM cities WHERE user_id = :user_id ORDER BY id');
    $cityStatement->execute(['user_id' => $userId]);
    $cityIds = array_map(static fn (array $city): int => (int) $city['id'], $cityStatement->fetchAll());

    if ($cityIds === []) {
        $pdo->rollBack();
        $sendJson(422, [
            'status' => 'error',
            'error' => 'Pre generisanja kontakata morate imati najmanje jedan grad.',
        ]);
    }

    $insert = $pdo->prepare(
        'INSERT INTO contacts (user_id, first_name, last_name, phone, email, city_id)
         VALUES (:user_id, :first_name, :last_name, :phone, :email, :city_id)'
    );

    for ($index = 0; $index < $count; $index++) {
        [$firstName, $lastName] = $namePairs[$index];
        $insert->execute([
            'user_id' => $userId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => '+3816' . random_int(10_000_000, 99_999_999),
            'email' => 'test_' . bin2hex(random_bytes(12)) . '@example.com',
            'city_id' => $cityIds[random_int(0, count($cityIds) - 1)],
        ]);
    }

    $pdo->commit();
    $sendJson(201, ['status' => 'success', 'count' => $count]);
} catch (Throwable $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Dummy contact generation error: ' . $exception->getMessage());
    $sendJson(500, [
        'status' => 'error',
        'error' => 'Test kontakte trenutno nije moguće generisati. Pokušajte ponovo kasnije.',
    ]);
}
