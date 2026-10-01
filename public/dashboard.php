<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';

if (!isAuthenticated()) {
    redirectTo('/login.php');
}

try {
    $statement = db()->prepare('SELECT email FROM users WHERE id = :id LIMIT 1');
    $statement->execute(['id' => currentUserId()]);
    $user = $statement->fetch();
} catch (PDOException $exception) {
    error_log('Dashboard database error: ' . $exception->getMessage());
    http_response_code(500);
    exit('Dashboard trenutno nije dostupan. Pokušajte ponovo kasnije.');
}

if ($user === false) {
    logoutUser();
    redirectTo('/login.php');
}

$email = (string) $user['email'];
?>
<!doctype html>
<html lang="sr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | Address Book</title>
    <link rel="stylesheet" href="/assets/css/dashboard.css">
</head>
<body>
    <div class="app-shell">
        <aside class="sidebar">
            <a class="brand" href="/dashboard.php">Address Book</a>
            <nav aria-label="Glavna navigacija">
                <a class="nav-link active" href="/dashboard.php" aria-current="page">Dashboard</a>
                <a class="nav-link" href="/contacts.php">Kontakti</a>
                <a class="nav-link" href="#cities">Gradovi</a>
            </nav>
            <form action="/logout.php" method="post" class="logout-form">
                <button class="logout-button" type="submit">Odjava</button>
            </form>
        </aside>

        <main class="main-content">
            <header class="page-header">
                <p class="eyebrow">ADDRESS BOOK</p>
                <h1>Dashboard</h1>
                <p class="welcome">Dobrodošli!</p>
            </header>

            <section class="account-card" aria-labelledby="account-heading">
                <div>
                    <p class="eyebrow">TRENUTNO PRIJAVLJENI KORISNIK</p>
                    <h2 id="account-heading"><?= escapeHtml($email) ?></h2>
                </div>
            </section>

            <section class="feature-grid" aria-label="Delovi aplikacije">
                <article class="feature-card" id="contacts">
                    <p class="eyebrow">ADRESAR</p>
                    <h2>Kontakti</h2>
                    <p>Pregledajte i uredite svoje kontakte.</p>
                    <a href="/contacts.php">Otvori kontakte</a>
                </article>
                <article class="feature-card" id="cities">
                    <p class="eyebrow">LOKACIJE</p>
                    <h2>Gradovi</h2>
                    <p>Upravljanje gradovima biće dodato u narednoj fazi.</p>
                </article>
            </section>
        </main>
    </div>
</body>
</html>
