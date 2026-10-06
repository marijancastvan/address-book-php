<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
$baseUrl = rtrim($config['app']['base_url'], '/');

if (!isAuthenticated()) {
    redirectTo('/login.php');
}

redirectTo('/dashboard.php');
?>
<!doctype html>
<html lang="sr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privremena provera prijave | Address Book</title>
    <link rel="stylesheet" href="<?= escapeHtml($baseUrl) ?>/assets/css/auth.css">
</head>
<body>
    <main class="auth-card">
        <h1>Privremena zaštićena stranica</h1>
        <p>Autentifikacija radi. ID prijavljenog korisnika: <?= (int) currentUserId() ?></p>
        <p>Ova stranica služi samo za proveru session-a i nije dashboard.</p>
        <form action="<?= escapeHtml($baseUrl) ?>/logout.php" method="post">
            <button type="submit">Odjavi se</button>
        </form>
        <p><a href="<?= escapeHtml($baseUrl) ?>/">Početna</a></p>
    </main>
</body>
</html>
