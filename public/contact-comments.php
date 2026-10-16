<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/csrf.php';
require_once dirname(__DIR__) . '/app/contact-helpers.php';

if (!isAuthenticated()) {
    redirectTo('/login.php');
}
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$userId = (int) currentUserId();
$contactId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$pdo = db();
$contact = null;
if ($contactId !== false && $contactId !== null) {
    $query = $pdo->prepare(
        'SELECT id, first_name, last_name, deleted_at FROM contacts WHERE id = :id AND user_id = :user_id'
    );
    $query->execute(['id' => $contactId, 'user_id' => $userId]);
    $contact = $query->fetch(PDO::FETCH_ASSOC) ?: null;
}
if ($contact === null) {
    http_response_code(404);
}

$context = contactListReturnContext($_GET);
if (($_GET['view'] ?? '') === 'deleted') {
    $context['view'] = 'deleted';
}
$backUrl = rtrim($config['app']['base_url'], '/') . '/contacts.php?' . http_build_query($context);
$pageSize = 25;
$commentsPageInput = filter_var($_GET['comments_page'] ?? 1, FILTER_VALIDATE_INT);
$commentsPage = is_int($commentsPageInput) && $commentsPageInput > 0 ? $commentsPageInput : 1;
$comments = [];
$totalPages = 1;
$totalComments = 0;
if ($contact !== null) {
    $count = $pdo->prepare(
        'SELECT COUNT(*) FROM contact_comments WHERE user_id = :user_id AND contact_id = :contact_id'
    );
    $count->execute(['user_id' => $userId, 'contact_id' => (int) $contactId]);
    $totalComments = (int) $count->fetchColumn();
    $totalPages = max(1, (int) ceil($totalComments / $pageSize));
    $commentsPage = min($commentsPage, $totalPages);
    $list = $pdo->prepare(
        'SELECT id, origin_contact_id, author_email_snapshot, body, created_at
         FROM contact_comments
         WHERE user_id = :user_id AND contact_id = :contact_id
         ORDER BY created_at DESC, id DESC
         LIMIT :limit OFFSET :offset'
    );
    $list->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $list->bindValue(':contact_id', (int) $contactId, PDO::PARAM_INT);
    $list->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $list->bindValue(':offset', ($commentsPage - 1) * $pageSize, PDO::PARAM_INT);
    $list->execute();
    $comments = $list->fetchAll(PDO::FETCH_ASSOC);
}
$flash = $_SESSION['contact_comment_flash'] ?? null;
if (is_array($flash) && (int) ($flash['contact_id'] ?? 0) === (int) $contactId) {
    unset($_SESSION['contact_comment_flash']);
} else {
    $flash = null;
}
$baseUrl = rtrim($config['app']['base_url'], '/');
$activeNavigation = 'contacts';
$utcTimezone = new DateTimeZone('UTC');
$displayTimezone = new DateTimeZone('Europe/Belgrade');
?>
<!doctype html>
<html lang="sr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Komentari kontakta | Address Book</title>
    <link rel="stylesheet" href="<?= escapeHtml($baseUrl) ?>/assets/css/app.css">
    <link rel="stylesheet" href="<?= escapeHtml($baseUrl) ?>/assets/css/contacts.css">
    <link rel="stylesheet" href="<?= escapeHtml($baseUrl) ?>/assets/css/contact-history.css">
    <script src="<?= escapeHtml($baseUrl) ?>/assets/js/contact-comments.js" defer></script>
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
    <main class="main-content contact-comments-page">
        <a class="button button-small history-back-link" href="<?= escapeHtml($backUrl) ?>">← Kontakti</a>
        <?php if ($contact === null): ?>
            <section class="empty-state"><h1>Kontakt nije pronađen</h1><p>Kontakt ne postoji ili nemate dozvolu za pristup.</p></section>
        <?php else: ?>
            <header class="history-title-panel">
                <p class="eyebrow">KOMENTARI</p>
                <h1><?= escapeHtml($contact['first_name'] . ' ' . $contact['last_name']) ?></h1>
            </header>
            <p class="contact-comments-merge-note">Komentari preneti spajanjem ostaju uz kontakt koji je preostao. Vraćanje drugog kontakta ne premešta ih nazad; oznaka uz komentar prikazuje njegov izvorni kontakt.</p>
            <?php if ($contact['deleted_at'] !== null): ?>
                <p class="contact-comments-deleted-notice">Kontakt je u obrisanim kontaktima. Komentari su dostupni za čitanje; dodavanje je omogućeno nakon vraćanja kontakta.</p>
                <?php if (is_array($flash) && is_string($flash['error'] ?? null)): ?><p class="form-error" role="alert"><?= escapeHtml($flash['error']) ?></p><?php endif; ?>
            <?php else: ?>
                <form class="contact-comment-form" action="<?= escapeHtml($baseUrl) ?>/contact-comment-create.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                    <input type="hidden" name="contact_id" value="<?= (int) $contact['id'] ?>">
                    <?php foreach ($context as $key => $value): ?><input type="hidden" name="<?= escapeHtml($key) ?>" value="<?= escapeHtml((string) $value) ?>"><?php endforeach; ?>
                    <label for="comment-body">Novi komentar</label>
                    <textarea id="comment-body" name="body" rows="4" required><?= escapeHtml(is_array($flash) ? (string) ($flash['body'] ?? '') : '') ?></textarea>
                    <small>Najviše 5000 Unicode znakova.</small>
                    <?php if (is_array($flash) && is_string($flash['error'] ?? null)): ?><p class="form-error" role="alert"><?= escapeHtml($flash['error']) ?></p><?php endif; ?>
                    <button class="button button-primary" type="submit">Dodaj komentar</button>
                </form>
            <?php endif; ?>
            <?php if ($comments === []): ?>
                <section class="empty-state history-empty-state"><p>Za ovaj kontakt još nema komentara.</p></section>
            <?php else: ?>
                <ol class="contact-comments-list">
                    <?php foreach ($comments as $comment):
                        $storedTime = (string) $comment['created_at'];
                        $parsedTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $storedTime, $utcTimezone);
                        $displayTime = $parsedTime instanceof DateTimeImmutable ? $parsedTime->setTimezone($displayTimezone) : null;
                    ?>
                        <li class="contact-comment-card">
                            <header><span><strong>Mail:</strong> <?= escapeHtml($comment['author_email_snapshot']) ?></span><time datetime="<?= escapeHtml($displayTime?->format(DATE_ATOM) ?? $storedTime) ?>"><strong>Time:</strong> <?= escapeHtml($displayTime?->format('H:i d-m-Y') ?? $storedTime) ?></time></header>
                            <p><?= nl2br(escapeHtml((string) $comment['body']), false) ?></p>
                            <?php if ((int) $comment['origin_contact_id'] !== (int) $contact['id']): ?><small>Komentar prenet sa kontakta ID <?= (int) $comment['origin_contact_id'] ?></small><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <?php if ($totalPages > 1): ?>
                    <nav class="history-pagination" aria-label="Stranice komentara">
                        <?php for ($page = 1; $page <= $totalPages; $page++): $params = ['id' => (int) $contact['id']] + $context + ['comments_page' => $page]; ?>
                            <a class="pagination-link <?= $page === $commentsPage ? 'is-current' : '' ?>" href="?<?= escapeHtml(http_build_query($params)) ?>" <?= $page === $commentsPage ? 'aria-current="page"' : '' ?>><?= $page ?></a>
                        <?php endfor; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
