<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/contact-helpers.php';

if (!isAuthenticated()) {
    redirectTo('/login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectTo('/contacts.php?modal=create');
}

$userId = currentUserId();
$formValues = contactFormValues($_POST);
$errors = validateContactValues($formValues);

try {
    $pdo = db();
    $cityId = filter_var($formValues['city_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($cityId !== false && !cityBelongsToUser($pdo, $cityId, $userId)) {
        $errors['city_id'] = 'Izaberite grad koji pripada vašem nalogu.';
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
} catch (PDOException $exception) {
    error_log('Contact creation database error: ' . $exception->getMessage());
    $errors['_form'] = 'Kontakt trenutno nije moguće sačuvati. Pokušajte ponovo kasnije.';
}

$_SESSION['contact_form_state'] = [
    'mode' => 'create',
    'values' => $formValues,
    'errors' => $errors,
];
redirectTo('/contacts.php?modal=create');
