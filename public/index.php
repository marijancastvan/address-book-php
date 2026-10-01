<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
?>
<!doctype html>
<html lang="sr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Address Book – PHP Clone</title>
</head>
<body>
    <main>
        <h1>Address Book – PHP Clone</h1>
        <p>PHP aplikacija je pokrenuta.</p>
        <?php if (isAuthenticated()): ?>
            <p><a href="/dashboard.php">Dashboard</a></p>
            <form action="/logout.php" method="post">
                <button type="submit">Odjavi se</button>
            </form>
        <?php else: ?>
            <p><a href="/login.php">Prijava</a> | <a href="/register.php">Registracija</a></p>
        <?php endif; ?>
    </main>
</body>
</html>
