<?php

declare(strict_types=1);

function normalizeApiTokenName(string $name): ?string
{
    if (preg_match('//u', $name) !== 1) {
        return null;
    }

    $normalized = preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $name);

    return is_string($normalized) ? $normalized : null;
}

function apiTokenNameLength(string $name): int
{
    $length = preg_match_all('/./us', $name);

    return $length === false ? 0 : $length;
}

function validateApiTokenName(string $name): array
{
    if (preg_match('//u', $name) !== 1) {
        return ['name' => 'Naziv tokena sadrži nevažeći tekst.'];
    }
    if ($name === '') {
        return ['name' => 'Naziv tokena je obavezan.'];
    }
    if (apiTokenNameLength($name) > 100) {
        return ['name' => 'Naziv tokena može imati najviše 100 Unicode znakova.'];
    }

    return [];
}

/** Create and persist only a hash; return the plaintext exactly once to the caller. */
function createApiToken(PDO $pdo, int $userId, string $name): array
{
    $plainToken = bin2hex(random_bytes(32));
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            'INSERT INTO api_tokens (user_id, name, token_hash, created_at, expires_at)
             VALUES (:user_id, :name, :token_hash, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY))'
        );
        $statement->execute([
            'user_id' => $userId,
            'name' => $name,
            'token_hash' => hash('sha256', $plainToken),
        ]);
        $tokenId = (int) $pdo->lastInsertId();

        $expiry = $pdo->prepare('SELECT expires_at FROM api_tokens WHERE id = :id AND user_id = :user_id');
        $expiry->execute(['id' => $tokenId, 'user_id' => $userId]);
        $expiresAt = $expiry->fetchColumn();
        if (!is_string($expiresAt)) {
            throw new RuntimeException('API token expiry lookup failed.');
        }
        $pdo->commit();

        return ['id' => $tokenId, 'token' => $plainToken, 'expires_at' => $expiresAt];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function revokeApiToken(PDO $pdo, int $userId, int $tokenId): bool
{
    $statement = $pdo->prepare(
        'UPDATE api_tokens SET revoked_at = UTC_TIMESTAMP()
         WHERE id = :id AND user_id = :user_id AND revoked_at IS NULL'
    );
    $statement->execute(['id' => $tokenId, 'user_id' => $userId]);

    return $statement->rowCount() === 1;
}

function authenticateApiToken(PDO $pdo, string $plainToken): ?int
{
    if (preg_match('/\A[a-f0-9]{64}\z/D', $plainToken) !== 1) {
        return null;
    }

    $tokenHash = hash('sha256', $plainToken);
    $statement = $pdo->prepare(
        'SELECT api_tokens.user_id, api_tokens.token_hash
         FROM api_tokens
         INNER JOIN users ON users.id = api_tokens.user_id
         WHERE api_tokens.token_hash = :token_hash
           AND api_tokens.revoked_at IS NULL
           AND api_tokens.expires_at > UTC_TIMESTAMP()
         LIMIT 1'
    );
    $statement->execute(['token_hash' => $tokenHash]);
    $record = $statement->fetch();

    if ($record === false || !hash_equals((string) $record['token_hash'], $tokenHash)) {
        return null;
    }

    return (int) $record['user_id'];
}

function formatApiTokenTime(string $utcDateTime): string
{
    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        $utcDateTime,
        new DateTimeZone('UTC'),
    );
    if (!$date instanceof DateTimeImmutable) {
        return $utcDateTime;
    }

    return $date->setTimezone(new DateTimeZone('Europe/Belgrade'))->format('H:i d-m-Y');
}
