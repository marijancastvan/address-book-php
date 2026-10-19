<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/auth.php';

if (!isAuthenticated()) {
    redirectTo('/login.php');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Dozvoljen je samo GET zahtev.');
}

$stream = fopen('php://temp', 'w+b');
fputcsv($stream, ['first_name', 'last_name', 'phone', 'email', 'city'], ',', '"', '');
fputcsv($stream, ['Mila', 'Petrović', '0641234567', 'mila.petrovic@example.com', 'Zemun'], ',', '"', '');
fputcsv($stream, ['Luka', 'Jovanović', '+381641234568', 'luka.jovanovic@example.com', 'Novi Beograd'], ',', '"', '');
rewind($stream);
$csv = stream_get_contents($stream);
fclose($stream);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="address-book-kontakti-sablon.csv"');
header('Cache-Control: no-store');
echo $csv;
