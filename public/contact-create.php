<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/csrf.php';
require_once dirname(__DIR__) . '/app/contact-helpers.php';
require_once dirname(__DIR__) . '/app/history-helpers.php';

if (!isAuthenticated()) {
    redirectTo('/login.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirectTo('/contacts.php?modal=create');
}

$userId = currentUserId();
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
        $cityId = filter_var($formValues['city_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($errors === []) {
            $pdo->beginTransaction();
            lockUserForHistoryMutation($pdo, (int) $userId);
            if ($cityId !== false && !cityBelongsToUser($pdo, $cityId, (int) $userId)) {
                $errors['city_id'] = 'Izaberite grad koji pripada vašem nalogu.';
            }
            if ($submittedTags['error'] === null && !contactTagsBelongToUser($pdo, (int) $userId, $tagIds)) {
                $errors['tag_ids'] = 'Jedan ili više izabranih tagova nisu dostupni na vašem nalogu.';
            }
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
            $contactId = (int) $pdo->lastInsertId();
            synchronizeContactTags($pdo, (int) $userId, $contactId, $tagIds);
            $pdo->commit();
            redirectTo('/contacts.php?success=created');
        }
        if ($pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
    } catch (Throwable $exception) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Contact creation transaction failed.');
        $errors['_form'] = 'Kontakt trenutno nije moguće sačuvati. Pokušajte ponovo kasnije.';
    }
}

$_SESSION['contact_form_state'] = [
    'mode' => 'create',
    'values' => $formValues,
    'tag_ids' => $tagIds,
    'errors' => $errors,
];
redirectTo('/contacts.php?modal=create');
