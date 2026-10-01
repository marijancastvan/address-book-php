<?php

declare(strict_types=1);

function contactFormValues(array $source): array
{
    $values = [];

    foreach (['first_name', 'last_name', 'phone', 'email', 'city_id'] as $field) {
        $value = $source[$field] ?? '';
        $values[$field] = is_scalar($value) ? trim((string) $value) : '';
    }

    return $values;
}

function contactCharacterLength(string $value): int
{
    $count = preg_match_all('/./us', $value);

    return $count === false ? 0 : $count;
}

function validateContactValues(array $values): array
{
    $errors = [];
    $limits = [
        'first_name' => ['required' => 'Ime je obavezno.', 'label' => 'Ime', 'max' => 100],
        'last_name' => ['required' => 'Prezime je obavezno.', 'label' => 'Prezime', 'max' => 100],
        'phone' => ['required' => 'Telefon je obavezan.', 'label' => 'Telefon', 'max' => 50],
        'email' => ['required' => 'Email je obavezan.', 'label' => 'Email', 'max' => 255],
    ];

    foreach ($limits as $field => $rule) {
        $value = $values[$field] ?? '';

        if ($value === '') {
            $errors[$field] = $rule['required'];
        } elseif (preg_match('//u', $value) !== 1) {
            $errors[$field] = $rule['label'] . ' sadrži nevažeći tekst.';
        } elseif (contactCharacterLength($value) > $rule['max']) {
            $errors[$field] = $rule['label'] . ' može imati najviše ' . $rule['max'] . ' karaktera.';
        }
    }

    if (($values['email'] ?? '') !== ''
        && preg_match('//u', $values['email']) === 1
        && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false
    ) {
        $errors['email'] = 'Unesite ispravnu email adresu.';
    }

    $cityValue = $values['city_id'] ?? '';
    $cityId = filter_var($cityValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($cityValue === '') {
        $errors['city_id'] = 'Izaberite grad.';
    } elseif ($cityId === false) {
        $errors['city_id'] = 'Izaberite grad.';
    }

    return $errors;
}

function getCitiesForUser(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare('SELECT id, name FROM cities WHERE user_id = :user_id ORDER BY name');
    $statement->execute(['user_id' => $userId]);

    return $statement->fetchAll();
}

function cityBelongsToUser(PDO $pdo, int $cityId, int $userId): bool
{
    $statement = $pdo->prepare('SELECT id FROM cities WHERE id = :city_id AND user_id = :user_id LIMIT 1');
    $statement->execute(['city_id' => $cityId, 'user_id' => $userId]);

    return $statement->fetch() !== false;
}

function contactSuccessMessage(): ?string
{
    return match ($_GET['success'] ?? '') {
        'created' => 'Kontakt je uspešno dodat.',
        'updated' => 'Kontakt je uspešno izmenjen.',
        'deleted' => 'Kontakt je uspešno obrisan.',
        default => null,
    };
}
