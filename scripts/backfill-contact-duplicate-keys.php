<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/contact-helpers.php';

$options = getopt('', ['batch-size::']);
$batchSize = isset($options['batch-size']) ? filter_var($options['batch-size'], FILTER_VALIDATE_INT) : 250;
if (!is_int($batchSize) || $batchSize < 1 || $batchSize > 1000) {
    fwrite(STDERR, "Batch size must be an integer from 1 to 1000.\n");
    exit(2);
}

$pdo = db();
$lastId = 0;
$processed = 0;
$updated = 0;
$unchanged = 0;
$changedDuringRun = 0;
$unsupportedPhones = 0;
$unsupportedEmails = 0;

$select = $pdo->prepare(
    'SELECT id, phone, email, phone_normalized, email_normalized, updated_at
     FROM contacts
     WHERE id > :last_id
     ORDER BY id ASC
     LIMIT :batch_size'
);
$select->bindValue(':last_id', $lastId, PDO::PARAM_INT);
$select->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);
$update = $pdo->prepare(
    'UPDATE contacts
     SET phone_normalized = :phone_normalized,
         email_normalized = :email_normalized
     WHERE id = :contact_id
       AND BINARY phone = BINARY :original_phone
       AND BINARY email = BINARY :original_email
       AND updated_at = :original_updated_at'
);

try {
    while (true) {
        $select->bindValue(':last_id', $lastId, PDO::PARAM_INT);
        $select->execute();
        $batch = $select->fetchAll(PDO::FETCH_ASSOC);
        if ($batch === []) {
            break;
        }

        $pdo->beginTransaction();
        foreach ($batch as $contact) {
            $contactId = (int) $contact['id'];
            $phoneKey = normalizeContactPhone((string) $contact['phone']);
            $emailKey = normalizeContactEmail((string) $contact['email']);
            $processed++;
            if ($contact['phone_normalized'] === $phoneKey && $contact['email_normalized'] === $emailKey) {
                $unchanged++;
            } else {
                $update->execute([
                    'phone_normalized' => $phoneKey,
                    'email_normalized' => $emailKey,
                    'contact_id' => $contactId,
                    'original_phone' => $contact['phone'],
                    'original_email' => $contact['email'],
                    'original_updated_at' => $contact['updated_at'],
                ]);
                if ($update->rowCount() > 0) {
                    $updated++;
                } else {
                    $changedDuringRun++;
                }
            }
            if ($phoneKey === null) {
                $unsupportedPhones++;
            }
            if ($emailKey === null) {
                $unsupportedEmails++;
            }
            $lastId = $contactId;
        }
        $pdo->commit();
    }
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Contact duplicate-key backfill failed.');
    fwrite(STDERR, "Backfill failed. Check the application log; no personal data was written to the report.\n");
    exit(1);
}

fwrite(STDOUT, "Contact duplicate-key backfill complete.\n");
fwrite(STDOUT, "Processed: {$processed}\nUpdated: {$updated}\nUnchanged: {$unchanged}\nChanged during run (skipped): {$changedDuringRun}\n");
fwrite(STDOUT, "Unsupported/empty phone keys: {$unsupportedPhones}\nUnsupported/empty email keys: {$unsupportedEmails}\n");
