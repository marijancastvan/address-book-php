<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/contact-helpers.php';

if (!isAuthenticated()) {
    redirectTo('/login.php');
}

$contactId = filter_var($_GET['id'] ?? $_POST['contact_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($contactId === false || $contactId === null) {
    redirectTo('/contacts.php?error=not_found');
}
$returnPageInput = filter_var($_GET['page'] ?? $_POST['page'] ?? 1, FILTER_VALIDATE_INT);
$returnPage = is_int($returnPageInput) && $returnPageInput > 0 ? $returnPageInput : 1;
$returnSearchInput = $_GET['search'] ?? $_POST['search'] ?? '';
$returnSearch = is_scalar($returnSearchInput) ? trim((string) $returnSearchInput) : '';
$returnContext = ['search' => $returnSearch, 'page' => $returnPage];
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectTo('/contacts.php?' . http_build_query(['edit_id' => $contactId] + $returnContext));
}

$userId = currentUserId();
$formValues = contactFormValues($_POST);
$errors = validateContactValues($formValues);

try {
    $pdo = db();
    $ownedContact = $pdo->prepare('SELECT id FROM contacts WHERE id = :contact_id AND user_id = :user_id LIMIT 1');
    $ownedContact->execute(['contact_id' => $contactId, 'user_id' => $userId]);
    if ($ownedContact->fetch() === false) {
        redirectTo('/contacts.php?' . http_build_query(['error' => 'not_found'] + $returnContext));
    }

    $cityId = filter_var($formValues['city_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($cityId !== false && !cityBelongsToUser($pdo, $cityId, $userId)) {
        $errors['city_id'] = 'Izaberite grad koji pripada vašem nalogu.';
    }

    if ($errors === []) {
        $update = $pdo->prepare(
            'UPDATE contacts
             SET first_name = :first_name, last_name = :last_name, phone = :phone,
                 email = :email, city_id = :city_id
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
        redirectTo('/contacts.php?' . http_build_query(['success' => 'updated'] + $returnContext));
    }
} catch (PDOException $exception) {
    error_log('Contact editing database error: ' . $exception->getMessage());
    $errors['_form'] = 'Kontakt trenutno nije moguće sačuvati. Pokušajte ponovo kasnije.';
}

$_SESSION['contact_form_state'] = [
    'mode' => 'edit',
    'contact_id' => $contactId,
    'values' => $formValues,
    'errors' => $errors,
];
redirectTo('/contacts.php?' . http_build_query(['edit_id' => $contactId] + $returnContext));
