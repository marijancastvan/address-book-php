<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/csrf.php';
require_once dirname(__DIR__) . '/app/contact-helpers.php';
require_once dirname(__DIR__) . '/app/contact-merge.php';

if (!isAuthenticated()) {
    redirectTo('/login.php');
}

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$userId = (int) currentUserId();
$baseUrl = rtrim($config['app']['base_url'], '/');
if (!isset($_SESSION['contact_merge_secret'])
    || !is_string($_SESSION['contact_merge_secret'])
    || preg_match('/\A[a-f0-9]{64}\z/D', $_SESSION['contact_merge_secret']) !== 1
) {
    $_SESSION['contact_merge_secret'] = bin2hex(random_bytes(32));
}
$mergeSessionSecret = $_SESSION['contact_merge_secret'];
$source = $_GET;
$firstInput = $_GET['first_id'] ?? $_POST['first_id'] ?? null;
$secondInput = $_GET['second_id'] ?? $_POST['second_id'] ?? null;
$firstId = filter_var($firstInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$secondId = filter_var($secondInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($firstId === false || $secondId === false || $firstId === $secondId) {
    http_response_code(404);
    exit('Par kontakata nije dostupan.');
}
$firstId = (int) $firstId;
$secondId = (int) $secondId;
$returnContext = contactListReturnContext($source);
unset($returnContext['view']);
$duplicatesPageInput = filter_var($source['duplicates_page'] ?? 1, FILTER_VALIDATE_INT);
$duplicatesPage = is_int($duplicatesPageInput) && $duplicatesPageInput > 0 ? $duplicatesPageInput : 1;
$duplicatesUrl = $baseUrl . '/contact-duplicates.php?' . http_build_query(
    $returnContext + ['duplicates_page' => $duplicatesPage],
);
$mergeUrl = $baseUrl . '/contact-merge.php?' . http_build_query(
    $returnContext + ['duplicates_page' => $duplicatesPage, 'first_id' => $firstId, 'second_id' => $secondId],
);
$errorMessage = null;
$result = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Forma je istekla ili nije validna. Osvežite stranicu i pokušajte ponovo.';
    } else {
        $keepId = filter_var($_POST['keep_contact_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $postedFirstId = filter_var($_POST['first_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $postedSecondId = filter_var($_POST['second_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reviewHash = $_POST['review_hash'] ?? '';
        $fieldSources = $_POST['field_sources'] ?? null;
        if ($keepId === false
            || $postedFirstId !== $firstId
            || $postedSecondId !== $secondId
            || !is_string($reviewHash)
            || preg_match('/\A[a-f0-9]{64}\z/D', $reviewHash) !== 1
            || !is_array($fieldSources)
        ) {
            $result = 'invalid';
        } else {
            try {
                $result = mergeDuplicateContacts(
                    db(),
                    $userId,
                    $firstId,
                    $secondId,
                    (int) $keepId,
                    $reviewHash,
                    $mergeSessionSecret,
                    $fieldSources,
                );
                if ($result === 'merged') {
                    redirectTo('/contact-duplicates.php?' . http_build_query(
                        $returnContext + ['duplicates_page' => $duplicatesPage, 'success' => 'merged'],
                    ));
                }
            } catch (Throwable $exception) {
                error_log('Contact merge transaction failed.');
                $result = 'failed';
            }
        }
        $errorMessage = match ($result) {
            'stale' => 'Podaci kontakata ili tagova promenjeni su nakon pregleda. Proverite osveženi prikaz pa ponovo potvrdite spajanje.',
            'not_duplicates' => 'Ovi kontakti više nemaju isto normalizovano polje. Spajanje nije izvršeno.',
            'not_available' => 'Jedan ili oba kontakta više nisu aktivni ili nisu dostupni na vašem nalogu.',
            'invalid_selection' => 'Izbor vrednosti nije validan. Izaberite vrednosti prikazane za ova dva kontakta.',
            'failed' => 'Spajanje trenutno nije uspelo. Nijedna promena nije sačuvana; pokušajte ponovo.',
            default => 'Zahtev za spajanje nije validan.',
        };
    }
}

$snapshots = loadContactMergeSnapshots(db(), $userId, [$firstId, $secondId]);
if ($snapshots === null) {
    http_response_code(404);
}
$isDuplicatePair = $snapshots !== null && contactMergeHasDuplicate($snapshots[$firstId], $snapshots[$secondId]);
if ($snapshots !== null && !$isDuplicatePair && $errorMessage === null) {
    $errorMessage = 'Ovi kontakti više nemaju isto normalizovano polje. Spajanje nije izvršeno.';
}
$fieldLabels = ['first_name' => 'Ime', 'last_name' => 'Prezime', 'phone' => 'Telefon', 'email' => 'E-mail', 'city_id' => 'Grad'];
$defaultKeepId = $firstId;
$defaultSources = array_fill_keys(array_keys($fieldLabels), $defaultKeepId);
$activeNavigation = 'contacts';
?>
<!doctype html>
<html lang="sr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Spoji kontakte | Address Book</title>
    <link rel="stylesheet" href="<?= escapeHtml($baseUrl) ?>/assets/css/app.css">
    <link rel="stylesheet" href="<?= escapeHtml($baseUrl) ?>/assets/css/contacts.css">
    <script src="<?= escapeHtml($baseUrl) ?>/assets/js/contact-merge.js" defer></script>
</head>
<body data-app-base="<?= escapeHtml($baseUrl) ?>">
<div class="app-shell">
    <aside class="sidebar">
        <a class="brand" href="<?= escapeHtml($baseUrl) ?>/dashboard.php">Address Book</a>
        <?php require dirname(__DIR__) . '/app/views/main-navigation.php'; ?>
        <form action="<?= escapeHtml($baseUrl) ?>/logout.php" method="post" class="logout-form">
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <button class="logout-button" type="submit">Odjava</button>
        </form>
    </aside>
    <main class="main-content contact-duplicates-page contact-merge-page">
        <a class="button button-small duplicates-back-link" href="<?= escapeHtml($duplicatesUrl) ?>">← Duplikati</a>
        <header class="page-header">
            <div><p class="eyebrow">ADRESAR</p><h1>Spoji duplirane kontakte</h1></div>
        </header>
        <?php if ($errorMessage !== null): ?><p class="contact-merge-error" role="alert"><?= escapeHtml($errorMessage) ?></p><?php endif; ?>
        <?php if ($snapshots === null): ?>
            <section class="empty-state"><h2>Par kontakata nije dostupan</h2><p>Kontakti možda više nisu aktivni ili ne pripadaju vašem nalogu.</p></section>
        <?php elseif (!$isDuplicatePair): ?>
            <section class="empty-state"><h2>Kontakti se više ne poklapaju</h2><p>Vratite se na pregled duplikata i ponovo izaberite par.</p></section>
        <?php else: ?>
            <?php $contactTitles = []; foreach ($snapshots as $contact) { $contactTitles[$contact['id']] = $contact['first_name'] . ' ' . $contact['last_name'] . ' (ID ' . $contact['id'] . ')'; } ?>
            <p class="duplicates-explanation">Izaberite kontakt koji ostaje aktivan, a zatim izaberite konačnu vrednost svakog polja. Kontakt koji ne izaberete prelazi u „Obrisani kontakti“ i može kasnije biti vraćen kao zaseban kontakt; vraćanje ne poništava ovo spajanje i može ponovo napraviti duplikat. Spaja se samo ovaj par.</p>
            <form class="contact-merge-form" action="<?= escapeHtml($mergeUrl) ?>" method="post">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                <input type="hidden" name="first_id" value="<?= $firstId ?>">
                <input type="hidden" name="second_id" value="<?= $secondId ?>">
                <input type="hidden" name="review_hash" value="<?= escapeHtml(contactMergeSnapshotHash($snapshots, $mergeSessionSecret)) ?>">
                <section class="merge-keep-panel">
                    <label for="keep-contact-id">Kontakt koji ostaje aktivan</label>
                    <select id="keep-contact-id" name="keep_contact_id">
                        <?php foreach ([$firstId, $secondId] as $contactId): ?><option value="<?= $contactId ?>"><?= escapeHtml($contactTitles[$contactId]) ?></option><?php endforeach; ?>
                    </select>
                    <p>Promena ovog izbora ne menja automatski vrednosti ispod. Konačan izbor je prikazan u pregledu.</p>
                    <button class="button button-small merge-use-kept-values" type="button" data-use-kept-values>Postavi sva polja na kontakt koji ostaje</button>
                </section>
                <section class="merge-fields-panel" aria-labelledby="merge-fields-title">
                    <h2 id="merge-fields-title">Izaberite konačne vrednosti</h2>
                    <?php foreach ($fieldLabels as $field => $label): ?>
                        <label class="merge-field-row" for="source-<?= escapeHtml($field) ?>">
                            <span><?= escapeHtml($label) ?></span>
                            <select id="source-<?= escapeHtml($field) ?>" name="field_sources[<?= escapeHtml($field) ?>]" data-merge-field="<?= escapeHtml($field) ?>">
                                <?php foreach ([$firstId, $secondId] as $contactId): $value = $field === 'city_id' ? $snapshots[$contactId]['city']['name'] . ' (ID ' . $snapshots[$contactId]['city']['id'] . ')' : $snapshots[$contactId][$field]; ?>
                                    <option value="<?= $contactId ?>" data-final-value="<?= escapeHtml($value) ?>"><?= escapeHtml($contactTitles[$contactId]) ?> — <?= escapeHtml($value) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endforeach; ?>
                </section>
                <section class="merge-preview-panel" aria-live="polite" aria-labelledby="merge-preview-title">
                    <h2 id="merge-preview-title">Konačan rezultat</h2>
                    <p class="merge-preview-kept">Ostaje: <strong data-merge-kept><?= escapeHtml($contactTitles[$defaultKeepId]) ?></strong>. ID i datum kreiranja ovog kontakta ostaju nepromenjeni.</p>
                    <dl>
                        <?php foreach ($fieldLabels as $field => $label): $value = $field === 'city_id' ? $snapshots[$defaultKeepId]['city']['name'] . ' (ID ' . $snapshots[$defaultKeepId]['city']['id'] . ')' : $snapshots[$defaultKeepId][$field]; ?>
                            <div><dt><?= escapeHtml($label) ?></dt><dd data-merge-preview="<?= escapeHtml($field) ?>"><?= escapeHtml($value) ?></dd></div>
                        <?php endforeach; ?>
                    </dl>
                    <div><strong>Tagovi koji se zadržavaju</strong><ul><?php $combinedTags = []; foreach ($snapshots as $contact) foreach ($contact['tags'] as $tag) $combinedTags[$tag['id']] = $tag; uasort($combinedTags, static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name'])); ?>
                        <?php if ($combinedTags === []): ?><li>Bez tagova</li><?php else: foreach ($combinedTags as $tag): ?><li><?= escapeHtml($tag['name']) ?></li><?php endforeach; endif; ?>
                    </ul></div>
                </section>
                <p class="merge-soft-delete-note">Drugi kontakt biće soft obrisan: njegova istorija ostaje dostupna, a kontakt možete kasnije vratiti zasebno.</p>
                <div class="merge-form-actions">
                    <a class="button button-secondary" href="<?= escapeHtml($duplicatesUrl) ?>">Otkaži</a>
                    <button class="button button-primary" type="submit">Potvrdi spajanje</button>
                </div>
            </form>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
