<?php

namespace BinktermPHP;

class AdminActionLogger
{
    public static function logAction(int $userId, string $action, array $details = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $detailsJson = !empty($details) ? json_encode($details) : '[]';

        $logMessage = sprintf(
            "[%s] Admin action - User ID: %d, Action: %s, Details: %s",
            $timestamp,
            $userId,
            $action,
            $detailsJson
        );

        error_log($logMessage);
    }
}
