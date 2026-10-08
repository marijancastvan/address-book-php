<?php

declare(strict_types=1);

require_once __DIR__ . '/history-helpers.php';

function normalizeTagName(string $name): ?string
{
    if (preg_match('//u', $name) !== 1) {
        return null;
    }

    $normalized = preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $name);

    return is_string($normalized) ? $normalized : null;
}

function tagNameCharacterLength(string $name): int
{
    $length = preg_match_all('/./us', $name);

    return $length === false ? 0 : $length;
}

function validateTagName(string $name): array
{
    if (preg_match('//u', $name) !== 1) {
        return ['name' => 'Naziv taga sadrži nevažeći tekst.'];
    }
    if ($name === '') {
        return ['name' => 'Naziv taga je obavezan.'];
    }
    if (tagNameCharacterLength($name) > 150) {
        return ['name' => 'Naziv taga može imati najviše 150 Unicode znakova.'];
    }

    return [];
}

function tagNameExistsForUser(PDO $pdo, int $userId, string $name, ?int $exceptTagId = null): bool
{
    if ($exceptTagId === null) {
        $statement = $pdo->prepare(
            'SELECT id FROM tags WHERE user_id = :user_id AND name = :name LIMIT 1'
        );
        $statement->execute(['user_id' => $userId, 'name' => $name]);
    } else {
        $statement = $pdo->prepare(
            'SELECT id FROM tags WHERE user_id = :user_id AND name = :name AND id <> :tag_id LIMIT 1'
        );
        $statement->execute(['user_id' => $userId, 'name' => $name, 'tag_id' => $exceptTagId]);
    }

    return $statement->fetch() !== false;
}

function createTagForUser(PDO $pdo, int $userId, string $name): int
{
    $statement = $pdo->prepare('INSERT INTO tags (user_id, name) VALUES (:user_id, :name)');
    $statement->execute(['user_id' => $userId, 'name' => $name]);

    return (int) $pdo->lastInsertId();
}

function renameTagForUser(PDO $pdo, int $userId, int $tagId, string $name): bool
{
    $pdo->beginTransaction();
    try {
        $actorEmail = lockUserForHistoryMutation($pdo, $userId);
        $tagStatement = $pdo->prepare('SELECT id, name FROM tags WHERE id = :tag_id AND user_id = :user_id FOR UPDATE');
        $tagStatement->execute(['tag_id' => $tagId, 'user_id' => $userId]);
        $tag = $tagStatement->fetch(PDO::FETCH_ASSOC);
        if ($tag === false) { $pdo->rollBack(); return false; }
        if ((string) $tag['name'] === $name) { $pdo->commit(); return true; }
        $contactsStatement = $pdo->prepare('SELECT c.id FROM contacts c JOIN contact_tags ct ON ct.contact_id = c.id AND ct.user_id = c.user_id WHERE c.user_id = :user_id AND ct.tag_id = :tag_id ORDER BY c.id FOR UPDATE');
        $contactsStatement->execute(['user_id' => $userId, 'tag_id' => $tagId]);
        $contactIds = array_map('intval', $contactsStatement->fetchAll(PDO::FETCH_COLUMN));
        $oldTags = getContactTagSnapshotsByContactIds($pdo, $userId, $contactIds);
        $update = $pdo->prepare('UPDATE tags SET name = :name WHERE id = :tag_id AND user_id = :user_id');
        $update->execute(['name' => $name, 'tag_id' => $tagId, 'user_id' => $userId]);
        $newTags = getContactTagSnapshotsByContactIds($pdo, $userId, $contactIds);
        foreach ($contactIds as $contactId) {
            recordContactHistoryEvent($pdo, $userId, $contactId, $userId, $actorEmail, 'tag_renamed', ['tags' => ['label' => 'Tagovi', 'old' => $oldTags[$contactId] ?? [], 'new' => $newTags[$contactId] ?? []]]);
        }
        $pdo->commit();
        return true;
    } catch (Throwable $exception) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $exception; }
}

function deleteTagForUser(PDO $pdo, int $userId, int $tagId): bool
{
    $pdo->beginTransaction();
    try {
        $actorEmail = lockUserForHistoryMutation($pdo, $userId);
        $tagStatement = $pdo->prepare('SELECT id FROM tags WHERE id = :tag_id AND user_id = :user_id FOR UPDATE');
        $tagStatement->execute(['tag_id' => $tagId, 'user_id' => $userId]);
        if ($tagStatement->fetchColumn() === false) { $pdo->rollBack(); return false; }
        $contactsStatement = $pdo->prepare('SELECT c.id FROM contacts c JOIN contact_tags ct ON ct.contact_id = c.id AND ct.user_id = c.user_id WHERE c.user_id = :user_id AND ct.tag_id = :tag_id ORDER BY c.id FOR UPDATE');
        $contactsStatement->execute(['user_id' => $userId, 'tag_id' => $tagId]);
        $contactIds = array_map('intval', $contactsStatement->fetchAll(PDO::FETCH_COLUMN));
        $oldTags = getContactTagSnapshotsByContactIds($pdo, $userId, $contactIds);
        $delete = $pdo->prepare('DELETE FROM tags WHERE id = :tag_id AND user_id = :user_id');
        $delete->execute(['tag_id' => $tagId, 'user_id' => $userId]);
        $newTags = getContactTagSnapshotsByContactIds($pdo, $userId, $contactIds);
        foreach ($contactIds as $contactId) recordContactHistoryEvent($pdo, $userId, $contactId, $userId, $actorEmail, 'tag_deleted', ['tags' => ['label' => 'Tagovi', 'old' => $oldTags[$contactId] ?? [], 'new' => $newTags[$contactId] ?? []]]);
        $pdo->commit();
        return true;
    } catch (Throwable $exception) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $exception; }
}

function isDuplicateTagError(PDOException $exception): bool
{
    return (int) ($exception->errorInfo[1] ?? 0) === 1062;
}

function tagSuccessMessage(): ?string
{
    return match ($_GET['success'] ?? '') {
        'created' => 'Tag je uspešno dodat.',
        'updated' => 'Tag je uspešno izmenjen.',
        'deleted' => 'Tag je uspešno obrisan. Njegove veze sa kontaktima su uklonjene, a kontakti su sačuvani.',
        default => null,
    };
}
