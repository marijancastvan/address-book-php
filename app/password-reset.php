<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

function findValidPasswordResetToken(PDO $pdo, string $rawToken, bool $forUpdate = false): ?array
{
    if (preg_match('/\A[a-f0-9]{64}\z/D', $rawToken) !== 1) {
        return null;
    }

    $tokenHash = hash('sha256', $rawToken);
    $sql = 'SELECT id, user_id, token_hash, used_at,
                   (expires_at > CURRENT_TIMESTAMP) AS is_unexpired
            FROM password_reset_tokens
            WHERE token_hash = :token_hash
            LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $statement = $pdo->prepare($sql);
    $statement->execute(['token_hash' => $tokenHash]);
    $record = $statement->fetch();

    if ($record === false
        || !hash_equals((string) $record['token_hash'], $tokenHash)
        || $record['used_at'] !== null
        || (int) $record['is_unexpired'] !== 1
    ) {
        return null;
    }

    return $record;
}

/**
 * Create one current reset token per account. The caller owns the transaction.
 */
function createPasswordResetToken(PDO $pdo, int $userId, string $rawToken): int
{
    $invalidate = $pdo->prepare(
        'UPDATE password_reset_tokens
         SET used_at = CURRENT_TIMESTAMP
         WHERE user_id = :user_id AND used_at IS NULL'
    );
    $invalidate->execute(['user_id' => $userId]);

    $insert = $pdo->prepare(
        'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
         VALUES (:user_id, :token_hash, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 60 MINUTE))'
    );
    $insert->execute([
        'user_id' => $userId,
        'token_hash' => hash('sha256', $rawToken),
    ]);

    return (int) $pdo->lastInsertId();
}

function sendPasswordResetEmail(string $email, string $rawToken): bool
{
    global $config;

    $baseUrl = $config['app']['base_url'] ?? '';
    $fromAddress = $config['mail']['from_address'] ?? '';
    $fromName = $config['mail']['from_name'] ?? 'Address Book';
    $smtpHost = $config['mail']['smtp_host'] ?? '';
    $smtpPort = filter_var($config['mail']['smtp_port'] ?? null, FILTER_VALIDATE_INT);
    $smtpUsername = $config['mail']['smtp_username'] ?? '';
    $smtpPassword = $config['mail']['smtp_password'] ?? '';
    $smtpEncryption = $config['mail']['smtp_encryption'] ?? '';
    if (!is_string($baseUrl)
        || !is_string($fromAddress)
        || !is_string($fromName)
        || !is_string($smtpHost)
        || !is_string($smtpUsername)
        || $smtpUsername === ''
        || !is_string($smtpPassword)
        || $smtpPassword === ''
        || !is_string($smtpEncryption)
        || strtolower($smtpEncryption) !== 'tls'
        || $smtpHost === ''
        || preg_match('/[\r\n]/', $smtpHost) === 1
        || $smtpPort === false
        || $smtpPort < 1
        || $smtpPort > 65535
    ) {
        error_log('Password reset email configuration is invalid.');
        return false;
    }

    $baseUrl = rtrim($baseUrl, '/');
    $urlParts = parse_url($baseUrl);
    if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false
        || !is_array($urlParts)
        || !in_array($urlParts['scheme'] ?? '', ['http', 'https'], true)
        || !isset($urlParts['host'])
        || filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false
        || preg_match('/[\r\n]/', $fromName) === 1
    ) {
        error_log('Password reset email configuration is invalid.');
        return false;
    }

    $resetUrl = $baseUrl . '/reset-password.php?token=' . rawurlencode($rawToken);
    $subject = 'Address Book - Reset lozinke';
    $message = "Zatražili ste reset lozinke za svoj Address Book nalog.\n\n"
        . "Otvorite sledeći link u roku od 60 minuta:\n"
        . $resetUrl . "\n\n"
        . "Ako niste tražili reset lozinke, ignorišite ovu poruku.\n";

    try {
        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = $smtpHost;
        $mailer->Port = $smtpPort;
        $mailer->SMTPAuth = true;
        $mailer->Username = $smtpUsername;
        $mailer->Password = $smtpPassword;
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        $mailer->setFrom($fromAddress, $fromName);
        $mailer->addAddress($email);
        $mailer->isHTML(false);
        $mailer->Subject = $subject;
        $mailer->Body = $message;
        $sent = $mailer->send();
    } catch (Throwable $exception) {
        $sent = false;
    }

    if (!$sent) {
        error_log('Password reset email delivery failed. Check the configured SMTP transport.');
    }

    return $sent;
}

function invalidatePasswordResetToken(PDO $pdo, int $tokenId): void
{
    $statement = $pdo->prepare(
        'UPDATE password_reset_tokens
         SET used_at = CURRENT_TIMESTAMP
         WHERE id = :id AND used_at IS NULL'
    );
    $statement->execute(['id' => $tokenId]);
}

function completePasswordReset(PDO $pdo, string $rawToken, string $password): ?int
{
    $pdo->beginTransaction();

    $token = findValidPasswordResetToken($pdo, $rawToken, true);
    if ($token === null) {
        $pdo->rollBack();
        return null;
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($passwordHash)) {
        throw new RuntimeException('Password hashing failed.');
    }

    $userUpdate = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :user_id');
    $userUpdate->execute([
        'password_hash' => $passwordHash,
        'user_id' => (int) $token['user_id'],
    ]);
    if ($userUpdate->rowCount() !== 1) {
        throw new RuntimeException('Password reset user update failed.');
    }

    $consumeTokens = $pdo->prepare(
        'UPDATE password_reset_tokens
         SET used_at = CURRENT_TIMESTAMP
         WHERE user_id = :user_id AND used_at IS NULL'
    );
    $consumeTokens->execute(['user_id' => (int) $token['user_id']]);
    if ($consumeTokens->rowCount() < 1) {
        throw new RuntimeException('Password reset token consumption failed.');
    }

    $pdo->commit();

    return (int) $token['user_id'];
}
