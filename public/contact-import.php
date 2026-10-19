<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/csrf.php';
require_once dirname(__DIR__) . '/app/contact-import-helpers.php';

if (!isAuthenticated()) {
    redirectTo('/login.php');
}
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');

$userId = (int) currentUserId();
$baseUrl = rtrim((string) ($config['app']['base_url'] ?? ''), '/');
$pdo = db();
$tokenInput = $_GET['token'] ?? $_POST['import_token'] ?? '';
$token = is_string($tokenInput) ? $tokenInput : '';
$tokenHash = preg_match('/\A[a-f0-9]{64}\z/D', $token) === 1 ? hash('sha256', $token) : null;
cleanupExpiredContactImports($pdo, $userId, $tokenHash);

$pageError = null;
$review = null;
$reviewToken = null;
$reviewFingerprint = null;
$createCitiesChecked = false;
$duplicatesChecked = false;
$flash = $_SESSION['contact_import_flash'] ?? null;
unset($_SESSION['contact_import_flash']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $pageError = 'Forma je istekla ili nije validna. Osvežite stranicu i pokušajte ponovo.';
    } else {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        if ($action === 'upload') {
            $separator = ($_POST['separator'] ?? ',') === ';' ? ';' : ',';
            $upload = $_FILES['csv_file'] ?? null;
            if (!is_array($upload)) {
                $pageError = 'Izaberite CSV fajl koji je uspešno otpremljen.';
            } elseif (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE
                || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_FORM_SIZE
            ) {
                $pageError = 'CSV fajl je veći od dozvoljene veličine od 2 MiB.';
            } elseif (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !isset($upload['tmp_name'])
                || !is_string($upload['tmp_name'])
                || !is_uploaded_file($upload['tmp_name'])
            ) {
                $pageError = 'Izaberite CSV fajl koji je uspešno otpremljen.';
            } else {
                $size = filesize($upload['tmp_name']);
                if (!is_int($size) || $size < 1 || $size > CONTACT_IMPORT_MAX_BYTES) {
                    $pageError = 'CSV fajl mora biti veći od 0 i ne sme biti veći od 2 MiB.';
                } else {
                    $contents = file_get_contents($upload['tmp_name']);
                    if (!is_string($contents)) {
                        $pageError = 'CSV fajl trenutno nije moguće pročitati.';
                    } else {
                        $parsed = parseContactImportCsv($contents, $separator);
                        if ($parsed['error'] !== null) {
                            $pageError = $parsed['error'];
                        } else {
                            try {
                                $reviewToken = createContactImportBatch($pdo, $userId, $parsed['rows']);
                                redirectTo('/contact-import.php?' . http_build_query(['token' => $reviewToken]));
                            } catch (Throwable $exception) {
                                error_log('Contact CSV staging failed.');
                                $pageError = 'CSV trenutno nije moguće pripremiti za pregled. Pokušajte ponovo.';
                            }
                        }
                    }
                }
            }
        } elseif ($action === 'cancel') {
            if ($tokenHash !== null) {
                $delete = $pdo->prepare(
                    'DELETE FROM contact_import_batches WHERE user_id = :user_id AND token_hash = :token_hash'
                );
                $delete->execute(['user_id' => $userId, 'token_hash' => $tokenHash]);
            }
            $_SESSION['contact_import_flash'] = 'Pregled importa je otkazan; kontakti i gradovi nisu menjani.';
            redirectTo('/contact-import.php');
        } elseif ($action === 'confirm') {
            $createCitiesChecked = ($_POST['create_missing_cities'] ?? '') === '1';
            $duplicatesChecked = ($_POST['include_duplicates'] ?? '') === '1';
            if ($tokenHash === null) {
                $pageError = 'Pregled importa nije dostupan. Učitajte CSV ponovo.';
            } else {
                try {
                    $result = completeContactImport(
                        $pdo,
                        $userId,
                        $token,
                        is_string($_POST['review_fingerprint'] ?? null) ? $_POST['review_fingerprint'] : '',
                        $createCitiesChecked,
                        $duplicatesChecked,
                    );
                    if ($result['status'] === 'completed') {
                        $_SESSION['contact_import_flash'] = 'Import je završen: kreirano je '
                            . contactImportCountLabel($result['contacts'], 'kontakt', 'kontakta', 'kontakata')
                            . ' i ' . contactImportCountLabel($result['cities'], 'grad', 'grada', 'gradova') . '.';
                        redirectTo('/contact-import.php');
                    }
                    if ($result['status'] === 'review_changed') {
                        $_SESSION['contact_import_flash'] = 'Stanje gradova ili duplikata se promenilo od pregleda. Proverite osvežena upozorenja i ponovo potvrdite import.';
                        redirectTo('/contact-import.php?' . http_build_query(['token' => $token]));
                    }
                    if ($result['status'] === 'consent_required') {
                        $pageError = 'Označite tražene potvrde za nedostajuće gradove i duplikate pre importa.';
                    } elseif ($result['status'] === 'invalid') {
                        $_SESSION['contact_import_flash'] = 'Import nije izvršen jer podaci više ne prolaze validaciju. Pregledajte greške.';
                        redirectTo('/contact-import.php?' . http_build_query(['token' => $token]));
                    } else {
                        $_SESSION['contact_import_flash'] = 'Pregled je istekao, otkazan ili je već upotrebljen. Učitajte CSV ponovo.';
                        redirectTo('/contact-import.php');
                    }
                } catch (Throwable $exception) {
                    error_log('Contact CSV confirmation failed.');
                    $pageError = 'Import nije sačuvan. Sve promene su poništene; proverite pregled i pokušajte ponovo.';
                }
            }
        } else {
            $pageError = 'Nepoznata akcija importa.';
        }
    }
}

if ($reviewToken === null && $tokenHash !== null) {
    $batchQuery = $pdo->prepare(
        'SELECT id, payload, review_fingerprint, expires_at
         FROM contact_import_batches WHERE user_id = :user_id AND token_hash = :token_hash LIMIT 1'
    );
    $batchQuery->execute(['user_id' => $userId, 'token_hash' => $tokenHash]);
    $batch = $batchQuery->fetch();
    if ($batch !== false && strtotime((string) $batch['expires_at'] . ' UTC') > time()) {
        try {
            $sourceRows = json_decode((string) $batch['payload'], true, 512, JSON_THROW_ON_ERROR);
            if (is_array($sourceRows)) {
                $review = buildContactImportReview($pdo, $userId, $sourceRows);
                $reviewToken = $token;
                $reviewFingerprint = $review['fingerprint'];
                $saveFingerprint = $pdo->prepare(
                    'UPDATE contact_import_batches SET review_fingerprint = :fingerprint WHERE id = :id AND user_id = :user_id'
                );
                $saveFingerprint->execute([
                    'fingerprint' => $reviewFingerprint,
                    'id' => (int) $batch['id'],
                    'user_id' => $userId,
                ]);
            }
        } catch (Throwable $exception) {
            error_log('Contact CSV review failed.');
            $review = null;
            $pageError = 'Pregled importa nije moguće učitati. Pokušajte ponovo ili učitajte CSV ponovo.';
        }
    } else {
        if ($batch !== false) {
            $deleteExpired = $pdo->prepare(
                'DELETE FROM contact_import_batches WHERE id = :id AND user_id = :user_id AND expires_at <= UTC_TIMESTAMP()'
            );
            $deleteExpired->execute(['id' => (int) $batch['id'], 'user_id' => $userId]);
        }
        $pageError ??= 'Pregled je istekao, otkazan ili je već upotrebljen. Učitajte CSV ponovo.';
    }
}

function contactImportFieldLabel(string $field): string
{
    return [
        'first_name' => 'Ime',
        'last_name' => 'Prezime',
        'phone' => 'Telefon',
        'email' => 'Email',
        'city' => 'Grad',
    ][$field] ?? $field;
}
?>
<!doctype html>
<html lang="sr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Import CSV kontakata | Address Book</title>
    <link rel="stylesheet" href="<?= escapeHtml($baseUrl) ?>/assets/css/app.css">
    <link rel="stylesheet" href="<?= escapeHtml($baseUrl) ?>/assets/css/contacts.css">
    <script src="<?= escapeHtml($baseUrl) ?>/assets/js/contact-import.js" defer></script>
</head>
<body data-app-base="<?= escapeHtml($baseUrl) ?>">
    <div class="app-shell">
        <aside class="sidebar">
            <?php $activeNavigation = 'contacts'; ?>
            <?php require dirname(__DIR__) . '/app/views/main-navigation.php'; ?>
        </aside>
        <main class="main-content contact-import-page">
            <header class="page-header">
                <div><p class="eyebrow">ADRESAR</p><h1>Import CSV kontakata</h1></div>
            </header>
            <a class="button button-small duplicates-back-link" href="<?= escapeHtml($baseUrl) ?>/contacts.php">← Kontakti</a>

            <?php if (is_string($flash) && $flash !== ''): ?><p class="message message-success" role="status"><?= escapeHtml($flash) ?></p><?php endif; ?>
            <?php if ($pageError !== null): ?><p class="message message-error" role="alert"><?= escapeHtml($pageError) ?></p><?php endif; ?>

            <?php if ($review !== null && $reviewToken !== null): ?>
                <section class="contact-import-panel" aria-labelledby="contact-import-review-title">
                    <div>
                        <h2 id="contact-import-review-title">Pregled importa</h2>
                        <p class="contact-import-note">Pronađeno je <?= count($review['rows']) ?> CSV zapisa. Upload i ovaj pregled nisu kreirali kontakte ni gradove.</p>
                        <p class="contact-import-note">Grešaka: <?= (int) $review['errors_count'] ?>; CSV zapisa sa upozorenjem o duplikatu: <?= (int) $review['warning_rows_count'] ?>.</p>
                    </div>

                    <?php if ($review['missing_cities'] !== []): ?>
                        <div class="contact-import-warning" role="note">
                            <strong>Nedostajući gradovi:</strong>
                            <?= escapeHtml(implode(', ', $review['missing_cities'])) ?>.
                            Bez izričite potvrde import neće kreirati ove gradove.
                        </div>
                    <?php endif; ?>

                    <?php if ($review['duplicate_groups'] !== []): ?>
                        <div class="contact-import-warning" role="note">
                            <strong>Pronađeni su mogući duplikati.</strong>
                            Duplikati se neće automatski spajati niti preskakati. Ako ih uključite, kasnije ih možete pregledati kroz postojeću funkciju Duplikati.
                        </div>
                    <?php endif; ?>
                    <p class="contact-import-note">Poređenje sa starim hosting kontaktima bez normalizovanih ključeva može biti nepotpuno.</p>

                    <div class="table-scroll">
                        <table class="data-table contact-import-table">
                            <thead><tr><th>CSV zapis</th><th>Ime</th><th>Prezime</th><th>Telefon</th><th>Email</th><th>Grad</th><th>Provera</th></tr></thead>
                            <tbody>
                                <?php foreach ($review['rows'] as $row): ?>
                                    <tr>
                                        <td data-label="CSV zapis"><?= (int) $row['record'] ?></td>
                                        <?php foreach (['first_name', 'last_name', 'phone', 'email', 'city'] as $field): ?>
                                            <td data-label="<?= escapeHtml(contactImportFieldLabel($field)) ?>"><?= escapeHtml($row['values'][$field]) ?></td>
                                        <?php endforeach; ?>
                                        <td data-label="Provera">
                                            <?php if ($row['errors'] !== []): ?><ul class="contact-import-row-errors"><?php foreach ($row['errors'] as $field => $error): ?><li><?= escapeHtml(contactImportFieldLabel((string) $field)) ?>: <?= escapeHtml($error) ?></li><?php endforeach; ?></ul><?php endif; ?>
                                            <?php if ($row['warnings'] !== []): ?><ul class="contact-import-row-warnings"><?php foreach ($row['warnings'] as $warning): ?><li><?= escapeHtml($warning) ?></li><?php endforeach; ?></ul><?php endif; ?>
                                            <?php if ($row['errors'] === [] && $row['warnings'] === []): ?>U redu<?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($review['can_import']): ?>
                        <form method="post" action="<?= escapeHtml($baseUrl) ?>/contact-import.php?<?= escapeHtml(http_build_query(['token' => $reviewToken])) ?>">
                            <input type="hidden" name="action" value="confirm">
                            <input type="hidden" name="import_token" value="<?= escapeHtml($reviewToken) ?>">
                            <input type="hidden" name="review_fingerprint" value="<?= escapeHtml($reviewFingerprint ?? '') ?>">
                            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                            <?php if ($review['missing_cities'] !== [] || $review['duplicate_groups'] !== []): ?>
                                <div class="contact-import-checks">
                                    <?php if ($review['missing_cities'] !== []): ?>
                                        <label class="contact-import-check"><input type="checkbox" name="create_missing_cities" value="1" required <?= $createCitiesChecked ? 'checked' : '' ?>><span>Kreiraj nedostajuće gradove navedene iznad.</span></label>
                                    <?php endif; ?>
                                    <?php if ($review['duplicate_groups'] !== []): ?>
                                        <label class="contact-import-check"><input type="checkbox" name="include_duplicates" value="1" required <?= $duplicatesChecked ? 'checked' : '' ?>><span>Razumem da će mogući duplikati biti uvezeni bez spajanja ili preskakanja.</span></label>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <div class="contact-import-actions">
                                <button class="button button-primary" type="submit">Potvrdi import</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <p class="message message-error" role="alert">Import nije dostupan dok postoje greške. Ispravite CSV i ponovo ga učitajte.</p>
                    <?php endif; ?>

                    <form method="post" action="<?= escapeHtml($baseUrl) ?>/contact-import.php">
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="import_token" value="<?= escapeHtml($reviewToken) ?>">
                        <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                        <div class="contact-import-actions"><button class="button button-secondary" type="submit">Otkaži pregled</button></div>
                    </form>
                </section>
            <?php endif; ?>

            <section class="contact-import-panel" aria-labelledby="contact-import-upload-title">
                <div>
                    <h2 id="contact-import-upload-title"><?= $review === null ? 'Učitaj CSV fajl' : 'Učitaj ispravljen ili drugi CSV' ?></h2>
                    <p class="contact-import-note">Ograničenje je 2 MiB i najviše 1000 kontakata. Telefon se čuva kao tekst, uključujući početnu nulu i znak +.</p>
                </div>
                <p><a href="<?= escapeHtml($baseUrl) ?>/contact-import-template.php">Preuzmi CSV šablon</a></p>
                <form class="contact-import-upload-form" method="post" action="<?= escapeHtml($baseUrl) ?>/contact-import.php" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload">
                    <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                    <div class="field-group">
                        <label for="contact-import-file">CSV fajl</label>
                        <input id="contact-import-file" name="csv_file" type="file" accept=".csv,text/csv" required>
                    </div>
                    <div class="field-group">
                        <label for="contact-import-separator">Separator</label>
                        <select id="contact-import-separator" name="separator">
                            <option value=",">Zarez (,)</option>
                            <option value=";">Tačka-zarez (;)</option>
                        </select>
                    </div>
                    <div class="contact-import-actions"><button class="button button-primary" type="submit">Učitaj i pregledaj</button></div>
                </form>
            </section>

            <section class="contact-import-panel">
                <h2>CSV format</h2>
                <p class="contact-import-note">Obavezno zaglavlje: <code>first_name,last_name,phone,email,city</code>. Redosled kolona može biti drugačiji. UTF-8 sa ili bez BOM-a je podržan; tagovi i komentari se ne uvoze.</p>
                <p class="contact-import-note">Ako uređujete CSV u Excelu, uvoz/izvoz može ukloniti početne nule telefona. Pre unosa formatirajte kolonu Telefon kao tekst ili uvezite CSV kroz opciju za tekstualne podatke i postavite tu kolonu na „Text“.</p>
            </section>
        </main>
    </div>
</body>
</html>
