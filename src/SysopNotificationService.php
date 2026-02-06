<?php

/*
 * Copright Matthew Asham and BinktermPHP Contributors
 * 
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the 
 * following conditions are met:
 * 
 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
 * Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE
 * 
 */

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
        if (empty($str)) {
            return '';
        }

        // Remove invalid UTF-8 sequences by encoding to UTF-8
        $str = mb_convert_encoding($str, 'UTF-8', 'UTF-8');

        // Remove NULL bytes and other control characters except tab, newline, carriage return
        $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $str);

        return $str;
    }

    /**
     * Send a netmail notification to the sysop
     *
     * @param string $subject Message subject
     * @param string $message Message body
     * @param string $fromName Sender name (defaults to 'System')
     * @return bool Success
     */
    public static function sendNoticeToSysop(string $subject, string $message, string $fromName = 'System', $email=true): bool
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
            $systemName = $binkpConfig->getSystemName();

            if (empty($sysopName)) {
                error_log("[SysopNotificationService] Sysop name not configured");
                return false;
            }

            // Find sysop user in database
            $stmt = $db->prepare("
                SELECT id,email FROM users
                WHERE LOWER(real_name) = LOWER(?) OR LOWER(username) = LOWER(?)
                LIMIT 1
            ");
            $stmt->execute([$sysopName, $sysopName]);
            $sysopUser = $stmt->fetch();

            if (!$sysopUser) {
                // Fallback to first admin user
                $stmt = $db->prepare("SELECT id,email FROM users WHERE is_admin = TRUE ORDER BY id LIMIT 1");
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

            if($email){
                // Get sysop email address
                $emailStmt = $db->prepare("SELECT email FROM users WHERE id = ?");
                $emailStmt->execute([$sysopUser['id']]);
                $sysopEmail = $emailStmt->fetchColumn();

                if (!empty($sysopEmail)) {
                    $emailSubject = "[$systemName Notification] " . $subject;
                    $plainBody = "From: " . $fromName . "\n\n" . $message;

                    // Convert plain text to HTML
                    $htmlBody = nl2br(htmlspecialchars($plainBody, ENT_QUOTES, 'UTF-8'));

                    // Send email notification using Mail class
                    $mailer = new Mail();
                    $mailer->sendMail($sysopEmail, $emailSubject, $htmlBody, $plainBody);
                }
            }
            return $result;

        } catch (\Exception $e) {
            error_log("[SysopNotificationService] Error sending notice to sysop: " . $e->getMessage());
            return false;
        }
    }
}

