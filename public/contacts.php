<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/contact-helpers.php';

if (!isAuthenticated()) {
    redirectTo('/login.php');
}

try {
    $statement = db()->prepare(
        'SELECT contacts.id, contacts.first_name, contacts.last_name,
                contacts.phone, contacts.email, cities.name AS city_name
         FROM contacts
         INNER JOIN cities ON cities.id = contacts.city_id
         WHERE contacts.user_id = :user_id
         ORDER BY contacts.last_name, contacts.first_name'
    );
    $statement->execute(['user_id' => currentUserId()]);
    $contacts = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log('Contacts list database error: ' . $exception->getMessage());
    http_response_code(500);
    exit('Kontakti trenutno nisu dostupni. Pokušajte ponovo kasnije.');
}

$successMessage = contactSuccessMessage();
$errorMessage = ($_GET['error'] ?? '') === 'not_found'
    ? 'Kontakt nije pronađen ili nemate dozvolu za pristup.'
    : null;
?>
<!doctype html>
<html lang="sr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kontakti | Address Book</title>
    <link rel="stylesheet" href="/assets/css/contacts.css">
</head>
<body>
    <div class="app-shell">
        <aside class="sidebar">
            <a class="brand" href="/dashboard.php">Address Book</a>
            <nav aria-label="Glavna navigacija">
                <a class="nav-link" href="/dashboard.php">Dashboard</a>
                <a class="nav-link active" href="/contacts.php" aria-current="page">Kontakti</a>
            </nav>
            <form action="/logout.php" method="post" class="logout-form">
                <button class="logout-button" type="submit">Odjava</button>
            </form>
        </aside>

        <main class="main-content">
            <header class="page-header">
                <div>
                    <p class="eyebrow">ADRESAR</p>
                    <h1>Kontakti</h1>
                </div>
                <a class="button button-primary" href="/contact-create.php">Dodaj kontakt</a>
            </header>

            <?php if ($successMessage !== null): ?>
                <p class="message message-success" role="status"><?= escapeHtml($successMessage) ?></p>
            <?php endif; ?>
            <?php if ($errorMessage !== null): ?>
                <p class="message message-error" role="alert"><?= escapeHtml($errorMessage) ?></p>
            <?php endif; ?>

            <?php if ($contacts === []): ?>
                <section class="empty-state">
                    <h2>Još nema kontakata</h2>
                    <p>Trenutno nemate nijedan kontakt.</p>
                    <a class="button button-primary" href="/contact-create.php">Dodaj kontakt</a>
                </section>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">Ime</th>
                                <th scope="col">Prezime</th>
                                <th scope="col">Telefon</th>
                                <th scope="col">Email</th>
                                <th scope="col">Grad</th>
                                <th scope="col">Akcije</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contacts as $contact): ?>
                                <tr>
                                    <td data-label="Ime"><?= escapeHtml($contact['first_name']) ?></td>
                                    <td data-label="Prezime"><?= escapeHtml($contact['last_name']) ?></td>
                                    <td data-label="Telefon"><?= escapeHtml($contact['phone']) ?></td>
                                    <td data-label="Email"><?= escapeHtml($contact['email']) ?></td>
                                    <td data-label="Grad"><?= escapeHtml($contact['city_name']) ?></td>
                                    <td data-label="Akcije">
                                        <div class="row-actions">
                                            <a class="button button-small button-secondary" href="/contact-edit.php?id=<?= (int) $contact['id'] ?>">Izmeni</a>
                                            <form method="post" action="/contact-delete.php" onsubmit="return confirm('Da li sigurno želite da obrišete ovaj kontakt?');">
                                                <input type="hidden" name="contact_id" value="<?= (int) $contact['id'] ?>">
                                                <button class="button button-small button-danger" type="submit">Obriši</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
