<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/csrf.php';
require_once dirname(__DIR__) . '/app/tag-helpers.php';

if (!isAuthenticated()) {
    redirectTo('/login.php');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}
if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
    redirectTo('/tags.php?error=csrf');
}

$input = $_POST['name'] ?? '';
$rawName = is_scalar($input) ? (string) $input : '';
$tagName = normalizeTagName($rawName);
$errors = $tagName === null
    ? ['name' => 'Naziv taga sadrži nevažeći tekst.']
    : validateTagName($tagName);
$tagName ??= '';

try {
    $pdo = db();
    if ($errors === [] && tagNameExistsForUser($pdo, (int) currentUserId(), $tagName)) {
        $errors['name'] = 'Tag sa ovim nazivom već postoji.';
    }
    if ($errors === []) {
        createTagForUser($pdo, (int) currentUserId(), $tagName);
        redirectTo('/tags.php?success=created');
    }
} catch (PDOException $exception) {
    if (isDuplicateTagError($exception)) {
        $errors['name'] = 'Tag sa ovim nazivom već postoji.';
    } else {
        error_log('Tag creation database error.');
        $errors['_form'] = 'Tag trenutno nije moguće sačuvati. Pokušajte ponovo kasnije.';
    }
}

$_SESSION['tag_form_state'] = ['mode' => 'create', 'name' => $tagName, 'errors' => $errors];
redirectTo('/tags.php?modal=create');
