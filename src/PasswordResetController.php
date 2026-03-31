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


namespace BinktermPHP;

class PasswordResetController
{
    private $db;
    private const TOKEN_EXPIRY_HOURS = 24;

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
    }

    /**
     * Request a password reset token
     *
     * @param string $usernameOrEmail Username or email address
     * @return array Result with success status and message
     */
    public function requestPasswordReset($usernameOrEmail)
    {
        // Find user by username or email
        $stmt = $this->db->prepare('
            SELECT u.id, u.username, u.email, u.real_name, u.is_active, us.locale
            FROM users u
            LEFT JOIN user_settings us ON us.user_id = u.id
            WHERE (u.username = ? OR u.email = ?) AND u.is_active = TRUE
        ');
        $stmt->execute([$usernameOrEmail, $usernameOrEmail]);
        $user = $stmt->fetch();

        // Always return success message for security (don't reveal if user exists)
        if (!$user || empty($user['email'])) {
            return [
                'success' => true,
                'message_code' => 'ui.forgot_password.reset_link_sent_if_exists'
            ];
        }

        // Generate secure random token
        $token = bin2hex(random_bytes(32));

        // Calculate expiration time
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . self::TOKEN_EXPIRY_HOURS . ' hours'));

        // Invalidate any existing unused tokens for this user
        $this->invalidateUserTokens($user['id']);

        // Store token in database
        $stmt = $this->db->prepare('
            INSERT INTO password_reset_tokens
            (user_id, token, expires_at, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?)
        ');

        $stmt->execute([
            $user['id'],
            $token,
            $expiresAt,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);

        // Send reset email
        $this->sendPasswordResetEmail($user, $token);

        return [
            'success' => true,
            'message_code' => 'ui.forgot_password.reset_link_sent_if_exists'
        ];
    }

    /**
     * Validate a password reset token
     *
     * @param string $token Reset token
     * @return array|false User data if valid, false otherwise
     */
    public function validateToken($token)
    {
        $stmt = $this->db->prepare('
            SELECT
                prt.id as token_id,
                prt.user_id,
                prt.expires_at,
                prt.used_at,
                u.username,
                u.email,
                u.real_name,
                u.is_active
            FROM password_reset_tokens prt
            JOIN users u ON prt.user_id = u.id
            WHERE prt.token = ? AND u.is_active = TRUE
        ');

        $stmt->execute([$token]);
        $result = $stmt->fetch();

        if (!$result) {
            return false;
        }

        // Check if token has been used
        if ($result['used_at']) {
            return false;
        }

        // Check if token has expired
        if (strtotime($result['expires_at']) < time()) {
            return false;
        }

        return $result;
    }

    /**
     * Reset password using a valid token
     *
     * @param string $token Reset token
     * @param string $newPassword New password
     * @return array Result with success status and message
     */
    public function resetPassword($token, $newPassword)
    {
        // Validate token
        $tokenData = $this->validateToken($token);

        if (!$tokenData) {
            return [
                'success' => false,
                'error_code' => 'errors.auth.invalid_or_expired_token',
                'error' => 'Invalid or expired token'
            ];
        }

        // Validate password strength
        if (strlen($newPassword) < 8) {
            return [
                'success' => false,
                'error_code' => 'errors.auth.weak_password',
                'error' => 'Password must be at least 8 characters long'
            ];
        }

        // Hash the new password
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

        try {
            $this->db->beginTransaction();

            // Update user password
            $stmt = $this->db->prepare('
                UPDATE users
                SET password_hash = ?, last_login = NULL
                WHERE id = ?
            ');
            $stmt->execute([$passwordHash, $tokenData['user_id']]);

            // Mark token as used
            $stmt = $this->db->prepare('
                UPDATE password_reset_tokens
                SET used_at = NOW() AT TIME ZONE \'UTC\'
                WHERE id = ?
            ');
            $stmt->execute([$tokenData['token_id']]);

            // Invalidate all user sessions for security
            $stmt = $this->db->prepare('
                DELETE FROM user_sessions WHERE user_id = ?
            ');
            $stmt->execute([$tokenData['user_id']]);

            $this->db->commit();

            return [
                'success' => true,
                'message_code' => 'ui.reset_password.success_reset_complete',
                'username' => $tokenData['username']
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
            getServerLogger()->error("Password reset failed: " . $e->getMessage());

            return [
                'success' => false,
                'error_code' => 'errors.auth.reset_failed',
                'error' => 'Failed to reset password'
            ];
        }
    }

    /**
     * Invalidate all unused tokens for a user
     *
     * @param int $userId User ID
     */
    private function invalidateUserTokens($userId)
    {
        $stmt = $this->db->prepare('
            DELETE FROM password_reset_tokens
            WHERE user_id = ? AND used_at IS NULL
        ');
        $stmt->execute([$userId]);
    }

    /**
     * Clean up expired tokens (should be called periodically)
     */
    public function cleanExpiredTokens()
    {
        $stmt = $this->db->prepare('
            DELETE FROM password_reset_tokens
            WHERE expires_at < NOW()
        ');
        $stmt->execute();
    }

    /**
     * Send password reset email
     *
     * @param array $user User data
     * @param string $token Reset token
     */
    private function sendPasswordResetEmail($user, $token)
    {
        $mail = new Mail();

        if (!$mail->isEnabled()) {
            getServerLogger()->warning("Password reset requested but email is not configured");
            return;
        }

        // Get system information
        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            $systemName = $binkpConfig->getSystemName();
        } catch (\Exception $e) {
            $systemName = 'BinktermPHP System';
        }

        // Build reset URL
        $resetUrl = Config::getSiteUrl() . '/reset-password?token=' . $token;
        $translator = new \BinktermPHP\I18n\Translator();
        $localeResolver = new \BinktermPHP\I18n\LocaleResolver($translator);
        // Resolve locale using the same priority as page rendering:
        // 1) user's saved locale preference, 2) cookie, 3) Accept-Language, 4) default
        $locale = $localeResolver->resolveLocale($user['locale'] ?? null, null);

        $t = static function (string $key, array $params = []) use ($translator, $locale): string {
            return $translator->translate($key, $params, $locale, ['common']);
        };

        $subject = $t('ui.password_reset_email.subject', ['system_name' => $systemName]);

        // Plain text version
        $plainText = $t('ui.password_reset_email.greeting', ['name' => (string)$user['real_name']]) . "\n\n";
        $plainText .= $t('ui.password_reset_email.request_received', ['system_name' => $systemName]) . "\n\n";
        $plainText .= $t('ui.password_reset_email.click_link_below') . "\n";
        $plainText .= $resetUrl . "\n\n";
        $plainText .= $t('ui.password_reset_email.expires_in_hours', ['hours' => self::TOKEN_EXPIRY_HOURS]) . "\n\n";
        $plainText .= $t('ui.password_reset_email.if_not_requested') . "\n";
        $plainText .= $t('ui.password_reset_email.password_unchanged_notice') . "\n\n";
        $plainText .= $t('ui.password_reset_email.security_notes') . "\n";
        $plainText .= "- " . $t('ui.password_reset_email.note_never_share') . "\n";
        $plainText .= "- " . $t('ui.password_reset_email.note_one_time') . "\n";
        $plainText .= "- " . $t('ui.password_reset_email.note_request_new') . "\n\n";
        $plainText .= $t('ui.password_reset_email.best_regards') . "\n";
        $plainText .= $systemName;

        $headerText = htmlspecialchars($t('ui.password_reset_email.header'), ENT_QUOTES, 'UTF-8');
        $greetingText = htmlspecialchars($t('ui.password_reset_email.greeting', ['name' => (string)$user['real_name']]), ENT_QUOTES, 'UTF-8');
        $requestReceivedText = htmlspecialchars($t('ui.password_reset_email.request_received', ['system_name' => $systemName]), ENT_QUOTES, 'UTF-8');
        $clickLinkText = htmlspecialchars($t('ui.password_reset_email.click_button_below'), ENT_QUOTES, 'UTF-8');
        $buttonText = htmlspecialchars($t('ui.password_reset_email.button'), ENT_QUOTES, 'UTF-8');
        $copyLinkText = htmlspecialchars($t('ui.password_reset_email.copy_link'), ENT_QUOTES, 'UTF-8');
        $expiresText = htmlspecialchars($t('ui.password_reset_email.expires_in_hours', ['hours' => self::TOKEN_EXPIRY_HOURS]), ENT_QUOTES, 'UTF-8');
        $securityNotesText = htmlspecialchars($t('ui.password_reset_email.security_notes'), ENT_QUOTES, 'UTF-8');
        $noteNeverShareText = htmlspecialchars($t('ui.password_reset_email.note_never_share'), ENT_QUOTES, 'UTF-8');
        $noteOneTimeText = htmlspecialchars($t('ui.password_reset_email.note_one_time'), ENT_QUOTES, 'UTF-8');
        $noteRequestNewText = htmlspecialchars($t('ui.password_reset_email.note_request_new'), ENT_QUOTES, 'UTF-8');
        $ifNotRequestedText = htmlspecialchars($t('ui.password_reset_email.if_not_requested'), ENT_QUOTES, 'UTF-8');
        $passwordUnchangedText = htmlspecialchars($t('ui.password_reset_email.password_unchanged_notice'), ENT_QUOTES, 'UTF-8');
        $footerAutomatedText = htmlspecialchars($t('ui.password_reset_email.footer_automated', ['system_name' => $systemName]), ENT_QUOTES, 'UTF-8');
        $footerNoReplyText = htmlspecialchars($t('ui.password_reset_email.footer_no_reply'), ENT_QUOTES, 'UTF-8');

        // HTML version
        $htmlText = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #0066cc; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background-color: #f9f9f9; }
                .button {
                    display: inline-block;
                    padding: 12px 24px;
                    background-color: #0066cc;
                    color: white;
                    text-decoration: none;
                    border-radius: 4px;
                    margin: 20px 0;
                }
                .security-notes {
                    background-color: #fff3cd;
                    border-left: 4px solid #ffc107;
                    padding: 12px;
                    margin: 20px 0;
                }
                .footer {
                    text-align: center;
                    color: #666;
                    font-size: 12px;
                    margin-top: 20px;
                    padding-top: 20px;
                    border-top: 1px solid #ddd;
                }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>{$headerText}</h2>
                </div>
                <div class='content'>
                    <p>{$greetingText}</p>

                    <p>{$requestReceivedText}</p>

                    <p>{$clickLinkText}</p>

                    <p style='text-align: center;'>
                        <a href='$resetUrl' class='button'>{$buttonText}</a>
                    </p>

                    <p>{$copyLinkText}</p>
                    <p style='word-break: break-all; background-color: white; padding: 10px; border: 1px solid #ddd;'>
                        $resetUrl
                    </p>

                    <p><strong>{$expiresText}</strong></p>

                    <div class='security-notes'>
                        <strong>{$securityNotesText}</strong>
                        <ul>
                            <li>{$noteNeverShareText}</li>
                            <li>{$noteOneTimeText}</li>
                            <li>{$noteRequestNewText}</li>
                        </ul>
                    </div>

                    <p>{$ifNotRequestedText}<br>{$passwordUnchangedText}</p>
                </div>
                <div class='footer'>
                    <p>{$footerAutomatedText}<br>
                    {$footerNoReplyText}</p>
                </div>
            </div>
        </body>
        </html>
        ";

        $mail->sendMail($user['email'], $subject, $htmlText, $plainText);
    }
}

