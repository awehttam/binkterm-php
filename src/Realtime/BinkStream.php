<?php

namespace BinktermPHP\Realtime;

use JsonException;
use PDO;

class BinkStream
{
    public static function emit(PDO $db, string $eventType, array $payload = [], ?int $userId = null, bool $adminOnly = false): ?int
    {
        $stmt = $db->prepare("
            INSERT INTO sse_events (event_type, payload, user_id, admin_only)
            VALUES (:event_type, CAST(:payload AS jsonb), :user_id, :admin_only)
            RETURNING id
        ");

        $stmt->bindValue(':event_type', $eventType, PDO::PARAM_STR);
        $stmt->bindValue(':payload', self::encodePayload($payload), PDO::PARAM_STR);
        if ($userId === null) {
            $stmt->bindValue(':user_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':admin_only', $adminOnly, PDO::PARAM_BOOL);
        $stmt->execute();

        $eventId = (int)$stmt->fetchColumn();
        if ($eventId > 0) {
            $notifyStmt = $db->prepare("SELECT pg_notify('binkstream', :payload)");
            $notifyStmt->bindValue(':payload', (string)$eventId, PDO::PARAM_STR);
            $notifyStmt->execute();
            return $eventId;
        }

        return null;
    }

    /**
     * @throws JsonException
     */
    private static function encodePayload(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
