<?php

declare(strict_types=1);

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
