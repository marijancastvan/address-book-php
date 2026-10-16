<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/csrf.php';
require_once dirname(__DIR__) . '/app/contact-helpers.php';
require_once dirname(__DIR__) . '/app/contact-comment-helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
if (!isAuthenticated()) {
    redirectTo('/login.php');
}

$contactId = filter_var($_POST['contact_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($contactId === false || !isValidCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('Neispravan zahtev.');
}
$contactId = (int) $contactId;
$returnContext = contactListReturnContext($_POST);
if (($_POST['view'] ?? '') === 'deleted') {
    $returnContext['view'] = 'deleted';
}
$commentPageUrl = '/contact-comments.php?' . http_build_query(
    ['id' => $contactId] + $returnContext + ['comments_page' => 1],
);
$bodyInput = $_POST['body'] ?? null;
$body = is_string($bodyInput) ? $bodyInput : '';
$error = null;
if (!is_string($bodyInput) || preg_match('//u', $bodyInput) !== 1) {
    $error = 'Unesite komentar u ispravnom tekstualnom formatu.';
} else {
    $trimmedBody = preg_replace('/\A[\p{Z}\s]+|[\p{Z}\s]+\z/u', '', $bodyInput);
    $body = is_string($trimmedBody) ? $trimmedBody : '';
    if ($body === '') {
        $error = 'Komentar je obavezan.';
    } elseif (contactCharacterLength($body) > 5000) {
        $error = 'Komentar može imati najviše 5000 znakova.';
    }
}

if ($error === null) {
    try {
        $result = createContactComment(db(), (int) currentUserId(), $contactId, $body);
        if ($result === 'created') {
            unset($_SESSION['contact_comment_flash']);
            redirectTo($commentPageUrl);
        }
        $error = $result === 'deleted'
            ? 'Komentar nije dodat jer je kontakt obrisan. Možete ga dodati nakon vraćanja kontakta.'
            : 'Kontakt nije pronađen ili nemate dozvolu za pristup.';
    } catch (Throwable $exception) {
        error_log('Contact comment creation failed.');
        $error = 'Komentar nije sačuvan. Pokušajte ponovo.';
    }
}

$_SESSION['contact_comment_flash'] = [
    'contact_id' => $contactId,
    'body' => $bodyInput === null ? '' : (is_scalar($bodyInput) ? (string) $bodyInput : ''),
    'error' => $error,
];
redirectTo($commentPageUrl);
