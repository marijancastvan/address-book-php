<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/contact-helpers.php';

if (!isAuthenticated()) {
    redirectTo('/login.php');
}

$userId = currentUserId();
$errors = [];
$formValues = [
    'first_name' => '',
    'last_name' => '',
    'phone' => '',
    'email' => '',
    'city_id' => '',
];

try {
    $pdo = db();
    $cities = getCitiesForUser($pdo, $userId);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $formValues = contactFormValues($_POST);
        $errors = validateContactValues($formValues);
        $cityId = filter_var($formValues['city_id'], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($cityId !== false && !cityBelongsToUser($pdo, $cityId, $userId)) {
            $errors[] = 'Izabrani grad nije dostupan.';
        }

        if ($errors === []) {
            $statement = $pdo->prepare(
                'INSERT INTO contacts (user_id, first_name, last_name, phone, email, city_id)
                 VALUES (:user_id, :first_name, :last_name, :phone, :email, :city_id)'
            );
            $statement->execute([
                'user_id' => $userId,
                'first_name' => $formValues['first_name'],
                'last_name' => $formValues['last_name'],
                'phone' => $formValues['phone'],
                'email' => $formValues['email'],
                'city_id' => $cityId,
            ]);

            redirectTo('/contacts.php?success=created');
        }
    }
} catch (PDOException $exception) {
    error_log('Contact creation database error: ' . $exception->getMessage());
    http_response_code(500);
    $errors[] = 'Kontakt trenutno nije moguće sačuvati. Pokušajte ponovo kasnije.';
    $cities ??= [];
}

$formAction = '/contact-create.php';
?>
<!doctype html>
<html lang="sr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dodaj kontakt | Address Book</title>
    <link rel="stylesheet" href="/assets/css/contacts.css">
</head>
<body>
    <main class="form-page">
        <a class="back-link" href="/contacts.php">← Kontakti</a>
        <section class="form-card">
            <p class="eyebrow">ADRESAR</p>
            <h1>Dodaj kontakt</h1>
            <?php require dirname(__DIR__) . '/app/views/contact-form.php'; ?>
        </section>
    </main>
</body>
</html>
