<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/csrf.php';
require_once dirname(__DIR__) . '/app/password-reset.php';

header('Cache-Control: no-store');

$errors = [];
$email = '';
$successMessage = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);
$baseUrl = rtrim($config['app']['base_url'], '/');
$genericMessage = 'Ako nalog sa unetom email adresom postoji i slanje emaila je dostupno, dobićete link za reset lozinke.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $errors[] = 'Zahtev nije validan. Osvežite stranicu i pokušajte ponovo.';
    } else {
        $emailInput = $_POST['email'] ?? '';
        $email = is_scalar($emailInput) ? strtolower(trim((string) $emailInput)) : '';

        if ($email === '') {
            $errors[] = 'Unesite email adresu.';
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Unesite ispravnu email adresu.';
        } else {
            $lastRequestAt = $_SESSION['password_reset_last_request_at'] ?? 0;
            $isSessionLimited = is_int($lastRequestAt) && time() - $lastRequestAt < 30;

            if (!$isSessionLimited) {
                $_SESSION['password_reset_last_request_at'] = time();
                try {
                    $pdo = db();
                    $userStatement = $pdo->prepare('SELECT id, email FROM users WHERE email = :email LIMIT 1');
                    $userStatement->execute(['email' => $email]);
                    $user = $userStatement->fetch();

                    if ($user !== false) {
                        $userId = (int) $user['id'];
                        $pdo->beginTransaction();

                        $lockUser = $pdo->prepare('SELECT id FROM users WHERE id = :user_id FOR UPDATE');
                        $lockUser->execute(['user_id' => $userId]);
                        $userStillExists = $lockUser->fetch() !== false;

                        $recentRequests = $pdo->prepare(
                            'SELECT COUNT(*) FROM password_reset_tokens
                             WHERE user_id = :user_id
                               AND created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 HOUR)'
                        );
                        $recentRequests->execute(['user_id' => $userId]);
                        $isAccountLimited = (int) $recentRequests->fetchColumn() >= 3;

                        if (!$userStillExists || $isAccountLimited) {
                            $pdo->rollBack();
                        } else {
                            $rawToken = bin2hex(random_bytes(32));
                            $tokenId = createPasswordResetToken($pdo, $userId, $rawToken);
                            $pdo->commit();

                            if (!sendPasswordResetEmail((string) $user['email'], $rawToken)) {
                                try {
                                    invalidatePasswordResetToken($pdo, $tokenId);
                                } catch (Throwable $invalidateException) {
                                    error_log('Failed to invalidate an undelivered password reset token: ' . $invalidateException->getMessage());
                                }
                            }
                        }
                    }
                } catch (Throwable $exception) {
                    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Password reset request failed: ' . $exception->getMessage());
                }
            }

            $_SESSION['flash_success'] = $genericMessage;
            redirectTo('/forgot-password.php');
        }
    }
}
?>
<!doctype html>
<html lang="sr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Zaboravili ste lozinku? | Address Book</title>
    <link rel="stylesheet" href="<?= escapeHtml($baseUrl) ?>/assets/css/app.css">
    <link rel="stylesheet" href="<?= escapeHtml($baseUrl) ?>/assets/css/auth.css">
    <script src="<?= escapeHtml($baseUrl) ?>/assets/js/form-submit-state.js" defer></script>
</head>
<body class="auth-page">
    <main class="auth-card">
        <a class="auth-brand" href="<?= escapeHtml($baseUrl) ?>/">ADDRESS BOOK</a>
        <h1>Zaboravili ste lozinku?</h1>
        <p>Unesite email adresu povezanu sa nalogom. Ako nalog postoji i slanje emaila je dostupno, dobićete link za reset lozinke.</p>
        <?php if ($successMessage !== null): ?>
            <p class="message message-info" role="status"><?= escapeHtml((string) $successMessage) ?></p>
        <?php endif; ?>
        <?php foreach ($errors as $error): ?>
            <p class="message message-error" role="alert"><?= escapeHtml($error) ?></p>
        <?php endforeach; ?>
        <form method="post" action="<?= escapeHtml($baseUrl) ?>/forgot-password.php" data-pending-submit>
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" maxlength="255" autocomplete="email" required value="<?= escapeHtml($email) ?>">
            <button class="button button-primary" type="submit" data-pending-label="Šaljem...">Pošalji link za reset lozinke</button>
        </form>
        <p><a href="<?= escapeHtml($baseUrl) ?>/login.php">Nazad na prijavu</a></p>
    </main>
</body>
</html>
