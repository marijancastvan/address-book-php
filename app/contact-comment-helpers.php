<?php

declare(strict_types=1);

require_once __DIR__ . '/history-helpers.php';

/** Insert an owner-authored comment only while the owned contact is active. */
function createContactComment(PDO $pdo, int $userId, int $contactId, string $body): string
{
    $pdo->beginTransaction();
    try {
        $authorEmail = lockUserForHistoryMutation($pdo, $userId);
        $contactQuery = $pdo->prepare(
            'SELECT deleted_at FROM contacts WHERE id = :contact_id AND user_id = :user_id FOR UPDATE'
        );
        $contactQuery->execute(['contact_id' => $contactId, 'user_id' => $userId]);
        $contact = $contactQuery->fetch(PDO::FETCH_ASSOC);
        if ($contact === false) {
            $pdo->rollBack();
            return 'not_found';
        }
        if ($contact['deleted_at'] !== null) {
            $pdo->rollBack();
            return 'deleted';
        }

        $insert = $pdo->prepare(
            'INSERT INTO contact_comments
                (user_id, contact_id, origin_contact_id, author_user_id, author_email_snapshot, body, created_at)
             VALUES (:user_id, :contact_id, :origin_contact_id, :author_user_id, :author_email, :body, UTC_TIMESTAMP())'
        );
        $insert->execute([
            'user_id' => $userId,
            'contact_id' => $contactId,
            'origin_contact_id' => $contactId,
            'author_user_id' => $userId,
            'author_email' => $authorEmail,
            'body' => $body,
        ]);
        $pdo->commit();

        return 'created';
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
