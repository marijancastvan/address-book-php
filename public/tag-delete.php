<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/csrf.php';
require_once dirname(__DIR__) . '/app/tag-helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}
if (!isAuthenticated()) {
    redirectTo('/login.php');
}
if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
    redirectTo('/tags.php?error=csrf');
}

$tagId = filter_var($_POST['tag_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($tagId === false || $tagId === null) {
    redirectTo('/tags.php?error=not_found');
}

try {
    $deleted = deleteTagForUser(db(), (int) currentUserId(), $tagId);
    redirectTo($deleted ? '/tags.php?success=deleted' : '/tags.php?error=not_found');
} catch (PDOException $exception) {
    error_log('Tag deletion database error.');
    http_response_code(500);
    exit('Tag trenutno nije moguće obrisati. Pokušajte ponovo kasnije.');
}
