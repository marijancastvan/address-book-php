<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/contact-import-helpers.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$assert(contactImportCountLabel(1, 'kontakt', 'kontakta', 'kontakata') === '1 kontakt', 'Singular Serbian count label is incorrect.');
$assert(contactImportCountLabel(2, 'kontakt', 'kontakta', 'kontakata') === '2 kontakta', 'Few Serbian count label is incorrect.');
$assert(contactImportCountLabel(12, 'kontakt', 'kontakta', 'kontakata') === '12 kontakata', 'Teen Serbian count label is incorrect.');

$comma = parseContactImportCsv(
    "\xEF\xBB\xBFfirst_name,last_name,phone,email,city\r\n"
    . "Ana,Petrović,06400123,ana@example.com,Zemun\r\n"
    . "\r\n"
    . "Luka,Jovanović,+381641234567,luka@example.com,\"Novi, Beograd\"\r\n"
    . "Mila,Ivić,064555,mila@example.com,\"Novi \"\"Beograd\"\"\"\r\n",
    ',',
);
$assert($comma['error'] === null && count($comma['rows']) === 3, 'BOM, comma delimiter, blank row, and quoted delimiter should parse.');
$assert($comma['rows'][0]['values']['phone'] === '06400123', 'A leading phone zero must remain intact.');
$assert($comma['rows'][1]['values']['phone'] === '+381641234567', 'A leading plus must remain intact.');
$assert($comma['rows'][1]['values']['city'] === 'Novi, Beograd', 'Quoted delimiters should be preserved.');
$assert($comma['rows'][2]['values']['city'] === 'Novi "Beograd"', 'Escaped quote characters should be preserved.');

$semicolon = parseContactImportCsv(
    "city;email;phone;last_name;first_name\n\"Novi; Beograd\";ana@example.com;00123;Ivić;Ana\n"
    . "Beograd;ana2@example.com;00234;\"Prezime\nsa prelomom\";Mila\n",
    ';',
);
$assert($semicolon['error'] === null && count($semicolon['rows']) === 2, 'Reordered semicolon CSV and multiline fields should parse.');
$assert($semicolon['rows'][0]['values']['first_name'] === 'Ana', 'Columns must be mapped by header name.');
$assert($semicolon['rows'][0]['values']['phone'] === '00123', 'Semicolon CSV must preserve leading zeros.');
$assert($semicolon['rows'][1]['values']['last_name'] === "Prezime\nsa prelomom", 'Quoted line breaks should be preserved.');

$invalidCases = [
    ["first_name,last_name,phone,email\nA,B,1,a@example.com\n", ',', 'missing header column'],
    ["first_name,last_name,phone,email,city,other\nA,B,1,a@example.com,C,D\n", ',', 'unknown header column'],
    ["first_name,last_name,phone,email,city\nA,B,1,a@example.com\n", ',', 'unequal field count'],
    ["first_name,last_name,phone,email,city\nA,B,1,a@example.com,\"unterminated\n", ',', 'unclosed quote'],
    ["first_name,last_name,phone,email,city\nA,B,1,a@example.com,To\xFF\n", ',', 'invalid UTF-8'],
];
foreach ($invalidCases as [$contents, $delimiter, $scenario]) {
    $parsed = parseContactImportCsv($contents, $delimiter);
    $assert($parsed['error'] !== null, 'Parser should reject ' . $scenario . '.');
}

$oversized = parseContactImportCsv(str_repeat('x', CONTACT_IMPORT_MAX_BYTES + 1), ',');
$assert($oversized['error'] !== null, 'Parser should reject content over the byte limit.');

$duplicateHeader = parseContactImportCsv(
    "first_name,last_name,phone,email,city,city\nA,B,1,a@example.com,C,C\n",
    ',',
);
$assert($duplicateHeader['error'] !== null, 'Parser should reject duplicate header columns.');

$tooManyRows = "first_name,last_name,phone,email,city\n";
for ($index = 0; $index <= CONTACT_IMPORT_MAX_ROWS; $index++) {
    $tooManyRows .= "Ime,Prezime,064{$index},u{$index}@example.com,Grad\n";
}
$tooMany = parseContactImportCsv($tooManyRows, ',');
$assert($tooMany['error'] !== null, 'Parser should reject more than 1000 contact rows.');

fwrite(STDOUT, "Contact CSV parser checks passed.\n");
