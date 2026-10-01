<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/city-helpers.php';

if (!isAuthenticated()) {
    redirectTo('/login.php');
}

$userId = currentUserId();
try {
    $statement = db()->prepare(
        'SELECT id, name, created_at, updated_at FROM cities WHERE user_id = :user_id ORDER BY name ASC'
    );
    $statement->execute(['user_id' => $userId]);
    $cities = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log('Cities list database error: ' . $exception->getMessage());
    http_response_code(500);
    exit('Gradovi trenutno nisu dostupni. Pokušajte ponovo kasnije.');
}

$formState = $_SESSION['city_form_state'] ?? null;
unset($_SESSION['city_form_state']);
$formState = is_array($formState) ? $formState : [];
$createName = '';
$createErrors = [];
$openCreate = ($_GET['modal'] ?? '') === 'create';
$openEditId = filter_var($_GET['edit_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (($formState['mode'] ?? '') === 'create') {
    $createName = is_scalar($formState['name'] ?? null) ? trim((string) $formState['name']) : '';
    $createErrors = is_array($formState['errors'] ?? null) ? $formState['errors'] : [];
    $openCreate = true;
}

$editCity = null;
$editName = '';
$editErrors = [];
if (($formState['mode'] ?? '') === 'edit') {
    $openEditId = filter_var($formState['city_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $editName = is_scalar($formState['name'] ?? null) ? trim((string) $formState['name']) : '';
    $editErrors = is_array($formState['errors'] ?? null) ? $formState['errors'] : [];
}
if ($openEditId !== false && $openEditId !== null) {
    foreach ($cities as $listedCity) {
        if ((int) $listedCity['id'] === $openEditId) {
            $editCity = $listedCity;
            if (($formState['mode'] ?? '') !== 'edit') $editName = $listedCity['name'];
            break;
        }
    }
}

$successMessage = citySuccessMessage();
$errorMessage = match ($_GET['error'] ?? '') {
    'not_found' => 'Grad nije pronađen ili nemate dozvolu za pristup.',
    'has_contacts' => 'Nije moguće obrisati grad koji je povezan sa kontaktima.',
    default => ($openEditId && $editCity === null) ? 'Grad nije pronađen ili nemate dozvolu za pristup.' : null,
};
$activeNavigation = 'cities';
?>
<!doctype html>
<html lang="sr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gradovi | Address Book</title>
    <link rel="stylesheet" href="/assets/css/cities.css">
    <link rel="stylesheet" href="/assets/css/dialogs.css">
    <script src="/assets/js/form-ui.js" defer></script>
</head>
<body>
    <div class="app-shell">
        <aside class="sidebar">
            <a class="brand" href="/dashboard.php">Address Book</a>
            <?php require dirname(__DIR__) . '/app/views/main-navigation.php'; ?>
            <form action="/logout.php" method="post" class="logout-form"><button class="logout-button" type="submit">Odjava</button></form>
        </aside>
        <main class="main-content">
            <header class="page-header">
                <div><p class="eyebrow">LOKACIJE</p><h1>Gradovi</h1></div>
                <button class="button button-primary" type="button" data-dialog-open="city-create-dialog">Dodaj novi grad</button>
            </header>
            <?php if ($successMessage !== null): ?><p class="message message-success" role="status"><?= escapeHtml($successMessage) ?></p><?php endif; ?>
            <?php if ($errorMessage !== null): ?><p class="message message-error" role="alert"><?= escapeHtml($errorMessage) ?></p><?php endif; ?>

            <?php if ($cities === []): ?>
                <section class="empty-state"><h2>Još nema gradova</h2><p>Trenutno nemate nijedan grad.</p><button class="button button-primary" type="button" data-dialog-open="city-create-dialog">Dodaj novi grad</button></section>
            <?php else: ?>
                <div class="table-wrap"><table>
                    <thead><tr><th scope="col">Naziv grada</th><th scope="col">Akcije</th></tr></thead>
                    <tbody><?php foreach ($cities as $city): ?>
                        <tr>
                            <td data-label="Naziv grada"><?= escapeHtml($city['name']) ?></td>
                            <td data-label="Akcije"><div class="row-actions">
                                <a class="button button-small button-secondary" href="/cities.php?edit_id=<?= (int) $city['id'] ?>">Izmeni</a>
                                <button class="button button-small button-danger" type="button" data-confirm-open="city-delete-dialog" data-delete-id="<?= (int) $city['id'] ?>">Izbriši</button>
                            </div></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </main>
    </div>

    <?php
    $dialogId = 'city-create-dialog'; $formIdPrefix = 'city-create'; $dialogTitle = 'Dodaj novi grad';
    $formAction = '/city-create.php'; $cityName = $createName; $errors = $createErrors; $openDialog = $openCreate;
    unset($cityId);
    require dirname(__DIR__) . '/app/views/city-form.php';
    if ($editCity !== null) {
        $dialogId = 'city-edit-dialog'; $formIdPrefix = 'city-edit'; $dialogTitle = 'Izmeni grad';
        $formAction = '/city-edit.php?id=' . (int) $editCity['id']; $cityName = $editName;
        $errors = $editErrors; $cityId = (int) $editCity['id'];
        $openDialog = (($formState['mode'] ?? '') === 'edit') || (($_GET['edit_id'] ?? '') !== '');
        require dirname(__DIR__) . '/app/views/city-form.php';
    }
    ?>
    <dialog class="app-dialog" id="city-delete-dialog" aria-labelledby="city-delete-title">
        <section class="dialog-panel">
            <header class="dialog-header">
                <h2 id="city-delete-title">Brisanje grada</h2>
                <button class="dialog-close" type="button" data-dialog-close aria-label="Zatvori dijalog">&times;</button>
            </header>
            <p>Da li ste sigurni da želite da obrišete ovaj grad?</p>
            <form method="post" action="/city-delete.php" class="form-actions">
                <input type="hidden" name="city_id" value="" data-confirm-id>
                <button class="button button-secondary" type="button" data-dialog-close>Otkaži</button>
                <button class="button button-danger" type="submit">Izbriši</button>
            </form>
        </section>
    </dialog>
</body>
</html>
