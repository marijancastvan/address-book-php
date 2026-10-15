<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/contact-helpers.php';

if (!isAuthenticated()) {
    redirectTo('/login.php');
}

$userId = (int) currentUserId();
$baseUrl = rtrim($config['app']['base_url'], '/');
$returnContext = contactListReturnContext($_GET);
unset($returnContext['view']);
$returnUrl = $baseUrl . '/contacts.php?' . http_build_query($returnContext);
$pageInput = filter_var($_GET['duplicates_page'] ?? 1, FILTER_VALIDATE_INT);
$page = is_int($pageInput) && $pageInput > 0 ? $pageInput : 1;
$pageSize = 25;
$where = 'a.user_id = :a_user_id
          AND b.user_id = :b_user_id
          AND a.deleted_at IS NULL
          AND b.deleted_at IS NULL
          AND b.id > a.id
          AND ((a.phone_normalized IS NOT NULL AND a.phone_normalized = b.phone_normalized)
               OR (a.email_normalized IS NOT NULL AND a.email_normalized = b.email_normalized))';

$countQuery = db()->prepare(
    'SELECT COUNT(*)
     FROM contacts a
     INNER JOIN contacts b ON ' . $where
);
$countQuery->execute(['a_user_id' => $userId, 'b_user_id' => $userId]);
$totalPairs = (int) $countQuery->fetchColumn();
$totalPages = max(1, (int) ceil($totalPairs / $pageSize));
$page = min($page, $totalPages);

$pairQuery = db()->prepare(
    'SELECT a.id AS a_id, a.first_name AS a_first_name, a.last_name AS a_last_name,
            a.phone AS a_phone, a.email AS a_email,
            a.phone_normalized AS a_phone_normalized, a.email_normalized AS a_email_normalized,
            b.id AS b_id, b.first_name AS b_first_name, b.last_name AS b_last_name,
            b.phone AS b_phone, b.email AS b_email,
            b.phone_normalized AS b_phone_normalized, b.email_normalized AS b_email_normalized
     FROM contacts a
     INNER JOIN contacts b ON ' . $where . '
     ORDER BY a.first_name ASC, a.last_name ASC, a.id ASC,
              b.first_name ASC, b.last_name ASC, b.id ASC
     LIMIT :limit OFFSET :offset'
);
$pairQuery->bindValue(':a_user_id', $userId, PDO::PARAM_INT);
$pairQuery->bindValue(':b_user_id', $userId, PDO::PARAM_INT);
$pairQuery->bindValue(':limit', $pageSize, PDO::PARAM_INT);
$pairQuery->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
$pairQuery->execute();
$pairs = [];
foreach ($pairQuery->fetchAll(PDO::FETCH_ASSOC) as $pair) {
    $samePhone = $pair['a_phone_normalized'] !== null
        && $pair['a_phone_normalized'] === $pair['b_phone_normalized'];
    $sameEmail = $pair['a_email_normalized'] !== null
        && $pair['a_email_normalized'] === $pair['b_email_normalized'];
    $pair['reason'] = $samePhone && $sameEmail ? 'Telefon i email' : ($samePhone ? 'Telefon' : 'Email');
    $pairs[] = $pair;
}

$pageUrl = static function (int $targetPage) use ($baseUrl, $returnContext): string {
    return $baseUrl . '/contact-duplicates.php?' . http_build_query(
        $returnContext + ['duplicates_page' => $targetPage],
    );
};
$activeNavigation = 'contacts';
?>
<!doctype html>
<html lang="sr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Duplikati kontakata | Address Book</title>
    <link rel="stylesheet" href="<?= escapeHtml($baseUrl) ?>/assets/css/app.css">
    <link rel="stylesheet" href="<?= escapeHtml($baseUrl) ?>/assets/css/contacts.css">
</head>
<body data-app-base="<?= escapeHtml($baseUrl) ?>">
    <div class="app-shell">
        <aside class="sidebar">
            <a class="brand" href="<?= escapeHtml($baseUrl) ?>/dashboard.php">Address Book</a>
            <?php require dirname(__DIR__) . '/app/views/main-navigation.php'; ?>
            <form action="<?= escapeHtml($baseUrl) ?>/logout.php" method="post" class="logout-form">
                <button class="logout-button" type="submit">Odjava</button>
            </form>
        </aside>
        <main class="main-content contact-duplicates-page">
            <a class="button button-small duplicates-back-link" href="<?= escapeHtml($returnUrl) ?>">← Kontakti</a>
            <header class="page-header">
                <div><p class="eyebrow">ADRESAR</p><h1>Duplikati kontakata</h1></div>
            </header>
            <p class="duplicates-explanation">Prikazani su parovi aktivnih kontakata istog naloga koji dele potpuno isti normalizovani telefon ili email. Svaki par je nezavisan: ako A deli telefon sa B, a B email sa C, prikazuju se parovi A–B i B–C sa svojim zasebnim razlozima.</p>
            <?php if ($pairs === []): ?>
                <section class="empty-state"><h2>Nema pronađenih duplikata</h2><p>Aktivni kontakti trenutno nemaju poklapanja po telefonu ili emailu.</p></section>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="contact-duplicates-table">
                        <thead><tr><th scope="col">Kontakt 1</th><th scope="col">Poklapanje</th><th scope="col">Kontakt 2</th></tr></thead>
                        <tbody>
                        <?php foreach ($pairs as $pair): ?>
                            <tr>
                                <td data-label="Kontakt 1"><strong><?= escapeHtml($pair['a_first_name'] . ' ' . $pair['a_last_name']) ?></strong><span><?= escapeHtml($pair['a_phone']) ?></span><span><?= escapeHtml($pair['a_email']) ?></span></td>
                                <td data-label="Poklapanje"><span class="duplicate-reason"><?= escapeHtml($pair['reason']) ?></span></td>
                                <td data-label="Kontakt 2"><strong><?= escapeHtml($pair['b_first_name'] . ' ' . $pair['b_last_name']) ?></strong><span><?= escapeHtml($pair['b_phone']) ?></span><span><?= escapeHtml($pair['b_email']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalPages > 1): ?>
                    <nav class="contacts-pagination" aria-label="Paginacija duplikata">
                        <?php if ($page > 1): ?><a class="pagination-link" href="<?= escapeHtml($pageUrl($page - 1)) ?>" rel="prev">Prethodna</a><?php else: ?><span class="pagination-link is-disabled" aria-disabled="true">Prethodna</span><?php endif; ?>
                        <span class="pagination-link is-current" aria-current="page"><?= $page ?> / <?= $totalPages ?></span>
                        <?php if ($page < $totalPages): ?><a class="pagination-link" href="<?= escapeHtml($pageUrl($page + 1)) ?>" rel="next">Sledeća</a><?php else: ?><span class="pagination-link is-disabled" aria-disabled="true">Sledeća</span><?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
