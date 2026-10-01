<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/contact-helpers.php';

if (!isAuthenticated()) {
    redirectTo('/login.php');
}

$userId = currentUserId();
$contactId = filter_var($_GET['id'] ?? $_POST['contact_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($contactId === false || $contactId === null) {
    redirectTo('/contacts.php?error=not_found');
}

try {
    $pdo = db();
    $statement = $pdo->prepare(
        'SELECT id, first_name, last_name, phone, email, city_id
         FROM contacts
         WHERE id = :contact_id AND user_id = :user_id
         LIMIT 1'
    );
    $statement->execute([
        'contact_id' => $contactId,
        'user_id' => $userId,
    ]);
    $contact = $statement->fetch();

    if ($contact === false) {
        redirectTo('/contacts.php?error=not_found');
    }

    $cities = getCitiesForUser($pdo, $userId);
    $formValues = [
        'first_name' => $contact['first_name'],
        'last_name' => $contact['last_name'],
        'phone' => $contact['phone'],
        'email' => $contact['email'],
        'city_id' => (string) $contact['city_id'],
    ];
    $errors = [];

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
            $update = $pdo->prepare(
                'UPDATE contacts
                 SET first_name = :first_name,
                     last_name = :last_name,
                     phone = :phone,
                     email = :email,
                     city_id = :city_id
                 WHERE id = :contact_id AND user_id = :user_id'
            );
            $update->execute([
                'first_name' => $formValues['first_name'],
                'last_name' => $formValues['last_name'],
                'phone' => $formValues['phone'],
                'email' => $formValues['email'],
                'city_id' => $cityId,
                'contact_id' => $contactId,
                'user_id' => $userId,
            ]);

            redirectTo('/contacts.php?success=updated');
        }
    }
} catch (PDOException $exception) {
    error_log('Contact editing database error: ' . $exception->getMessage());
    http_response_code(500);
    exit('Kontakt trenutno nije moguće učitati ili sačuvati. Pokušajte ponovo kasnije.');
}

$formAction = '/contact-edit.php?id=' . (int) $contactId;
?>
<!doctype html>
<html lang="sr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Izmeni kontakt | Address Book</title>
    <link rel="stylesheet" href="/assets/css/contacts.css">
</head>
<body>
    <main class="form-page">
        <a class="back-link" href="/contacts.php">← Kontakti</a>
        <section class="form-card">
            <p class="eyebrow">ADRESAR</p>
            <h1>Izmeni kontakt</h1>
            <?php require dirname(__DIR__) . '/app/views/contact-form.php'; ?>
        </section>
    </main>
</body>
</html>
