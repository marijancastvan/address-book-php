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

$tagId = filter_var($_POST['tag_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($tagId === false || $tagId === null) {
    redirectTo('/tags.php?error=not_found');
}

$userId = (int) currentUserId();
$input = $_POST['name'] ?? '';
$rawName = is_scalar($input) ? (string) $input : '';
$tagName = normalizeTagName($rawName);
$errors = $tagName === null
    ? ['name' => 'Naziv taga sadrži nevažeći tekst.']
    : validateTagName($tagName);
$tagName ??= '';

try {
    $pdo = db();
    $ownedTag = $pdo->prepare('SELECT id FROM tags WHERE id = :tag_id AND user_id = :user_id LIMIT 1');
    $ownedTag->execute(['tag_id' => $tagId, 'user_id' => $userId]);
    if ($ownedTag->fetch() === false) {
        redirectTo('/tags.php?error=not_found');
    }

    if ($errors === [] && tagNameExistsForUser($pdo, $userId, $tagName, $tagId)) {
        $errors['name'] = 'Tag sa ovim nazivom već postoji.';
    }
    if ($errors === []) {
        if (!renameTagForUser($pdo, $userId, $tagId, $tagName)) {
            redirectTo('/tags.php?error=not_found');
        }
        redirectTo('/tags.php?success=updated');
    }
} catch (PDOException $exception) {
    if (isDuplicateTagError($exception)) {
        $errors['name'] = 'Tag sa ovim nazivom već postoji.';
    } else {
        error_log('Tag editing database error.');
        $errors['_form'] = 'Tag trenutno nije moguće sačuvati. Pokušajte ponovo kasnije.';
    }
} catch (Throwable $exception) {
    error_log('Tag editing failed while recording history.');
    $errors['_form'] = 'Tag trenutno nije moguće sačuvati. Pokušajte ponovo kasnije.';
}

$_SESSION['tag_form_state'] = ['mode' => 'edit', 'tag_id' => $tagId, 'name' => $tagName, 'errors' => $errors];
redirectTo('/tags.php?edit_id=' . $tagId);
