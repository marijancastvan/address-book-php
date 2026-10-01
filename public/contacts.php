<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/contact-helpers.php';

if (!isAuthenticated()) {
    redirectTo('/login.php');
}

$userId = currentUserId();
try {
    $pdo = db();
    $statement = $pdo->prepare(
        'SELECT contacts.id, contacts.first_name, contacts.last_name, contacts.phone,
                contacts.email, contacts.city_id, cities.name AS city_name
         FROM contacts
         INNER JOIN cities ON cities.id = contacts.city_id
         WHERE contacts.user_id = :user_id
         ORDER BY contacts.last_name, contacts.first_name'
    );
    $statement->execute(['user_id' => $userId]);
    $contacts = $statement->fetchAll();
    $cities = getCitiesForUser($pdo, $userId);
} catch (PDOException $exception) {
    error_log('Contacts list database error: ' . $exception->getMessage());
    http_response_code(500);
    exit('Kontakti trenutno nisu dostupni. Pokušajte ponovo kasnije.');
}

$formState = $_SESSION['contact_form_state'] ?? null;
unset($_SESSION['contact_form_state']);
$formState = is_array($formState) ? $formState : [];
$createValues = ['first_name' => '', 'last_name' => '', 'phone' => '', 'email' => '', 'city_id' => ''];
$createErrors = [];
$openCreate = ($_GET['modal'] ?? '') === 'create';
$openEditId = filter_var($_GET['edit_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (($formState['mode'] ?? '') === 'create') {
    $createValues = contactFormValues(is_array($formState['values'] ?? null) ? $formState['values'] : []);
    $createErrors = is_array($formState['errors'] ?? null) ? $formState['errors'] : [];
    $openCreate = true;
}

$editContact = null;
$editValues = ['first_name' => '', 'last_name' => '', 'phone' => '', 'email' => '', 'city_id' => ''];
$editErrors = [];
if (($formState['mode'] ?? '') === 'edit') {
    $openEditId = filter_var($formState['contact_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $editValues = contactFormValues(is_array($formState['values'] ?? null) ? $formState['values'] : []);
    $editErrors = is_array($formState['errors'] ?? null) ? $formState['errors'] : [];
}
if ($openEditId !== false && $openEditId !== null) {
    foreach ($contacts as $listedContact) {
        if ((int) $listedContact['id'] === $openEditId) {
            $editContact = $listedContact;
            if (($formState['mode'] ?? '') !== 'edit') {
                $editValues = [
                    'first_name' => $listedContact['first_name'],
                    'last_name' => $listedContact['last_name'],
                    'phone' => $listedContact['phone'],
                    'email' => $listedContact['email'],
                    'city_id' => (string) $listedContact['city_id'],
                ];
            }
            break;
        }
    }
}

$successMessage = contactSuccessMessage();
$errorMessage = ($_GET['error'] ?? '') === 'not_found' || ($openEditId && $editContact === null)
    ? 'Kontakt nije pronađen ili nemate dozvolu za pristup.'
    : null;
$activeNavigation = 'contacts';
?>
<!doctype html>
<html lang="sr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kontakti | Address Book</title>
    <link rel="stylesheet" href="/assets/css/contacts.css">
    <link rel="stylesheet" href="/assets/css/dialogs.css">
    <script src="/assets/js/form-ui.js" defer></script>
</head>
<body>
    <div class="app-shell">
        <aside class="sidebar">
            <a class="brand" href="/dashboard.php">Address Book</a>
            <?php require dirname(__DIR__) . '/app/views/main-navigation.php'; ?>
            <form action="/logout.php" method="post" class="logout-form">
                <button class="logout-button" type="submit">Odjava</button>
            </form>
        </aside>

        <main class="main-content">
            <header class="page-header">
                <div><p class="eyebrow">ADRESAR</p><h1>Kontakti</h1></div>
                <button class="button button-primary" type="button" data-dialog-open="contact-create-dialog">Dodaj kontakt</button>
            </header>

            <?php if ($successMessage !== null): ?><p class="message message-success" role="status"><?= escapeHtml($successMessage) ?></p><?php endif; ?>
            <?php if ($errorMessage !== null): ?><p class="message message-error" role="alert"><?= escapeHtml($errorMessage) ?></p><?php endif; ?>

            <?php if ($contacts === []): ?>
                <section class="empty-state">
                    <h2>Još nema kontakata</h2>
                    <p>Trenutno nemate nijedan kontakt.</p>
                    <button class="button button-primary" type="button" data-dialog-open="contact-create-dialog">Dodaj kontakt</button>
                </section>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th scope="col">Ime</th><th scope="col">Prezime</th><th scope="col">Telefon</th><th scope="col">Email</th><th scope="col">Grad</th><th scope="col">Akcije</th></tr></thead>
                        <tbody>
                            <?php foreach ($contacts as $contact): ?>
                                <tr>
                                    <td data-label="Ime"><?= escapeHtml($contact['first_name']) ?></td>
                                    <td data-label="Prezime"><?= escapeHtml($contact['last_name']) ?></td>
                                    <td data-label="Telefon"><?= escapeHtml($contact['phone']) ?></td>
                                    <td data-label="Email"><?= escapeHtml($contact['email']) ?></td>
                                    <td data-label="Grad"><?= escapeHtml($contact['city_name']) ?></td>
                                    <td data-label="Akcije"><div class="row-actions">
                                        <a class="button button-small button-secondary" href="/contacts.php?edit_id=<?= (int) $contact['id'] ?>">Izmeni</a>
                                        <button class="button button-small button-danger" type="button" data-confirm-open="contact-delete-dialog" data-delete-id="<?= (int) $contact['id'] ?>">Izbriši</button>
                                    </div></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <?php
    $dialogId = 'contact-create-dialog';
    $formIdPrefix = 'contact-create';
    $dialogTitle = 'Dodaj kontakt';
    $formAction = '/contact-create.php';
    $formValues = $createValues;
    $errors = $createErrors;
    $openDialog = $openCreate;
    unset($contactId);
    require dirname(__DIR__) . '/app/views/contact-form.php';

    if ($editContact !== null) {
        $dialogId = 'contact-edit-dialog';
        $formIdPrefix = 'contact-edit';
        $dialogTitle = 'Izmeni kontakt';
        $formAction = '/contact-edit.php?id=' . (int) $editContact['id'];
        $formValues = $editValues;
        $errors = $editErrors;
        $contactId = (int) $editContact['id'];
        $openDialog = (($formState['mode'] ?? '') === 'edit') || (($_GET['edit_id'] ?? '') !== '');
        require dirname(__DIR__) . '/app/views/contact-form.php';
    }
    ?>
    <dialog class="app-dialog" id="contact-delete-dialog" aria-labelledby="contact-delete-title">
        <section class="dialog-panel">
            <header class="dialog-header">
                <h2 id="contact-delete-title">Brisanje kontakta</h2>
                <button class="dialog-close" type="button" data-dialog-close aria-label="Zatvori dijalog">&times;</button>
            </header>
            <p>Da li ste sigurni da želite da obrišete ovaj kontakt?</p>
            <form method="post" action="/contact-delete.php" class="form-actions">
                <input type="hidden" name="contact_id" value="" data-confirm-id>
                <button class="button button-secondary" type="button" data-dialog-close>Otkaži</button>
                <button class="button button-danger" type="submit">Izbriši</button>
            </form>
        </section>
    </dialog>
</body>
</html>
