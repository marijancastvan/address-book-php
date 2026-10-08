<?php

declare(strict_types=1);

require_once __DIR__ . '/history-helpers.php';

function renameCityForUser(PDO $pdo, int $userId, int $cityId, string $name): bool
{
    $pdo->beginTransaction();
    try {
        $actorEmail = lockUserForHistoryMutation($pdo, $userId);
        $cityStatement = $pdo->prepare('SELECT id, name FROM cities WHERE id = :city_id AND user_id = :user_id FOR UPDATE');
        $cityStatement->execute(['city_id' => $cityId, 'user_id' => $userId]);
        $city = $cityStatement->fetch(PDO::FETCH_ASSOC);
        if ($city === false) { $pdo->rollBack(); return false; }
        if ((string) $city['name'] === $name) { $pdo->commit(); return true; }
        $contactStatement = $pdo->prepare('SELECT id FROM contacts WHERE user_id = :user_id AND city_id = :city_id ORDER BY id FOR UPDATE');
        $contactStatement->execute(['user_id' => $userId, 'city_id' => $cityId]);
        $contactIds = array_map('intval', $contactStatement->fetchAll(PDO::FETCH_COLUMN));
        $update = $pdo->prepare('UPDATE cities SET name = :name WHERE id = :city_id AND user_id = :user_id');
        $update->execute(['name' => $name, 'city_id' => $cityId, 'user_id' => $userId]);
        foreach ($contactIds as $contactId) {
            recordContactHistoryEvent($pdo, $userId, $contactId, $userId, $actorEmail, 'city_renamed', ['city' => ['label' => 'Grad', 'old' => ['id' => $cityId, 'name' => (string) $city['name']], 'new' => ['id' => $cityId, 'name' => $name]]]);
        }
        $pdo->commit();
        return true;
    } catch (Throwable $exception) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $exception; }
}

function deleteCityForUser(PDO $pdo, int $userId, int $cityId): bool
{
    $pdo->beginTransaction();
    try {
        lockUserForHistoryMutation($pdo, $userId);
        $delete = $pdo->prepare('DELETE FROM cities WHERE id = :city_id AND user_id = :user_id');
        $delete->execute(['city_id' => $cityId, 'user_id' => $userId]);
        $deleted = $delete->rowCount() === 1;
        $pdo->commit();
        return $deleted;
    } catch (Throwable $exception) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $exception; }
}

function cityNameCharacterLength(string $name): int
{
    $count = preg_match_all('/./us', $name);

    return $count === false ? 0 : $count;
}

function validateCityName(string $name): array
{
    if ($name === '') {
        return ['name' => 'Naziv grada je obavezan.'];
    }
    if (preg_match('//u', $name) !== 1) {
        return ['name' => 'Naziv grada sadrži nevažeći tekst.'];
    }
    if (cityNameCharacterLength($name) > 255) {
        return ['name' => 'Naziv grada može imati najviše 255 karaktera.'];
    }

    return [];
}

function cityNameExistsForUser(PDO $pdo, int $userId, string $name, ?int $exceptCityId = null): bool
{
    if ($exceptCityId === null) {
        $statement = $pdo->prepare('SELECT id FROM cities WHERE user_id = :user_id AND name = :name LIMIT 1');
        $statement->execute(['user_id' => $userId, 'name' => $name]);
    } else {
        $statement = $pdo->prepare(
            'SELECT id FROM cities WHERE user_id = :user_id AND name = :name AND id <> :city_id LIMIT 1'
        );
        $statement->execute(['user_id' => $userId, 'name' => $name, 'city_id' => $exceptCityId]);
    }

    return $statement->fetch() !== false;
}

function isDuplicateCityError(PDOException $exception): bool
{
    return (int) ($exception->errorInfo[1] ?? 0) === 1062;
}

function citySuccessMessage(): ?string
{
    return match ($_GET['success'] ?? '') {
        'created' => 'Grad je uspešno dodat.',
        'updated' => 'Grad je uspešno izmenjen.',
        'deleted' => 'Grad je uspešno obrisan.',
        default => null,
    };
}
