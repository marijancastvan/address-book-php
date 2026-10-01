<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';

if (isAuthenticated()) {
    redirectTo('/protected.php');
}

$errors = [];
$email = '';
$successMessage = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $errors[] = 'Unesite email adresu i lozinku.';
    } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Email ili lozinka nisu ispravni.';
    } else {
        try {
            if (attemptLogin($email, $password)) {
                redirectTo('/protected.php');
            }

            $errors[] = 'Email ili lozinka nisu ispravni.';
        } catch (PDOException $exception) {
            error_log('Login database error: ' . $exception->getMessage());
            http_response_code(500);
            $errors[] = 'Prijava trenutno nije dostupna. Pokušajte ponovo kasnije.';
        }
    }
}
?>
<!doctype html>
<html lang="sr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Prijava | Address Book</title>
    <link rel="stylesheet" href="/assets/css/auth.css">
</head>
<body>
    <main class="auth-card">
        <h1>Prijava</h1>
        <?php if ($successMessage !== null): ?>
            <p class="message message-success"><?= escapeHtml((string) $successMessage) ?></p>
        <?php endif; ?>
        <?php foreach ($errors as $error): ?>
            <p class="message message-error"><?= escapeHtml($error) ?></p>
        <?php endforeach; ?>
        <form method="post" action="/login.php">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" maxlength="255" autocomplete="email" required value="<?= escapeHtml($email) ?>">

            <label for="password">Lozinka</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>

            <button type="submit">Prijavite se</button>
        </form>
        <p>Nemate nalog? <a href="/register.php">Registrujte se</a>.</p>
    </main>
</body>
</html>
