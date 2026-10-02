<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/contact-helpers.php';

if (!isAuthenticated()) {
    if (($_GET['format'] ?? '') === 'json') {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['error' => 'Sesija je istekla. Prijavite se ponovo.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    redirectTo('/login.php');
}

$userId = currentUserId();
$isJsonRequest = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($_GET['format'] ?? '') === 'json';
$searchInput = $_GET['search'] ?? '';
$searchTerm = is_scalar($searchInput) ? trim((string) $searchInput) : '';
try {
    $pdo = db();
    $sql = 'SELECT contacts.id, contacts.first_name, contacts.last_name, contacts.phone,
                   contacts.email, contacts.city_id, cities.name AS city_name
            FROM contacts
            INNER JOIN cities ON cities.id = contacts.city_id
            WHERE contacts.user_id = :user_id';
    $parameters = ['user_id' => $userId];

    if ($searchTerm !== '') {
        $sql .= ' AND (
            contacts.first_name LIKE :first_name_search
            OR contacts.last_name LIKE :last_name_search
            OR contacts.phone LIKE :phone_search
            OR contacts.email LIKE :email_search
        )';
        $searchPattern = '%' . $searchTerm . '%';
        $parameters += [
            'first_name_search' => $searchPattern,
            'last_name_search' => $searchPattern,
            'phone_search' => $searchPattern,
            'email_search' => $searchPattern,
        ];
    }

    $sql .= ' ORDER BY contacts.last_name, contacts.first_name';
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    $contacts = $statement->fetchAll();
    if ($isJsonRequest) {
        $jsonContacts = array_map(static fn (array $contact): array => [
            'id' => (int) $contact['id'],
            'first_name' => (string) $contact['first_name'],
            'last_name' => (string) $contact['last_name'],
            'phone' => (string) $contact['phone'],
            'email' => (string) $contact['email'],
            'city_name' => (string) $contact['city_name'],
        ], $contacts);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['contacts' => $jsonContacts], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    $cities = getCitiesForUser($pdo, $userId);
} catch (PDOException $exception) {
    error_log('Contacts list database error: ' . $exception->getMessage());
    http_response_code(500);
    if ($isJsonRequest) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['error' => 'Kontakti trenutno nisu dostupni. Pokušajte ponovo kasnije.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
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
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/contacts.css">
    <link rel="stylesheet" href="/assets/css/dialogs.css">
    <script src="/assets/js/form-ui.js" defer></script>
    <script src="/assets/js/contacts.js" defer></script>
    <script src="/assets/js/contact-city-create.js" defer></script>
    <script src="/assets/js/form-submit-state.js" defer></script>
    <script src="/assets/js/contact-generator.js" defer></script>
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
                <div class="contacts-header-actions">
                    <button class="button button-primary" type="button" data-dialog-open="contact-create-dialog">Dodaj kontakt</button>
                    <button class="button button-secondary" type="button" data-dialog-open="contact-generator-dialog">KREIRAJ DUMMY</button>
                </div>
            </header>

            <?php if ($successMessage !== null): ?><p class="message message-success" role="status"><?= escapeHtml($successMessage) ?></p><?php endif; ?>
            <?php if ($errorMessage !== null): ?><p class="message message-error" role="alert"><?= escapeHtml($errorMessage) ?></p><?php endif; ?>
            <p class="message message-success" id="contact-generator-success" role="status" aria-live="polite" hidden></p>

            <form class="contact-search" method="get" action="/contacts.php" role="search">
                <div class="search-field">
                    <label for="contact-search">Pretraži kontakte</label>
                    <input id="contact-search" name="search" type="search" value="<?= escapeHtml($searchTerm) ?>" placeholder="Ime, prezime, telefon ili email..." autocomplete="off" aria-controls="contact-results">
                </div>
            </form>
            <p class="search-status" id="contact-search-status" role="status" aria-live="polite"></p>

            <div id="contact-results" class="contact-results" aria-live="polite" aria-busy="false">
            <?php if ($searchTerm !== '' && $contacts === []): ?>
                <section class="empty-state">
                    <h2>Nema rezultata</h2>
                    <p>Nema kontakata koji odgovaraju pretrazi.</p>
                </section>
            <?php elseif ($contacts === []): ?>
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
                                        <a class="button button-small button-secondary" href="/contacts.php?edit_id=<?= (int) $contact['id'] ?>&amp;search=<?= rawurlencode($searchTerm) ?>">Izmeni</a>
                                        <button class="button button-small button-danger" type="button" data-confirm-open="contact-delete-dialog" data-delete-id="<?= (int) $contact['id'] ?>" data-delete-label="<?= escapeHtml($contact['first_name'] . ' ' . $contact['last_name']) ?>">Izbriši</button>
                                    </div></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            </div>
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
    <dialog class="app-dialog" id="contact-generator-dialog" aria-labelledby="contact-generator-title">
        <section class="dialog-panel">
            <header class="dialog-header">
                <h2 id="contact-generator-title">Generiši test kontakte</h2>
                <button class="dialog-close" type="button" data-dialog-close aria-label="Zatvori dijalog">&times;</button>
            </header>
            <p>Izaberite broj testnih kontakata koje želite da dodate u svoj adresar.</p>
            <?php if ($cities === []): ?>
                <p class="message message-info" role="status">Pre generisanja kontakata morate imati najmanje jedan grad.</p>
            <?php endif; ?>
            <p class="message message-error" id="contact-generator-error" data-contact-generator-error role="alert" hidden></p>
            <form class="contact-form" method="post" action="/contact-generate.php" data-contact-generator novalidate>
                <div class="field-group">
                    <label for="contact-generator-count">Broj kontakata</label>
                    <input id="contact-generator-count" name="count" type="number" min="0" max="500" step="1" value="10" required inputmode="numeric" aria-describedby="contact-generator-error" <?= $cities === [] ? 'disabled' : '' ?>>
                </div>
                <div class="form-actions">
                    <button class="button button-primary" type="submit" data-contact-generator-submit data-pending-label="Generisanje..." <?= $cities === [] ? 'disabled' : '' ?>>Generiši</button>
                    <button class="button button-secondary" type="button" data-dialog-close>Otkaži</button>
                </div>
            </form>
        </section>
    </dialog>
    <dialog class="app-dialog" id="contact-delete-dialog" aria-labelledby="contact-delete-title" data-confirm-entity="kontakt">
        <section class="dialog-panel">
            <header class="dialog-header">
                <h2 id="contact-delete-title">Brisanje kontakta</h2>
                <button class="dialog-close" type="button" data-dialog-close aria-label="Zatvori dijalog">&times;</button>
            </header>
            <p data-confirm-message>Da li ste sigurni da želite da obrišete ovaj kontakt?</p>
            <form method="post" action="/contact-delete.php" class="form-actions" data-pending-submit>
                <input type="hidden" name="contact_id" value="" data-confirm-id>
                <button class="button button-secondary" type="button" data-dialog-close>Otkaži</button>
                <button class="button button-danger" type="submit" data-pending-label="Brisanje...">Izbriši</button>
            </form>
        </section>
    </dialog>
    <dialog class="app-dialog" id="contact-city-create-dialog" aria-labelledby="contact-city-create-title">
        <section class="dialog-panel">
            <header class="dialog-header">
                <h2 id="contact-city-create-title">Dodavanje mesta</h2>
                <button class="dialog-close" type="button" data-dialog-close aria-label="Zatvori dijalog">&times;</button>
            </header>
            <p data-city-create-confirm-message></p>
            <div class="form-actions">
                <button class="button button-secondary" type="button" data-dialog-close>Otkaži</button>
                <button class="button button-primary" type="button" data-city-create-confirm>Dodaj mesto</button>
            </div>
        </section>
    </dialog>
</body>
</html>
