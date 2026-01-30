<?php

namespace BinktermPHP;

use BinktermPHP\Binkp\Logger;

class AdminActionLogger
{
    private static ?Logger $logger = null;

    public static function logAction(int $userId, string $action, array $details = []): void
    {
        $logger = self::getLogger();
        $logger->info('Admin action', [
            'user_id' => $userId,
            'action' => $action,
            'details' => $details
        ]);
    }

    private static function getLogger(): Logger
    {
        if (self::$logger === null) {
            self::$logger = new Logger(Config::getLogPath('admin_actions.log'), 'INFO', false);
        }

        return self::$logger;
    }
}
