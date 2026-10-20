<?php

declare(strict_types=1);

function apiJsonResponse(int $status, array $payload, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function apiErrorResponse(int $status, string $code, string $message, array $headers = []): never
{
    apiJsonResponse($status, ['error' => ['code' => $code, 'message' => $message]], $headers);
}

function apiRequestUsesSecureTransport(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off' && $https !== '0') {
        return true;
    }
    if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }

    // Local HTTP is allowed only when both the peer and requested host are loopback.
    $remoteAddress = strtolower((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $isLoopbackPeer = in_array($remoteAddress, ['127.0.0.1', '::1'], true);
    $isLoopbackHost = preg_match('/\A(?:localhost|127\.0\.0\.1|\[::1\])(?::\d{1,5})?\z/D', $host) === 1;

    return $isLoopbackPeer && $isLoopbackHost;
}

function apiAuthorizationHeader(): ?string
{
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $serverKey) {
        $serverHeader = $_SERVER[$serverKey] ?? null;
        if (is_string($serverHeader) && $serverHeader !== '') {
            return $serverHeader;
        }
    }

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (is_string($name) && strcasecmp($name, 'Authorization') === 0 && is_string($value)) {
                return $value;
            }
        }
    }

    return null;
}

function apiAuthenticateRequest(PDO $pdo): int
{
    if (isset($_GET['token'])) {
        apiErrorResponse(400, 'query_token_not_supported', 'API tokeni se šalju isključivo u Authorization zaglavlju.');
    }
    if (!apiRequestUsesSecureTransport()) {
        apiErrorResponse(400, 'https_required', 'API zahtevi zahtevaju HTTPS.');
    }

    $challenge = ['WWW-Authenticate' => 'Bearer realm="Address Book API"'];
    $authorization = apiAuthorizationHeader();
    if ($authorization === null) {
        apiErrorResponse(401, 'authentication_required', 'Nedostaje Bearer token.', $challenge);
    }
    if (preg_match('/\ABearer[ \t]+([A-Fa-f0-9]{64})\z/iD', trim($authorization), $matches) !== 1) {
        apiErrorResponse(401, 'invalid_token', 'Bearer token nije validan.', [
            'WWW-Authenticate' => 'Bearer realm="Address Book API", error="invalid_token"',
        ]);
    }

    try {
        $userId = authenticateApiToken($pdo, strtolower($matches[1]));
    } catch (Throwable $exception) {
        error_log('API bearer authentication failed due to a database error.');
        apiErrorResponse(500, 'server_error', 'API zahtev trenutno nije moguće obraditi.');
    }

    if ($userId === null) {
        apiErrorResponse(401, 'invalid_token', 'Bearer token je nevažeći, istekao ili opozvan.', [
            'WWW-Authenticate' => 'Bearer realm="Address Book API", error="invalid_token"',
        ]);
    }

    return $userId;
}
