<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/csrf.php';
require_once dirname(__DIR__) . '/app/api-token-helpers.php';

header('Cache-Control: private, no-store');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');

if (!isAuthenticated()) {
    redirectTo('/login.php');
}

$userId = (int) currentUserId();
$baseUrl = rtrim((string) ($config['app']['base_url'] ?? ''), '/');
$formName = '';
$formErrors = [];
$notice = $_SESSION['api_token_notice'] ?? null;
unset($_SESSION['api_token_notice']);
$createdToken = $_SESSION['api_token_flash'] ?? null;
unset($_SESSION['api_token_flash']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $_SESSION['api_token_notice'] = ['type' => 'error', 'text' => 'Zahtev nije prihvaćen. Osvežite stranicu i pokušajte ponovo.'];
        redirectTo('/api-tokens.php');
    }

    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if ($action === 'create') {
        $rawName = is_string($_POST['name'] ?? null) ? $_POST['name'] : '';
        $formName = normalizeApiTokenName($rawName) ?? '';
        $formErrors = validateApiTokenName($formName);
        if ($formErrors !== []) {
            $_SESSION['api_token_form'] = ['name' => $formName, 'errors' => $formErrors];
            redirectTo('/api-tokens.php');
        }

        try {
            $createdToken = createApiToken(db(), $userId, $formName);
            $createdToken['name'] = $formName;
            $_SESSION['api_token_flash'] = $createdToken;
            $_SESSION['api_token_notice'] = ['type' => 'success', 'text' => 'API token je kreiran. Kopirajte ga sada; kasnije više neće biti prikazan.'];
            redirectTo('/api-tokens.php');
        } catch (Throwable $exception) {
            error_log('API token creation failed.');
            $_SESSION['api_token_form'] = [
                'name' => $formName,
                'errors' => ['_form' => 'Token trenutno nije moguće kreirati. Pokušajte ponovo.'],
            ];
            redirectTo('/api-tokens.php');
        }
    }

    if ($action === 'revoke') {
        $tokenId = filter_var($_POST['token_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        try {
            $revoked = $tokenId !== false && $tokenId !== null
                && revokeApiToken(db(), $userId, (int) $tokenId);
            $_SESSION['api_token_notice'] = $revoked
                ? ['type' => 'success', 'text' => 'API token je opozvan.']
                : ['type' => 'error', 'text' => 'Token nije pronađen ili je već opozvan.'];
        } catch (Throwable $exception) {
            error_log('API token revocation failed.');
            $_SESSION['api_token_notice'] = ['type' => 'error', 'text' => 'Token trenutno nije moguće opozvati. Pokušajte ponovo.'];
        }
        redirectTo('/api-tokens.php');
    }

    $_SESSION['api_token_notice'] = ['type' => 'error', 'text' => 'Nepoznata akcija.'];
    redirectTo('/api-tokens.php');
}

$formState = $_SESSION['api_token_form'] ?? null;
unset($_SESSION['api_token_form']);
if (is_array($formState)) {
    $formName = is_string($formState['name'] ?? null) ? $formState['name'] : '';
    $formErrors = is_array($formState['errors'] ?? null) ? $formState['errors'] : [];
}

try {
    $statement = db()->prepare(
        'SELECT id, name, created_at, expires_at, revoked_at
         FROM api_tokens WHERE user_id = :user_id
         ORDER BY created_at DESC, id DESC'
    );
    $statement->execute(['user_id' => $userId]);
    $tokens = $statement->fetchAll();
} catch (Throwable $exception) {
    error_log('API token list retrieval failed.');
    http_response_code(500);
    exit('API tokeni trenutno nisu dostupni. Pokušajte ponovo kasnije.');
}

$activeNavigation = 'api_tokens';
?>
<!doctype html>
<html lang="sr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>API tokeni | Address Book</title>
    <link rel="stylesheet" href="<?= escapeHtml($baseUrl) ?>/assets/css/app.css">
    <link rel="stylesheet" href="<?= escapeHtml($baseUrl) ?>/assets/css/api-tokens.css">
</head>
<body>
    <div class="app-shell">
        <aside class="sidebar">
            <a class="brand" href="<?= escapeHtml($baseUrl) ?>/dashboard.php">Address Book</a>
            <?php require dirname(__DIR__) . '/app/views/main-navigation.php'; ?>
            <form action="<?= escapeHtml($baseUrl) ?>/logout.php" method="post" class="logout-form">
                <button class="logout-button" type="submit">Odjava</button>
            </form>
        </aside>
        <main class="main-content api-tokens-page">
            <header class="page-header">
                <div><p class="eyebrow">DEVELOPERS</p><h1>API tokeni</h1></div>
            </header>
            <a class="button button-small api-tokens-back" href="<?= escapeHtml($baseUrl) ?>/dashboard.php">← Dashboard</a>

            <?php if (is_array($notice) && is_string($notice['text'] ?? null)): ?>
                <p class="message <?= ($notice['type'] ?? '') === 'success' ? 'message-success' : 'message-error' ?>" role="status"><?= escapeHtml($notice['text']) ?></p>
            <?php endif; ?>

            <?php if (is_array($createdToken) && is_string($createdToken['token'] ?? null)): ?>
                <section class="api-token-reveal" aria-labelledby="api-token-reveal-title">
                    <h2 id="api-token-reveal-title">Kopirajte novi token</h2>
                    <p>Ovo je jedini put da će pun token biti prikazan. Sačuvajte ga na bezbednom mestu; zatim zatvorite ili napustite ovu stranicu.</p>
                    <code><?= escapeHtml($createdToken['token']) ?></code>
                    <p class="api-token-expiry">Ističe: <?= escapeHtml(formatApiTokenTime((string) ($createdToken['expires_at'] ?? ''))) ?></p>
                </section>
            <?php endif; ?>

            <section class="api-token-panel" aria-labelledby="api-token-create-title">
                <h2 id="api-token-create-title">Kreiraj API token</h2>
                <p>Token važi 30 dana. Koristite ga samo preko HTTPS API zahteva.</p>
                <?php if (isset($formErrors['_form'])): ?><p class="message message-error" role="alert"><?= escapeHtml((string) $formErrors['_form']) ?></p><?php endif; ?>
                <form method="post" action="<?= escapeHtml($baseUrl) ?>/api-tokens.php" class="api-token-create-form">
                    <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="field-group">
                        <label for="api-token-name">Naziv</label>
                        <input id="api-token-name" name="name" type="text" value="<?= escapeHtml($formName) ?>" required aria-describedby="api-token-name-error">
                        <?php if (isset($formErrors['name'])): ?><small class="field-error" id="api-token-name-error"><?= escapeHtml((string) $formErrors['name']) ?></small><?php else: ?><small id="api-token-name-error">Do 100 Unicode znakova.</small><?php endif; ?>
                    </div>
                    <button class="button button-primary" type="submit">Kreiraj token</button>
                </form>
            </section>

            <section class="api-token-panel" aria-labelledby="api-token-list-title">
                <h2 id="api-token-list-title">Vaši tokeni</h2>
                <?php if ($tokens === []): ?>
                    <div class="api-token-empty-state"><p>Još nemate API tokene.</p></div>
                <?php else: ?>
                    <div class="api-token-list">
                        <?php foreach ($tokens as $token): ?>
                            <?php
                            $isRevoked = $token['revoked_at'] !== null;
                            $isExpired = strtotime((string) $token['expires_at'] . ' UTC') <= time();
                            $status = $isRevoked ? 'Opozvan' : ($isExpired ? 'Istekao' : 'Aktivan');
                            ?>
                            <article class="api-token-item">
                                <div class="api-token-details">
                                    <h3><?= escapeHtml((string) $token['name']) ?></h3>
                                    <p>Status: <strong><?= escapeHtml($status) ?></strong></p>
                                    <p>Kreiran: <?= escapeHtml(formatApiTokenTime((string) $token['created_at'])) ?></p>
                                    <p>Ističe: <?= escapeHtml(formatApiTokenTime((string) $token['expires_at'])) ?></p>
                                </div>
                                <?php if (!$isRevoked): ?>
                                    <form method="post" action="<?= escapeHtml($baseUrl) ?>/api-tokens.php" class="api-token-revoke-form">
                                        <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                                        <input type="hidden" name="action" value="revoke">
                                        <input type="hidden" name="token_id" value="<?= (int) $token['id'] ?>">
                                        <button class="button button-small button-danger" type="submit">Opozovi</button>
                                    </form>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</body>
</html>
