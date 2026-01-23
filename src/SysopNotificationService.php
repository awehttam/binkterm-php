<?php
/**
 * Sysop Notification Service
 *
 * Utility functions for sending system notifications via netmail.
 */

namespace BinktermPHP;

use BinktermPHP\Binkp\Config\BinkpConfig;

class SysopNotificationService
{
    /**
     * Sanitize string to valid UTF-8
     */
    private static function sanitizeUtf8(string $str): string
    {
        // Remove invalid UTF-8 sequences
        $str = mb_convert_encoding($str, 'UTF-8', 'UTF-8');
        // Remove any remaining non-printable characters except newlines/tabs
        $str = preg_replace('/[^\x09\x0A\x0D\x20-\x7E\xA0-\xFF]/u', '', $str);
        return $str ?: '';
    }

    /**
     * Send a netmail notification to the sysop
     *
     * @param string $subject Message subject
     * @param string $message Message body
     * @param string $fromName Sender name (defaults to 'System')
     * @return bool Success
     */
    public static function sendNoticeToSysop(string $subject, string $message, string $fromName = 'System'): bool
    {
        try {
            // Sanitize inputs to valid UTF-8
            $subject = self::sanitizeUtf8($subject);
            $message = self::sanitizeUtf8($message);
            $fromName = self::sanitizeUtf8($fromName);

            $db = Database::getInstance()->getPdo();

            // Get system configuration
            $binkpConfig = BinkpConfig::getInstance();
            $systemAddress = $binkpConfig->getSystemAddress();
            $sysopName = $binkpConfig->getSystemSysop();

            if (empty($sysopName)) {
                error_log("[SysopNotificationService] Sysop name not configured");
                return false;
            }

            // Find sysop user in database
            $stmt = $db->prepare("
                SELECT id FROM users
                WHERE LOWER(real_name) = LOWER(?) OR LOWER(username) = LOWER(?)
                LIMIT 1
            ");
            $stmt->execute([$sysopName, $sysopName]);
            $sysopUser = $stmt->fetch();

            if (!$sysopUser) {
                // Fallback to first admin user
                $stmt = $db->prepare("SELECT id FROM users WHERE is_admin = TRUE ORDER BY id LIMIT 1");
                $stmt->execute();
                $sysopUser = $stmt->fetch();

                if (!$sysopUser) {
                    error_log("[SysopNotificationService] No sysop or admin user found");
                    return false;
                }
            }

            // Insert netmail notification
            $stmt = $db->prepare("
                INSERT INTO netmail (
                    user_id, from_address, to_address, from_name, to_name,
                    subject, message_text, date_written, date_received, attributes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)
            ");

            $result = $stmt->execute([
                $sysopUser['id'],
                'System',
                $systemAddress,
                $fromName,
                $sysopName,
                $subject,
                $message,
                0
            ]);

            return $result;

        } catch (\Exception $e) {
            error_log("[SysopNotificationService] Error sending notice to sysop: " . $e->getMessage());
            return false;
        }
    }
}
