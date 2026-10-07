<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/csrf.php';
require_once dirname(__DIR__) . '/app/tag-helpers.php';

if (!isAuthenticated()) {
    redirectTo('/login.php');
}

$userId = currentUserId();
try {
    $statement = db()->prepare('SELECT id, name FROM tags WHERE user_id = :user_id ORDER BY name ASC');
    $statement->execute(['user_id' => $userId]);
    $tags = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log('Tag list database error.');
    http_response_code(500);
    exit('Tagovi trenutno nisu dostupni. Pokušajte ponovo kasnije.');
}

$formState = $_SESSION['tag_form_state'] ?? null;
unset($_SESSION['tag_form_state']);
$formState = is_array($formState) ? $formState : [];

$createName = '';
$createErrors = [];
$openCreate = ($_GET['modal'] ?? '') === 'create';
if (($formState['mode'] ?? '') === 'create') {
    $createName = is_string($formState['name'] ?? null) ? $formState['name'] : '';
    $createErrors = is_array($formState['errors'] ?? null) ? $formState['errors'] : [];
    $openCreate = true;
}

$editTag = null;
$editName = '';
$editErrors = [];
$editIdInput = ($formState['mode'] ?? '') === 'edit'
    ? ($formState['tag_id'] ?? null)
    : ($_GET['edit_id'] ?? null);
$editId = filter_var($editIdInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$editRequested = $editIdInput !== null && $editIdInput !== '';

if (($formState['mode'] ?? '') === 'edit') {
    $editName = is_string($formState['name'] ?? null) ? $formState['name'] : '';
    $editErrors = is_array($formState['errors'] ?? null) ? $formState['errors'] : [];
}

if ($editRequested && $editId !== false && $editId !== null) {
    foreach ($tags as $tag) {
        if ((int) $tag['id'] === $editId) {
            $editTag = $tag;
            if (($formState['mode'] ?? '') !== 'edit') {
                $editName = (string) $tag['name'];
            }
            break;
        }
    }
}

$successMessage = tagSuccessMessage();
$errorMessage = match ($_GET['error'] ?? '') {
    'not_found' => 'Tag nije pronađen ili nemate dozvolu za pristup.',
    'csrf' => 'Zahtev nije prihvaćen. Osvežite stranicu i pokušajte ponovo.',
    default => ($editRequested && $editTag === null)
        ? 'Tag nije pronađen ili nemate dozvolu za pristup.'
        : null,
};
$activeNavigation = 'tags';
$baseUrl = rtrim($config['app']['base_url'], '/');
?>
<!doctype html>
<html lang="sr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tagovi | Address Book</title>
    <link rel="stylesheet" href="<?= escapeHtml($baseUrl) ?>/assets/css/app.css">
    <link rel="stylesheet" href="<?= escapeHtml($baseUrl) ?>/assets/css/tags.css">
    <link rel="stylesheet" href="<?= escapeHtml($baseUrl) ?>/assets/css/dialogs.css">
    <script src="<?= escapeHtml($baseUrl) ?>/assets/js/form-ui.js" defer></script>
    <script src="<?= escapeHtml($baseUrl) ?>/assets/js/form-submit-state.js" defer></script>
</head>
<body>
    <div class="app-shell">
        <aside class="sidebar">
            <a class="brand" href="<?= escapeHtml($baseUrl) ?>/dashboard.php">Address Book</a>
            <?php require dirname(__DIR__) . '/app/views/main-navigation.php'; ?>
            <form action="<?= escapeHtml($baseUrl) ?>/logout.php" method="post" class="logout-form">
                <button class="logout-button" type="submit">Odjava</button>
            </form>
        </aside>
        <main class="main-content">
            <header class="page-header">
                <div><p class="eyebrow">ORGANIZACIJA</p><h1>Tagovi</h1></div>
                <button class="button button-primary" type="button" data-dialog-open="tag-create-dialog">Dodaj novi tag</button>
            </header>

            <?php if ($successMessage !== null): ?><p class="message message-success" role="status"><?= escapeHtml($successMessage) ?></p><?php endif; ?>
            <?php if ($errorMessage !== null): ?><p class="message message-error" role="alert"><?= escapeHtml($errorMessage) ?></p><?php endif; ?>

            <?php if ($tags === []): ?>
                <section class="empty-state tags-empty-state">
                    <h2>Još nema tagova</h2>
                    <p>Dodajte tagove da biste ih kasnije koristili za organizaciju kontakata.</p>
                    <button class="button button-primary" type="button" data-dialog-open="tag-create-dialog">Dodaj novi tag</button>
                </section>
            <?php else: ?>
                <div class="table-wrap tags-table-wrap">
                    <table>
                        <thead><tr><th scope="col">Naziv taga</th><th scope="col">Akcije</th></tr></thead>
                        <tbody>
                        <?php foreach ($tags as $tag): ?>
                            <tr>
                                <td data-label="Naziv taga"><?= escapeHtml((string) $tag['name']) ?></td>
                                <td data-label="Akcije"><div class="row-actions">
                                    <a class="button button-small button-secondary" href="<?= escapeHtml($baseUrl) ?>/tags.php?edit_id=<?= (int) $tag['id'] ?>">Izmeni</a>
                                    <button class="button button-small button-danger" type="button" data-confirm-open="tag-delete-dialog" data-delete-id="<?= (int) $tag['id'] ?>" data-delete-label="<?= escapeHtml((string) $tag['name']) ?>">Izbriši</button>
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
    $dialogId = 'tag-create-dialog';
    $formIdPrefix = 'tag-create';
    $dialogTitle = 'Dodaj novi tag';
    $formAction = $baseUrl . '/tag-create.php';
    $tagName = $createName;
    $errors = $createErrors;
    $openDialog = $openCreate;
    $resetOnClose = true;
    unset($tagId);
    require dirname(__DIR__) . '/app/views/tag-form.php';

    if ($editTag !== null) {
        $dialogId = 'tag-edit-dialog';
        $formIdPrefix = 'tag-edit';
        $dialogTitle = 'Izmeni tag';
        $formAction = $baseUrl . '/tag-edit.php';
        $tagName = $editName;
        $errors = $editErrors;
        $tagId = (int) $editTag['id'];
        $openDialog = ($formState['mode'] ?? '') === 'edit' || $editRequested;
        $resetOnClose = false;
        require dirname(__DIR__) . '/app/views/tag-form.php';
    }
    ?>

    <dialog class="app-dialog" id="tag-delete-dialog" aria-labelledby="tag-delete-title" data-confirm-entity="tag" data-confirm-static-message="true">
        <section class="dialog-panel">
            <header class="dialog-header">
                <h2 id="tag-delete-title">Brisanje taga</h2>
                <button class="dialog-close" type="button" data-dialog-close aria-label="Zatvori dijalog">&times;</button>
            </header>
            <p data-confirm-message>Brisanjem taga ukloniće se njegove veze sa kontaktima. Kontakti će ostati sačuvani. Da li želite da nastavite?</p>
            <form method="post" action="<?= escapeHtml($baseUrl) ?>/tag-delete.php" class="form-actions" data-pending-submit>
                <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                <input type="hidden" name="tag_id" value="" data-confirm-id>
                <button class="button button-secondary" type="button" data-dialog-close>Otkaži</button>
                <button class="button button-danger" type="submit" data-pending-label="Brisanje...">Izbriši</button>
            </form>
        </section>
    </dialog>
</body>
</html>
