<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
$baseUrl = rtrim($config['app']['base_url'], '/');
?>
<!doctype html>
<html lang="sr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f4f7fc">
    <title>Address Book | Vaš lični adresar</title>
    <link rel="stylesheet" href="<?= escapeHtml($baseUrl) ?>/assets/css/app.css">
</head>
<body class="home-page">
    <main class="home-card">
        <h1 class="home-title">ADDRESS BOOK</h1>
        <p class="home-tagline">VAŠ LIČNI ADRESAR</p>
        <div class="home-actions">
            <?php if (isAuthenticated()): ?>
                <a class="button button-primary" href="<?= escapeHtml($baseUrl) ?>/dashboard.php">Otvori Dashboard</a>
                <form action="<?= escapeHtml($baseUrl) ?>/logout.php" method="post" class="home-logout-form">
                    <button class="button button-secondary" type="submit">Odjavi se</button>
                </form>
            <?php else: ?>
                <a class="button button-primary" href="<?= escapeHtml($baseUrl) ?>/login.php">Prijavite se</a>
                <a class="button button-secondary" href="<?= escapeHtml($baseUrl) ?>/register.php">Kreirajte nalog</a>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
