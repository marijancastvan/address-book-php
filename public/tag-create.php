<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/csrf.php';
require_once dirname(__DIR__) . '/app/tag-helpers.php';

$isJsonRequest = ($_GET['format'] ?? '') === 'json';
$sendJson = static function (int $statusCode, array $payload): never {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

if (!isAuthenticated()) {
    if ($isJsonRequest) {
        $sendJson(401, ['error' => 'Sesija je istekla. Prijavite se ponovo.']);
    }
    redirectTo('/login.php');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    if ($isJsonRequest) {
        header('Allow: POST');
        $sendJson(405, ['error' => 'Dozvoljen je samo POST zahtev.']);
    }
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}
if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
    if ($isJsonRequest) {
        $sendJson(403, ['error' => 'Forma je istekla ili nije validna. Osvežite stranicu i pokušajte ponovo.']);
    }
    redirectTo('/tags.php?error=csrf');
}

$input = $_POST['name'] ?? '';
$rawName = is_scalar($input) ? (string) $input : '';
$tagName = normalizeTagName($rawName);
$errors = $tagName === null
    ? ['name' => 'Naziv taga sadrži nevažeći tekst.']
    : validateTagName($tagName);
$tagName ??= '';
$duplicateName = false;

try {
    $pdo = db();
    if ($errors === [] && tagNameExistsForUser($pdo, (int) currentUserId(), $tagName)) {
        $errors['name'] = 'Tag sa ovim nazivom već postoji.';
        $duplicateName = true;
    }
    if ($errors === []) {
        $tagId = createTagForUser($pdo, (int) currentUserId(), $tagName);
        if ($isJsonRequest) {
            $sendJson(201, ['status' => 'created', 'tag' => ['id' => $tagId, 'name' => $tagName]]);
        }
        redirectTo('/tags.php?success=created');
    }
} catch (PDOException $exception) {
    if (isDuplicateTagError($exception)) {
        $errors['name'] = 'Tag sa ovim nazivom već postoji.';
        $duplicateName = true;
    } else {
        if ($isJsonRequest) {
            error_log('Inline tag creation database error.');
            $sendJson(500, ['error' => 'Tag trenutno nije moguće dodati. Pokušajte ponovo.']);
        }
        error_log('Tag creation database error.');
        $errors['_form'] = 'Tag trenutno nije moguće sačuvati. Pokušajte ponovo kasnije.';
    }
}

if ($isJsonRequest) {
    $sendJson($duplicateName ? 409 : 422, ['errors' => $errors]);
}

$_SESSION['tag_form_state'] = ['mode' => 'create', 'name' => $tagName, 'errors' => $errors];
redirectTo('/tags.php?modal=create');
