<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/csrf.php';

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
    $statement = $pdo->prepare('SELECT id, first_name, last_name FROM contacts WHERE id = :id AND user_id = :user_id');
    $statement->execute(['id' => $contactId, 'user_id' => $userId]);
    $contact = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
}
if ($contact === null) {
    http_response_code(404);
}

$historyPageInput = filter_var($_GET['history_page'] ?? 1, FILTER_VALIDATE_INT);
$historyPage = is_int($historyPageInput) && $historyPageInput > 0 ? $historyPageInput : 1;
$pageSize = 25;
$events = [];
$totalPages = 1;
if ($contact !== null) {
    $count = $pdo->prepare('SELECT COUNT(*) FROM contact_history_events WHERE user_id = :user_id AND contact_id = :contact_id');
    $count->execute(['user_id' => $userId, 'contact_id' => $contactId]);
    $total = (int) $count->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $pageSize));
    $historyPage = min($historyPage, $totalPages);
    $query = $pdo->prepare('SELECT id, actor_user_id, actor_email_snapshot, action, occurred_at FROM contact_history_events WHERE user_id = :user_id AND contact_id = :contact_id ORDER BY occurred_at DESC, id DESC LIMIT :limit OFFSET :offset');
    $query->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $query->bindValue(':contact_id', (int) $contactId, PDO::PARAM_INT);
    $query->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $query->bindValue(':offset', ($historyPage - 1) * $pageSize, PDO::PARAM_INT);
    $query->execute();
    $events = $query->fetchAll(PDO::FETCH_ASSOC);
    if ($events !== []) {
        $ids = array_map(static fn (array $event): int => (int) $event['id'], $events);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $changesQuery = $pdo->prepare("SELECT event_id, field_label, old_value, new_value FROM contact_history_changes WHERE user_id = ? AND event_id IN ($marks) ORDER BY id");
        $changesQuery->execute(array_merge([$userId], $ids));
        $changes = [];
        foreach ($changesQuery->fetchAll(PDO::FETCH_ASSOC) as $change) $changes[(int) $change['event_id']][] = $change;
        foreach ($events as &$event) $event['changes'] = $changes[(int) $event['id']] ?? [];
        unset($event);
    }
}
$baseUrl = rtrim($config['app']['base_url'], '/');
$activeNavigation = 'contacts';
$context = [];
foreach (['search', 'city_id', 'tag_id', 'date_from', 'date_to'] as $key) {
    if (isset($_GET[$key]) && is_scalar($_GET[$key]) && (string) $_GET[$key] !== '') $context[$key] = (string) $_GET[$key];
}
$sourcePage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$sourcePage = is_int($sourcePage) && $sourcePage > 0 ? $sourcePage : 1;
$backUrl = $baseUrl . '/contacts.php?' . http_build_query($context + ['page' => $sourcePage]);
$utcTimezone = new DateTimeZone('UTC');
$historyTimezone = new DateTimeZone('Europe/Belgrade');
$actionLabels = ['contact_updated' => 'Izmena kontakta', 'tag_renamed' => 'Preimenovanje taga', 'tag_deleted' => 'Brisanje taga', 'city_renamed' => 'Preimenovanje grada'];
$formatValue = static function (string $value): string {
    $decoded = json_decode($value, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        if (array_is_list($decoded)) {
            if ($decoded === []) return 'Bez tagova';
            return implode(', ', array_map(static fn ($item): string => is_array($item)
                ? (string) ($item['name'] ?? '') . (isset($item['id']) ? ' (ID ' . (int) $item['id'] . ')' : '')
                : (string) $item, $decoded));
        }
        return trim((string) ($decoded['name'] ?? '') . (isset($decoded['id']) ? ' (ID ' . (int) $decoded['id'] . ')' : ''));
    }
    return $value === '' ? 'Prazno' : $value;
};
?>
<!doctype html>
<html lang="sr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Istorija kontakta | Address Book</title>
<link rel="stylesheet" href="<?= escapeHtml($baseUrl) ?>/assets/css/app.css"><link rel="stylesheet" href="<?= escapeHtml($baseUrl) ?>/assets/css/contact-history.css"></head>
<body data-app-base="<?= escapeHtml($baseUrl) ?>"><div class="app-shell"><aside class="sidebar">
<a class="brand" href="<?= escapeHtml($baseUrl) ?>/dashboard.php">Address Book</a><?php require dirname(__DIR__) . '/app/views/main-navigation.php'; ?>
<form action="<?= escapeHtml($baseUrl) ?>/logout.php" method="post" class="logout-form"><input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>"><button class="logout-button" type="submit">Odjava</button></form></aside>
<main class="main-content contact-history-page"><a class="button button-small history-back-link" href="<?= escapeHtml($backUrl) ?>">← Kontakti</a>
<?php if ($contact === null): ?><section class="empty-state"><h1>Kontakt nije pronađen</h1><p>Kontakt ne postoji ili nemate dozvolu za pristup.</p></section>
<?php else: ?><header class="history-title-panel"><p class="eyebrow">ISTORIJA PROMENA</p><h1><?= escapeHtml($contact['first_name'] . ' ' . $contact['last_name']) ?></h1></header>
<?php if ($events === []): ?><section class="empty-state history-empty-state"><p>Za ovaj kontakt još nema zabeleženih izmena.</p></section>
<?php else: ?><ol class="history-list">
<?php foreach ($events as $event): $storedEventTime = (string) $event['occurred_at']; $parsedEventTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $storedEventTime, $utcTimezone); $eventTime = $parsedEventTime instanceof DateTimeImmutable ? $parsedEventTime->setTimezone($historyTimezone) : null; ?><li class="history-event"><header><h2><?= escapeHtml($actionLabels[$event['action']] ?? 'Promena kontakta') ?></h2><p class="history-event-meta"><span><strong>Mail:</strong> <?= escapeHtml($event['actor_email_snapshot']) ?></span><time datetime="<?= escapeHtml($eventTime?->format(DATE_ATOM) ?? $storedEventTime) ?>"><strong>Time:</strong> <?= escapeHtml($eventTime?->format('H:i d-m-Y') ?? $storedEventTime) ?></time></p></header>
<dl><?php foreach ($event['changes'] as $change): ?><div><dt><?= escapeHtml($change['field_label']) ?></dt><dd><span class="history-old-value"><?= escapeHtml($formatValue($change['old_value'])) ?></span><span class="history-change-arrow" aria-hidden="true"> → </span><strong class="history-new-value"><?= escapeHtml($formatValue($change['new_value'])) ?></strong></dd></div><?php endforeach; ?></dl></li><?php endforeach; ?></ol>
<?php if ($totalPages > 1): ?><nav class="history-pagination" aria-label="Stranice istorije">
<?php for ($p = 1; $p <= $totalPages; $p++): $params = $context + ['page' => $sourcePage, 'id' => (int) $contactId, 'history_page' => $p]; ?><a class="pagination-link <?= $p === $historyPage ? 'is-current' : '' ?>" href="?<?= escapeHtml(http_build_query($params)) ?>" <?= $p === $historyPage ? 'aria-current="page"' : '' ?>><?= $p ?></a><?php endfor; ?>
</nav><?php endif; ?><?php endif; ?><?php endif; ?></main></div></body></html>
