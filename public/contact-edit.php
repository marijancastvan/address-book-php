<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/csrf.php';
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
$returnContext = [
    'search' => $returnSearch,
    'city_id' => is_scalar($_GET['city_id'] ?? $_POST['city_id'] ?? '') ? (string) ($_GET['city_id'] ?? $_POST['city_id'] ?? '') : '',
    'tag_id' => is_scalar($_GET['tag_id'] ?? $_POST['tag_id'] ?? '') ? (string) ($_GET['tag_id'] ?? $_POST['tag_id'] ?? '') : '',
    'date_from' => is_scalar($_GET['date_from'] ?? $_POST['date_from'] ?? '') ? (string) ($_GET['date_from'] ?? $_POST['date_from'] ?? '') : '',
    'date_to' => is_scalar($_GET['date_to'] ?? $_POST['date_to'] ?? '') ? (string) ($_GET['date_to'] ?? $_POST['date_to'] ?? '') : '',
    'page' => $returnPage,
];
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirectTo('/contacts.php?' . http_build_query(['edit_id' => $contactId] + $returnContext));
}

$userId = (int) currentUserId();
$formValues = contactFormValues($_POST);
$submittedTags = contactTagIdsFromInput($_POST);
$tagIds = $submittedTags['ids'];
$errors = validateContactValues($formValues);
if ($submittedTags['error'] !== null) {
    $errors['tag_ids'] = $submittedTags['error'];
}

if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
    $errors['_form'] = 'Forma je istekla ili nije validna. Osvežite stranicu i pokušajte ponovo.';
} else {
    $pdo = null;
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
        if ($submittedTags['error'] === null && !contactTagsBelongToUser($pdo, $userId, $tagIds)) {
            $errors['tag_ids'] = 'Jedan ili više izabranih tagova nisu dostupni na vašem nalogu.';
        }

        if ($errors === []) {
            $pdo->beginTransaction();
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
            synchronizeContactTags($pdo, $userId, $contactId, $tagIds);
            $pdo->commit();
            redirectTo('/contacts.php?' . http_build_query(['success' => 'updated'] + $returnContext));
        }
    } catch (Throwable $exception) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Contact editing transaction failed.');
        $errors['_form'] = 'Kontakt trenutno nije moguće sačuvati. Pokušajte ponovo kasnije.';
    }
}

$_SESSION['contact_form_state'] = [
    'mode' => 'edit',
    'contact_id' => $contactId,
    'values' => $formValues,
    'tag_ids' => $tagIds,
    'errors' => $errors,
];
redirectTo('/contacts.php?' . http_build_query(['edit_id' => $contactId] + $returnContext));
