<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';

if (isAuthenticated()) {
    redirectTo('/dashboard.php');
}

$errors = [];
$email = '';
$successMessage = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');

    if ($email === '' || $password === '' || $passwordConfirmation === '') {
        $errors[] = 'Popunite sva polja.';
    }

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Unesite ispravnu email adresu.';
    }

    if ($password !== '' && $passwordConfirmation !== '' && $password !== $passwordConfirmation) {
        $errors[] = 'Lozinka i potvrda lozinke se ne podudaraju.';
    }

    if ($errors === []) {
        try {
            if (registerUser($email, $password)) {
                $_SESSION['flash_success'] = 'Registracija je uspešna. Sada se možete prijaviti.';
                redirectTo('/login.php');
            }

            $errors[] = 'Nalog sa ovom email adresom već postoji.';
        } catch (PDOException $exception) {
            error_log('Registration database error: ' . $exception->getMessage());
            http_response_code(500);
            $errors[] = 'Registracija trenutno nije dostupna. Pokušajte ponovo kasnije.';
        }
    }
}
?>
<!doctype html>
<html lang="sr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registracija | Address Book</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/auth.css">
    <script src="/assets/js/form-submit-state.js" defer></script>
</head>
<body class="auth-page">
    <main class="auth-card">
        <a class="auth-brand" href="/">ADDRESS BOOK</a>
        <h1>Kreirajte nalog</h1>
        <?php if ($successMessage !== null): ?>
            <p class="message message-success" role="status"><?= escapeHtml((string) $successMessage) ?></p>
        <?php endif; ?>
        <?php foreach ($errors as $error): ?>
            <p class="message message-error" role="alert"><?= escapeHtml($error) ?></p>
        <?php endforeach; ?>
        <form method="post" action="/register.php" data-pending-submit>
            <label for="email">Email</label>
            <input id="email" name="email" type="email" maxlength="255" autocomplete="email" required value="<?= escapeHtml($email) ?>">

            <label for="password">Lozinka</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required>

            <label for="password_confirmation">Potvrdite lozinku</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>

            <button class="button button-primary" type="submit" data-pending-label="Registracija...">Registrujte se</button>
        </form>
        <p>Već imate nalog? <a href="/login.php">Prijavite se</a>.</p>
    </main>
</body>
</html>
