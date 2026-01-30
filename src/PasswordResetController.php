<?php

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
            SELECT id, username, email, real_name, is_active
            FROM users
            WHERE (username = ? OR email = ?) AND is_active = TRUE
        ');
        $stmt->execute([$usernameOrEmail, $usernameOrEmail]);
        $user = $stmt->fetch();

        // Always return success message for security (don't reveal if user exists)
        if (!$user || empty($user['email'])) {
            return [
                'success' => true,
                'message' => 'If an account with that username or email exists, a password reset link has been sent.'
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
            'message' => 'If an account with that username or email exists, a password reset link has been sent.'
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
                'message' => 'Invalid or expired reset token.'
            ];
        }

        // Validate password strength
        if (strlen($newPassword) < 8) {
            return [
                'success' => false,
                'message' => 'Password must be at least 8 characters long.'
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
                'message' => 'Password has been reset successfully. You can now log in with your new password.',
                'username' => $tokenData['username']
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log("Password reset failed: " . $e->getMessage());

            return [
                'success' => false,
                'message' => 'Failed to reset password. Please try again.'
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
            error_log("Password reset requested but email is not configured");
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

        $subject = "Password Reset Request - $systemName";

        // Plain text version
        $plainText = "Hello {$user['real_name']},\n\n";
        $plainText .= "We received a request to reset your password for your account on $systemName.\n\n";
        $plainText .= "To reset your password, please click the link below:\n";
        $plainText .= "$resetUrl\n\n";
        $plainText .= "This link will expire in " . self::TOKEN_EXPIRY_HOURS . " hours.\n\n";
        $plainText .= "If you did not request a password reset, you can safely ignore this email.\n";
        $plainText .= "Your password will not be changed unless you click the link above and create a new password.\n\n";
        $plainText .= "For security reasons:\n";
        $plainText .= "- Never share this link with anyone\n";
        $plainText .= "- This link can only be used once\n";
        $plainText .= "- If you need another reset link, request a new one from the login page\n\n";
        $plainText .= "Best regards,\n";
        $plainText .= "$systemName";

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
                    <h2>Password Reset Request</h2>
                </div>
                <div class='content'>
                    <p>Hello <strong>{$user['real_name']}</strong>,</p>

                    <p>We received a request to reset your password for your account on <strong>$systemName</strong>.</p>

                    <p>To reset your password, please click the button below:</p>

                    <p style='text-align: center;'>
                        <a href='$resetUrl' class='button'>Reset Your Password</a>
                    </p>

                    <p>Or copy and paste this link into your browser:</p>
                    <p style='word-break: break-all; background-color: white; padding: 10px; border: 1px solid #ddd;'>
                        $resetUrl
                    </p>

                    <p><strong>This link will expire in " . self::TOKEN_EXPIRY_HOURS . " hours.</strong></p>

                    <div class='security-notes'>
                        <strong>Security Notes:</strong>
                        <ul>
                            <li>Never share this link with anyone</li>
                            <li>This link can only be used once</li>
                            <li>If you need another reset link, request a new one from the login page</li>
                        </ul>
                    </div>

                    <p>If you did not request a password reset, you can safely ignore this email.
                    Your password will not be changed unless you click the link above and create a new password.</p>
                </div>
                <div class='footer'>
                    <p>This is an automated message from $systemName<br>
                    Please do not reply to this email.</p>
                </div>
            </div>
        </body>
        </html>
        ";

        $mail->sendMail($user['email'], $subject, $htmlText, $plainText);
    }
}
