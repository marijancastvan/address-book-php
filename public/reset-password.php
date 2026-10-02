<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/csrf.php';
require_once dirname(__DIR__) . '/app/password-reset.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$tokenInput = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['token'] ?? '')
    : ($_GET['token'] ?? '');
$token = is_string($tokenInput) ? trim($tokenInput) : '';
$errors = [];
$tokenIsValid = false;
$databaseError = false;
$pdo = null;

try {
    $pdo = db();
    $tokenIsValid = findValidPasswordResetToken($pdo, $token) !== null;
} catch (Throwable $exception) {
    error_log('Password reset token lookup failed: ' . $exception->getMessage());
    $databaseError = true;
    $errors[] = 'Reset lozinke trenutno nije dostupan. Pokušajte ponovo kasnije.';
}

$csrfRejected = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $csrfRejected = true;
        $errors[] = 'Zahtev nije validan. Osvežite stranicu i pokušajte ponovo.';
    } elseif ($databaseError) {
        http_response_code(500);
    } elseif (!$tokenIsValid) {
        $errors[] = 'Link za reset lozinke više nije važeći.';
    } else {
        $passwordInput = $_POST['password'] ?? '';
        $confirmationInput = $_POST['password_confirmation'] ?? '';
        $password = is_string($passwordInput) ? $passwordInput : '';
        $passwordConfirmation = is_string($confirmationInput) ? $confirmationInput : '';

        if ($password === '' || $passwordConfirmation === '') {
            $errors[] = 'Popunite sva polja.';
        }
        if ($password !== '' && $passwordConfirmation !== '' && $password !== $passwordConfirmation) {
            $errors[] = 'Lozinka i potvrda lozinke se ne podudaraju.';
        }

        if ($errors === []) {
            try {
                $userId = completePasswordReset($pdo, $token, $password);
                if ($userId === null) {
                    $tokenIsValid = false;
                    $errors[] = 'Link za reset lozinke više nije važeći.';
                } else {
                    logoutUser();
                    session_start();
                    session_regenerate_id(true);
                    $_SESSION = ['flash_success' => 'Lozinka je uspešno promenjena. Prijavite se novom lozinkom.'];
                    redirectTo('/login.php');
                }
            } catch (Throwable $exception) {
                if ($pdo instanceof PDO && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Password reset completion failed: ' . $exception->getMessage());
                http_response_code(500);
                $errors[] = 'Lozinku trenutno nije moguće promeniti. Pokušajte ponovo kasnije.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="sr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Promena lozinke | Address Book</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/auth.css">
    <script src="/assets/js/form-submit-state.js" defer></script>
</head>
<body class="auth-page">
    <main class="auth-card">
        <a class="auth-brand" href="/">ADDRESS BOOK</a>
        <h1>Promena lozinke</h1>
        <?php foreach ($errors as $error): ?>
            <p class="message message-error" role="alert"><?= escapeHtml($error) ?></p>
        <?php endforeach; ?>

        <?php if (!$tokenIsValid || $csrfRejected || $databaseError): ?>
            <?php if (!$csrfRejected && !$databaseError && $errors === []): ?>
                <p class="message message-error" role="alert">Link za reset lozinke više nije važeći.</p>
            <?php endif; ?>
            <p><a href="/login.php">Nazad na prijavu</a></p>
        <?php else: ?>
            <p>Unesite novu lozinku i potvrdite je.</p>
            <form method="post" action="/reset-password.php" data-pending-submit>
                <input type="hidden" name="token" value="<?= escapeHtml($token) ?>">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                <label for="password">Nova lozinka</label>
                <input id="password" name="password" type="password" autocomplete="new-password" required>

                <label for="password_confirmation">Potvrdi novu lozinku</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>

                <button class="button button-primary" type="submit" data-pending-label="Menjam lozinku...">Promeni lozinku</button>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
