<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';

if (!isAuthenticated()) {
    redirectTo('/login.php');
}
?>
<!doctype html>
<html lang="sr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privremena provera prijave | Address Book</title>
    <link rel="stylesheet" href="/assets/css/auth.css">
</head>
<body>
    <main class="auth-card">
        <h1>Privremena zaštićena stranica</h1>
        <p>Autentifikacija radi. ID prijavljenog korisnika: <?= (int) currentUserId() ?></p>
        <p>Ova stranica služi samo za proveru session-a i nije dashboard.</p>
        <form action="/logout.php" method="post">
            <button type="submit">Odjavi se</button>
        </form>
        <p><a href="/">Početna</a></p>
    </main>
</body>
</html>
