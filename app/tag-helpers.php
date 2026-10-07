<?php

declare(strict_types=1);

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

function renameTagForUser(PDO $pdo, int $userId, int $tagId, string $name): void
{
    $statement = $pdo->prepare(
        'UPDATE tags SET name = :name WHERE id = :tag_id AND user_id = :user_id'
    );
    $statement->execute(['name' => $name, 'tag_id' => $tagId, 'user_id' => $userId]);
}

function deleteTagForUser(PDO $pdo, int $userId, int $tagId): bool
{
    $statement = $pdo->prepare('DELETE FROM tags WHERE id = :tag_id AND user_id = :user_id');
    $statement->execute(['tag_id' => $tagId, 'user_id' => $userId]);

    return $statement->rowCount() === 1;
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
