<?php

declare(strict_types=1);

require_once __DIR__ . '/contact-helpers.php';
require_once __DIR__ . '/city-helpers.php';
require_once __DIR__ . '/history-helpers.php';

const CONTACT_IMPORT_MAX_BYTES = 2097152;
const CONTACT_IMPORT_MAX_ROWS = 1000;
const CONTACT_IMPORT_TTL_MINUTES = 30;

function contactImportCountLabel(int $count, string $one, string $few, string $many): string
{
    $lastTwo = $count % 100;
    $last = $count % 10;
    if ($lastTwo >= 11 && $lastTwo <= 14) {
        return $count . ' ' . $many;
    }
    if ($last >= 2 && $last <= 4) {
        return $count . ' ' . $few;
    }
    return $count . ' ' . ($last === 1 ? $one : $many);
}

/** Strictly check quote placement before handing CSV parsing to PHP's CSV parser. */
function contactImportCsvQuotesAreValid(string $contents, string $delimiter): bool
{
    $insideQuotes = false;
    $afterQuote = false;
    $atFieldStart = true;
    $length = strlen($contents);

    for ($index = 0; $index < $length; $index++) {
        $character = $contents[$index];

        if ($insideQuotes) {
            if ($character === '"') {
                if ($index + 1 < $length && $contents[$index + 1] === '"') {
                    $index++;
                } else {
                    $insideQuotes = false;
                    $afterQuote = true;
                }
            }
            continue;
        }

        if ($afterQuote) {
            if ($character === $delimiter) {
                $afterQuote = false;
                $atFieldStart = true;
            } elseif ($character === "\r" || $character === "\n") {
                $afterQuote = false;
                $atFieldStart = true;
            } else {
                return false;
            }
            continue;
        }

        if ($character === '"') {
            if (!$atFieldStart) {
                return false;
            }
            $insideQuotes = true;
            $atFieldStart = false;
        } elseif ($character === $delimiter) {
            $atFieldStart = true;
        } elseif ($character === "\r" || $character === "\n") {
            $atFieldStart = true;
        } else {
            $atFieldStart = false;
        }
    }

    return !$insideQuotes;
}

/** Parse the five-column CSV format using fgetcsv, including multiline fields. */
function parseContactImportCsv(string $contents, string $delimiter): array
{
    if (!in_array($delimiter, [',', ';'], true)) {
        return ['rows' => [], 'error' => 'Izaberite zarez ili tačka-zarez kao separator.'];
    }
    if (strlen($contents) > CONTACT_IMPORT_MAX_BYTES) {
        return ['rows' => [], 'error' => 'CSV fajl ne sme biti veći od 2 MiB.'];
    }
    if ($contents === '' || preg_match('//u', $contents) !== 1 || str_contains($contents, "\0")) {
        return ['rows' => [], 'error' => 'Fajl mora biti neprazan, ispravan UTF-8 CSV bez NUL znakova.'];
    }

    if (str_starts_with($contents, "\xEF\xBB\xBF")) {
        $contents = substr($contents, 3);
    }
    if (!contactImportCsvQuotesAreValid($contents, $delimiter)) {
        return ['rows' => [], 'error' => 'CSV sadrži neispravnu strukturu navodnika. Proverite fajl i pokušajte ponovo.'];
    }

    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) {
        return ['rows' => [], 'error' => 'CSV trenutno nije moguće obraditi. Pokušajte ponovo.'];
    }
    fwrite($stream, $contents);
    rewind($stream);

    $requiredColumns = ['first_name', 'last_name', 'phone', 'email', 'city'];
    $headerIndexes = null;
    $rows = [];
    $csvRecord = 0;
    $parseError = null;

    try {
        while (($fields = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $csvRecord++;
            $fields = array_map(static fn (mixed $value): string => $value === null ? '' : (string) $value, $fields);
            $isBlank = count(array_filter($fields, static fn (string $value): bool => trim($value) !== '')) === 0;
            if ($isBlank) {
                continue;
            }

            if ($headerIndexes === null) {
                if (count($fields) !== count($requiredColumns)) {
                    $parseError = 'Zaglavlje mora sadržati tačno kolone: first_name, last_name, phone, email, city.';
                    break;
                }

                $header = array_map(static fn (string $value): string => strtolower(trim($value)), $fields);
                $duplicates = array_values(array_unique(array_diff_assoc($header, array_unique($header))));
                $unknown = array_values(array_diff($header, $requiredColumns));
                $missing = array_values(array_diff($requiredColumns, $header));
                if ($duplicates !== []) {
                    $parseError = 'Zaglavlje sadrži dupliranu kolonu: ' . implode(', ', $duplicates) . '.';
                } elseif ($unknown !== []) {
                    $parseError = 'Nepoznata kolona u zaglavlju: ' . implode(', ', array_unique($unknown)) . '.';
                } elseif ($missing !== []) {
                    $parseError = 'Nedostaje obavezna kolona: ' . implode(', ', $missing) . '.';
                } else {
                    $headerIndexes = array_flip($header);
                }
                if ($parseError !== null) {
                    break;
                }
                continue;
            }

            if (count($fields) !== count($requiredColumns)) {
                $parseError = 'CSV zapis ' . $csvRecord . ', polje Struktura: očekivano je pet vrednosti, a pronađeno ' . count($fields) . '.';
                break;
            }
            if (count($rows) >= CONTACT_IMPORT_MAX_ROWS) {
                $parseError = 'CSV može sadržati najviše 1000 kontakata. Višak zapisa počinje od CSV zapisa ' . $csvRecord . '.';
                break;
            }

            $values = [];
            foreach ($requiredColumns as $column) {
                $values[$column] = trim($fields[$headerIndexes[$column]]);
            }
            $rows[] = ['record' => $csvRecord, 'values' => $values];
        }
    } finally {
        fclose($stream);
    }

    if ($parseError !== null) {
        return ['rows' => [], 'error' => $parseError];
    }
    if ($headerIndexes === null) {
        return ['rows' => [], 'error' => 'CSV fajl nema zaglavlje sa obaveznim kolonama.'];
    }
    if ($rows === []) {
        return ['rows' => [], 'error' => 'CSV ne sadrži nijedan kontakt za uvoz.'];
    }

    return ['rows' => $rows, 'error' => null];
}

function contactImportCityComparisonKey(PDO $pdo, string $name): string
{
    static $cache = [];
    if (array_key_exists($name, $cache)) {
        return $cache[$name];
    }

    $statement = $pdo->prepare('SELECT LOWER(CONVERT(:name USING utf8mb4)) COLLATE utf8mb4_unicode_ci');
    $statement->execute(['name' => $name]);
    $key = $statement->fetchColumn();

    $cache[$name] = is_string($key) ? $key : strtolower($name);

    return $cache[$name];
}

/** Load active contacts whose stored keys match any key in the CSV. */
function findContactImportDuplicateCandidates(PDO $pdo, int $userId, array $rows, bool $lock): array
{
    $phoneKeys = [];
    $emailKeys = [];
    foreach ($rows as $row) {
        if (is_string($row['phone_normalized'] ?? null)) {
            $phoneKeys[$row['phone_normalized']] = $row['phone_normalized'];
        }
        if (is_string($row['email_normalized'] ?? null)) {
            $emailKeys[$row['email_normalized']] = $row['email_normalized'];
        }
    }

    $candidates = [];
    foreach ([['phone_normalized', array_values($phoneKeys)], ['email_normalized', array_values($emailKeys)]] as [$column, $keys]) {
        foreach (array_chunk($keys, 250) as $chunkIndex => $chunk) {
            if ($chunk === []) {
                continue;
            }
            $parameters = ['user_id' => $userId];
            $placeholders = [];
            foreach ($chunk as $index => $key) {
                $name = 'key_' . $chunkIndex . '_' . $index;
                $placeholders[] = ':' . $name;
                $parameters[$name] = $key;
            }
            $sql = 'SELECT id, phone_normalized, email_normalized FROM contacts
                    WHERE user_id = :user_id AND deleted_at IS NULL AND ' . $column
                . ' IN (' . implode(', ', $placeholders) . ') ORDER BY id';
            $statement = $pdo->prepare($sql);
            $statement->execute($parameters);
            foreach ($statement->fetchAll() as $contact) {
                $candidates[(int) $contact['id']] = $contact;
            }
        }
    }

    if (!$lock || $candidates === []) {
        return array_values($candidates);
    }

    $ids = array_keys($candidates);
    sort($ids, SORT_NUMERIC);
    $locked = [];
    foreach (array_chunk($ids, 400) as $chunkIndex => $chunk) {
        $parameters = ['user_id' => $userId];
        $placeholders = [];
        foreach ($chunk as $index => $id) {
            $name = 'contact_id_' . $chunkIndex . '_' . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $id;
        }
        $statement = $pdo->prepare(
            'SELECT id, phone_normalized, email_normalized FROM contacts
             WHERE user_id = :user_id AND deleted_at IS NULL AND id IN (' . implode(', ', $placeholders) . ')
             ORDER BY id FOR UPDATE'
        );
        $statement->execute($parameters);
        foreach ($statement->fetchAll() as $contact) {
            $locked[(int) $contact['id']] = $contact;
        }
    }

    return array_values($locked);
}

/** Validate uploaded values and calculate owner-scoped city and duplicate review data. */
function buildContactImportReview(PDO $pdo, int $userId, array $sourceRows, bool $lockMatches = false): array
{
    $rows = [];
    $cityLookup = [];
    foreach ($sourceRows as $sourceRow) {
        $values = $sourceRow['values'];
        $fieldErrors = validateContactValues([
            'first_name' => $values['first_name'],
            'last_name' => $values['last_name'],
            'phone' => $values['phone'],
            'email' => $values['email'],
            'city_id' => '1',
        ]);
        unset($fieldErrors['city_id']);
        $cityErrors = validateCityName($values['city']);
        if ($cityErrors !== []) {
            $fieldErrors['city'] = $cityErrors['name'];
        }

        $rows[] = [
            'record' => (int) $sourceRow['record'],
            'values' => $values,
            'errors' => $fieldErrors,
            'warnings' => [],
            'phone_normalized' => normalizeContactPhone($values['phone']),
            'email_normalized' => normalizeContactEmail($values['email']),
        ];

        if ($cityErrors === []) {
            $cityLookup[$values['city']] = $values['city'];
        }
    }

    $missingCitiesByKey = [];
    $citySelectSql = 'SELECT id FROM cities WHERE user_id = :user_id AND name = :name LIMIT 1'
        . ($lockMatches ? ' FOR UPDATE' : '');
    $citySelect = $pdo->prepare($citySelectSql);
    foreach ($cityLookup as $name) {
        $cityKey = contactImportCityComparisonKey($pdo, $name);
        $citySelect->execute(['user_id' => $userId, 'name' => $name]);
        $cityId = $citySelect->fetchColumn();
        if ($cityId === false && !isset($missingCitiesByKey[$cityKey])) {
            $missingCitiesByKey[$cityKey] = ['key' => $cityKey, 'name' => $name];
        }
    }

    $activeContacts = findContactImportDuplicateCandidates($pdo, $userId, $rows, $lockMatches);
    $activePhone = [];
    $activeEmail = [];
    foreach ($activeContacts as $contact) {
        $contactId = (int) $contact['id'];
        if (is_string($contact['phone_normalized'])) {
            $activePhone[$contact['phone_normalized']][] = $contactId;
        }
        if (is_string($contact['email_normalized'])) {
            $activeEmail[$contact['email_normalized']][] = $contactId;
        }
    }

    $filePhone = [];
    $fileEmail = [];
    foreach ($rows as $index => $row) {
        if (is_string($row['phone_normalized'])) {
            $filePhone[$row['phone_normalized']][] = $index;
        }
        if (is_string($row['email_normalized'])) {
            $fileEmail[$row['email_normalized']][] = $index;
        }
    }

    $duplicateGroups = [];
    foreach (['phone' => [$filePhone, $activePhone], 'email' => [$fileEmail, $activeEmail]] as $kind => [$fileGroups, $activeGroups]) {
        foreach ($fileGroups as $key => $indexes) {
            $activeIds = $activeGroups[$key] ?? [];
            if (count($indexes) < 2 && $activeIds === []) {
                continue;
            }
            $records = array_map(static fn (int $index): int => $rows[$index]['record'], $indexes);
            $duplicateGroups[] = [
                'kind' => $kind,
                'key_hash' => hash('sha256', $kind . "\0" . $key),
                'records' => $records,
                'active_contact_ids' => array_values(array_map('intval', $activeIds)),
            ];

            foreach ($indexes as $index) {
                $otherRecords = array_values(array_diff($records, [$rows[$index]['record']]));
                $message = $kind === 'phone' ? 'Telefon' : 'Email';
                if ($otherRecords !== []) {
                    $shown = array_slice($otherRecords, 0, 8);
                    $extra = count($otherRecords) > 8 ? ' i još ' . (count($otherRecords) - 8) : '';
                    $rows[$index]['warnings'][] = $message . ' se poklapa sa CSV zapisima: '
                        . implode(', ', $shown) . $extra . '.';
                }
                if ($activeIds !== []) {
                    $shown = array_slice($activeIds, 0, 8);
                    $extra = count($activeIds) > 8 ? ' i još ' . (count($activeIds) - 8) : '';
                    $rows[$index]['warnings'][] = $message . ' se poklapa sa aktivnim kontaktom ID '
                        . implode(', ', $shown) . $extra . '.';
                }
            }
        }
    }

    $missingCities = array_values($missingCitiesByKey);
    $missingFingerprint = array_map(
        static fn (array $city): string => hash('sha256', $city['key']),
        $missingCities,
    );
    sort($missingFingerprint, SORT_STRING);
    usort($duplicateGroups, static fn (array $left, array $right): int => [$left['kind'], $left['key_hash']] <=> [$right['kind'], $right['key_hash']]);
    $fingerprint = hash('sha256', json_encode([
        'missing_cities' => $missingFingerprint,
        'duplicate_groups' => $duplicateGroups,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

    $errorCount = 0;
    $warningRowCount = 0;
    foreach ($rows as $row) {
        $errorCount += count($row['errors']);
        if ($row['warnings'] !== []) {
            $warningRowCount++;
        }
    }

    return [
        'rows' => $rows,
        'missing_cities' => array_map(static fn (array $city): string => $city['name'], $missingCities),
        'duplicate_groups' => $duplicateGroups,
        'errors_count' => $errorCount,
        'warning_rows_count' => $warningRowCount,
        'fingerprint' => $fingerprint,
        'can_import' => $errorCount === 0,
    ];
}

function createContactImportBatch(PDO $pdo, int $userId, array $rows): string
{
    $token = bin2hex(random_bytes(32));
    $payload = json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $statement = $pdo->prepare(
        'INSERT INTO contact_import_batches (user_id, token_hash, payload, expires_at, created_at)
         VALUES (:user_id, :token_hash, :payload, DATE_ADD(UTC_TIMESTAMP(), INTERVAL '
            . CONTACT_IMPORT_TTL_MINUTES . ' MINUTE), UTC_TIMESTAMP())'
    );
    $statement->execute([
        'user_id' => $userId,
        'token_hash' => hash('sha256', $token),
        'payload' => $payload,
    ]);

    return $token;
}

function cleanupExpiredContactImports(PDO $pdo, int $userId, ?string $currentTokenHash = null): void
{
    if ($currentTokenHash !== null) {
        $statement = $pdo->prepare(
            'DELETE FROM contact_import_batches
             WHERE expires_at <= UTC_TIMESTAMP() AND NOT (user_id = :user_id AND token_hash = :token_hash)'
        );
        $statement->execute(['user_id' => $userId, 'token_hash' => $currentTokenHash]);
        return;
    }

    $pdo->exec('DELETE FROM contact_import_batches WHERE expires_at <= UTC_TIMESTAMP()');
}

/** Complete an import atomically. Caller must pass only consent states from the POST. */
function completeContactImport(
    PDO $pdo,
    int $userId,
    string $token,
    string $expectedReviewFingerprint,
    bool $createMissingCities,
    bool $allowDuplicates,
): array
{
    if (preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
        return ['status' => 'unavailable'];
    }

    $pdo->beginTransaction();
    try {
        lockUserForHistoryMutation($pdo, $userId);
        $tokenHash = hash('sha256', $token);
        $batchQuery = $pdo->prepare(
            'SELECT id, payload, review_fingerprint, expires_at
             FROM contact_import_batches
             WHERE user_id = :user_id AND token_hash = :token_hash
             FOR UPDATE'
        );
        $batchQuery->execute(['user_id' => $userId, 'token_hash' => $tokenHash]);
        $batch = $batchQuery->fetch();
        if ($batch === false || strtotime((string) $batch['expires_at'] . ' UTC') <= time()) {
            $pdo->rollBack();
            return ['status' => 'unavailable'];
        }

        $sourceRows = json_decode((string) $batch['payload'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($sourceRows) || $sourceRows === []) {
            $pdo->rollBack();
            return ['status' => 'unavailable'];
        }
        $review = buildContactImportReview($pdo, $userId, $sourceRows, true);
        if (!$review['can_import']) {
            $pdo->rollBack();
            return ['status' => 'invalid', 'review' => $review];
        }

        if (preg_match('/\A[a-f0-9]{64}\z/D', $expectedReviewFingerprint) !== 1
            || !is_string($batch['review_fingerprint'])
            || !hash_equals($expectedReviewFingerprint, $review['fingerprint'])
            || !hash_equals((string) $batch['review_fingerprint'], $review['fingerprint'])
        ) {
            $updateReview = $pdo->prepare(
                'UPDATE contact_import_batches SET review_fingerprint = :fingerprint WHERE id = :id AND user_id = :user_id'
            );
            $updateReview->execute([
                'fingerprint' => $review['fingerprint'],
                'id' => (int) $batch['id'],
                'user_id' => $userId,
            ]);
            $pdo->commit();
            return ['status' => 'review_changed'];
        }

        if ($review['missing_cities'] !== [] && !$createMissingCities) {
            $pdo->rollBack();
            return ['status' => 'consent_required'];
        }
        if ($review['duplicate_groups'] !== [] && !$allowDuplicates) {
            $pdo->rollBack();
            return ['status' => 'consent_required'];
        }

        $cityIds = [];
        $createdCities = 0;
        $cityLookup = $pdo->prepare(
            'SELECT id FROM cities WHERE user_id = :user_id AND name = :name LIMIT 1 FOR UPDATE'
        );
        $insertCity = $pdo->prepare(
            'INSERT INTO cities (user_id, name, updated_at) VALUES (:user_id, :name, UTC_TIMESTAMP())'
        );
        foreach ($review['rows'] as $row) {
            $name = $row['values']['city'];
            $cityKey = contactImportCityComparisonKey($pdo, $name);
            if (isset($cityIds[$cityKey])) {
                continue;
            }
            $cityLookup->execute(['user_id' => $userId, 'name' => $name]);
            $cityId = $cityLookup->fetchColumn();
            if ($cityId === false) {
                if (!$createMissingCities) {
                    throw new RuntimeException('A city disappeared after import review.');
                }
                $insertCity->execute(['user_id' => $userId, 'name' => $name]);
                $cityId = (int) $pdo->lastInsertId();
                $createdCities++;
            }
            $cityIds[$cityKey] = (int) $cityId;
        }

        $insertContact = $pdo->prepare(
            'INSERT INTO contacts
                (user_id, first_name, last_name, phone, email, phone_normalized, email_normalized, city_id, updated_at)
             VALUES
                (:user_id, :first_name, :last_name, :phone, :email, :phone_normalized, :email_normalized, :city_id, UTC_TIMESTAMP())'
        );
        foreach ($review['rows'] as $row) {
            $values = $row['values'];
            $cityKey = contactImportCityComparisonKey($pdo, $values['city']);
            $insertContact->execute([
                'user_id' => $userId,
                'first_name' => $values['first_name'],
                'last_name' => $values['last_name'],
                'phone' => $values['phone'],
                'email' => $values['email'],
                'phone_normalized' => normalizeContactPhone($values['phone']),
                'email_normalized' => normalizeContactEmail($values['email']),
                'city_id' => $cityIds[$cityKey],
            ]);
        }

        $deleteBatch = $pdo->prepare('DELETE FROM contact_import_batches WHERE id = :id AND user_id = :user_id');
        $deleteBatch->execute(['id' => (int) $batch['id'], 'user_id' => $userId]);
        if ($deleteBatch->rowCount() !== 1) {
            throw new RuntimeException('The import batch was already consumed.');
        }

        $pdo->commit();
        return ['status' => 'completed', 'contacts' => count($review['rows']), 'cities' => $createdCities];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
