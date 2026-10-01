<?php

declare(strict_types=1);

function isAuthenticated(): bool
{
    return isset($_SESSION['user_id']) && is_int($_SESSION['user_id']);
}

function currentUserId(): ?int
{
    return isAuthenticated() ? $_SESSION['user_id'] : null;
}

function registerUser(string $email, string $password): bool
{
    $pdo = db();
    $existingUser = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $existingUser->execute(['email' => $email]);

    if ($existingUser->fetch() !== false) {
        return false;
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $insertUser = $pdo->prepare(
        'INSERT INTO users (email, password_hash) VALUES (:email, :password_hash)'
    );
    $insertUser->execute([
        'email' => $email,
        'password_hash' => $passwordHash,
    ]);

    return true;
}

function attemptLogin(string $email, string $password): bool
{
    $statement = db()->prepare(
        'SELECT id, password_hash FROM users WHERE email = :email LIMIT 1'
    );
    $statement->execute(['email' => $email]);
    $user = $statement->fetch();

    if ($user === false || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION = ['user_id' => (int) $user['id']];

    return true;
}

function logoutUser(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $cookie['path'],
            'domain' => $cookie['domain'],
            'secure' => $cookie['secure'],
            'httponly' => $cookie['httponly'],
            'samesite' => $cookie['samesite'],
        ]);
    }

    session_destroy();
}

function redirectTo(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
