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

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class Mail
{
    public function isEnabled(): bool
    {
        return Config::env('SMTP_ENABLED', 'false') === 'true';
    }
    
    public function sendMail(string $to, string $subject, string $body, ?string $altBody = null): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        
        try {
            $mail = new PHPMailer(true);
            $mail->CharSet = PHPMailer::CHARSET_UTF8;

            // Server settings
            $mail->isSMTP();
            $mail->Host = Config::env('SMTP_HOST');
            $mail->SMTPAuth = true;
            $mail->Username = Config::env('SMTP_USER');
            $mail->Password = Config::env('SMTP_PASS');
            $mail->Port = (int) Config::env('SMTP_PORT', 587);

            if(Config::env('SMTP_NOVERIFYCERT')==true) {
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    ]
                ];
            }
            
            // Security settings
            $security = strtolower(Config::env('SMTP_SECURITY', 'tls'));
            if ($security === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($security === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }
            
            // Recipients
            $mail->setFrom(Config::env('SMTP_FROM_EMAIL'), Config::env('SMTP_FROM_NAME', 'BinktermPHP'));
            $mail->addAddress($to);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $body;
            
            if ($altBody) {
                $mail->AltBody = $altBody;
            }
            
            $mail->send();
            error_log("Sent Email to $to re: $subject");
            return true;
            
        } catch (Exception $e) {
            error_log("Mail sending failed: " . $mail->ErrorInfo);
            try {
                SysopNotificationService::sendNoticeToSysop(
                    'Email sending failure',
                    'An error occurred sending Email to '.$to.' re: '.$subject.': '.$mail->ErrorInfo,
                    'System',
                    false
                );
            }catch (Exception $ex){

            }

            return false;
        }
    }
    
    public function sendWelcomeEmail(string $email, string $username, string $realName): bool
    {
        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            $systemAddress = $binkpConfig->getSystemAddress();
            $sysopName = $binkpConfig->getSystemSysop();
            $systemName = $binkpConfig->getSystemName();
        } catch (\Exception $e) {
            $systemAddress = '1:123/456';
            $sysopName = 'Sysop';
            $systemName = 'BinktermPHP System';
        }

        $subject = "Welcome to $systemName!";
        
        // Load the same welcome template used for netmail
        $plainTextMessage = $this->loadWelcomeTemplate($realName, $systemName, $systemAddress, $sysopName);
        
        // Convert plain text to HTML for email
        $htmlMessage = $this->convertTextToHtml($plainTextMessage);
        
        return $this->sendMail($email, $subject, $htmlMessage, $plainTextMessage);
    }
    
    public function sendAccountReminder(string $email, string $username, string $realName): bool
    {
        try {
            $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
            $systemAddress = $binkpConfig->getSystemAddress();
            $sysopName = $binkpConfig->getSystemSysop();
            $systemName = $binkpConfig->getSystemName();
        } catch (\Exception $e) {
            $systemAddress = '1:123/456';
            $sysopName = 'Sysop';
            $systemName = 'BinktermPHP System';
        }

        $subject = "Account Reminder - $systemName";
        
        $plainTextMessage = "Hello $realName,\n\n";
        $plainTextMessage .= "This is a friendly reminder that your account on $systemName is ready to use!\n\n";
        $plainTextMessage .= "Account Details:\n";
        $plainTextMessage .= "===============\n";
        $plainTextMessage .= "Username: $username\n";
        $plainTextMessage .= "System: $systemName ($systemAddress)\n\n";
        $plainTextMessage .= "Getting Started:\n";
        $plainTextMessage .= "===============\n";
        $plainTextMessage .= "1. Visit the web interface and log in with your username\n";
        $plainTextMessage .= "2. Browse available echo areas for discussions\n";
        $plainTextMessage .= "3. Send and receive netmail (private messages)\n";
        $plainTextMessage .= "4. Customize your settings and preferences\n\n";
        $plainTextMessage .= "If you've forgotten your password or have any questions,\n";
        $plainTextMessage .= "please contact the sysop at $sysopName.\n\n";
        $plainTextMessage .= "Welcome to the community!";
        
        // Convert plain text to HTML for email
        $htmlMessage = $this->convertTextToHtml($plainTextMessage);
        
        return $this->sendMail($email, $subject, $htmlMessage, $plainTextMessage);
    }
    
    /**
     * Send a netmail forwarding email to the recipient's external email address.
     *
     * @param string $toEmail      Recipient email address
     * @param string $fromName     Sender display name (the FTN message author)
     * @param string $fromAddress  Sender FTN address (e.g. 1:123/456)
     * @param string $subject      Original netmail subject
     * @param string $messageText  Plain-text message body
     * @param string $systemName   Name of this BBS/system
     * @param array  $attachments  Optional file attachments; each entry is
     *                             ['path' => '/absolute/path', 'filename' => 'file.ext']
     * @return bool True on success, false on failure
     */
    public function sendNetmailForward(
        string $toEmail,
        string $fromName,
        string $fromAddress,
        string $subject,
        string $messageText,
        string $systemName,
        array $attachments = []
    ): bool {
        if (!$this->isEnabled()) {
            return false;
        }

        $emailSubject = "[Netmail] {$subject}";

        $fromLine  = $fromAddress !== '' ? "{$fromName} ({$fromAddress})" : $fromName;

        $plainText = "From: {$fromLine}\n"
            . "Subject: {$subject}\n"
            . "System: {$systemName}\n"
            . "---\n"
            . $messageText
            . "\n\n---\n"
            . "This message was forwarded automatically from {$systemName}.\n"
            . "Note: Replying to this email will not reach the sender. Please log in to {$systemName} to reply.";

        $safeFrom    = htmlspecialchars($fromLine, ENT_QUOTES, 'UTF-8');
        $safeBody    = nl2br(htmlspecialchars($messageText, ENT_QUOTES, 'UTF-8'));
        $safeSystem  = htmlspecialchars($systemName, ENT_QUOTES, 'UTF-8');
        $htmlBody = "
        <html>
        <head>
            <title>Netmail from {$safeFrom}</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .header { background: #f4f4f4; padding: 10px 15px; border-left: 4px solid #0066cc; margin-bottom: 15px; }
                .message-body { font-family: 'Courier New', monospace; white-space: pre-wrap; }
                hr { border: none; border-top: 1px solid #ccc; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='header'>
                <strong>Netmail from {$safeFrom}</strong><br>
                <small>System: {$safeSystem}</small>
            </div>
            <div class='message-body'>{$safeBody}</div>
            <hr>
            <small style='color: #666;'>
                This message was forwarded automatically from {$safeSystem}.<br>
                <strong>Note:</strong> Replying to this email will not reach the sender.
                Please log in to {$safeSystem} to reply.
            </small>
        </body>
        </html>
        ";

        try {
            $mail = new PHPMailer(true);
            $mail->CharSet = PHPMailer::CHARSET_UTF8;

            // Server settings
            $mail->isSMTP();
            $mail->Host     = Config::env('SMTP_HOST');
            $mail->SMTPAuth = true;
            $mail->Username = Config::env('SMTP_USER');
            $mail->Password = Config::env('SMTP_PASS');
            $mail->Port     = (int) Config::env('SMTP_PORT', 587);

            if (Config::env('SMTP_NOVERIFYCERT') == true) {
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer'       => false,
                        'verify_peer_name'  => false,
                        'allow_self_signed' => true,
                    ]
                ];
            }

            // Security settings
            $security = strtolower(Config::env('SMTP_SECURITY', 'tls'));
            if ($security === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($security === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            // Recipients
            $mail->setFrom(Config::env('SMTP_FROM_EMAIL'), Config::env('SMTP_FROM_NAME', 'BinktermPHP'));
            $mail->addAddress($toEmail);

            // Attachments
            foreach ($attachments as $att) {
                if (!empty($att['path']) && is_readable($att['path'])) {
                    $mail->addAttachment($att['path'], $att['filename'] ?? basename($att['path']));
                }
            }

            // Content
            $mail->isHTML(true);
            $mail->Subject = $emailSubject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = $plainText;

            $mail->send();
            error_log("[NETMAIL FORWARD] Forwarded netmail to {$toEmail} re: {$subject}");
            return true;

        } catch (Exception $e) {
            error_log("[NETMAIL FORWARD] Failed to forward netmail to {$toEmail}: " . $mail->ErrorInfo);
            return false;
        }
    }

    /**
     * Forward a received netmail to the recipient's email if forwarding is enabled,
     * the system is licensed, and the user has an email address configured.
     *
     * @param int    $recipientUserId  The local user who received the netmail
     * @param string $fromName         Sender display name
     * @param string $fromAddress      Sender FTN address (e.g. 1:123/456)
     * @param string $subject          Message subject
     * @param string $messageText      Plain-text message body
     * @param array  $attachments      Optional list of ['path'=>..., 'filename'=>...] to attach
     */
    public static function maybeForwardNetmail(
        int $recipientUserId,
        string $fromName,
        string $fromAddress,
        string $subject,
        string $messageText,
        array $attachments = []
    ): void {
        try {
            if (!License::isValid()) {
                return;
            }

            $db = Database::getInstance()->getPdo();

            $stmt = $db->prepare(
                "SELECT u.email, us.forward_netmail_email
                 FROM users u
                 LEFT JOIN user_settings us ON us.user_id = u.id
                 WHERE u.id = ?"
            );
            $stmt->execute([$recipientUserId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row || empty($row['email']) || !$row['forward_netmail_email']) {
                return;
            }

            $systemName = 'BinktermPHP System';
            try {
                $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
                $systemName = $binkpConfig->getSystemName();
            } catch (\Exception $e) {
                // Use default system name
            }

            $mail = new self();
            $mail->sendNetmailForward(
                $row['email'],
                $fromName,
                $fromAddress,
                $subject,
                $messageText,
                $systemName,
                $attachments
            );

        } catch (\Exception $e) {
            error_log("[NETMAIL FORWARD] Error in maybeForwardNetmail for user {$recipientUserId}: " . $e->getMessage());
        }
    }

    private function loadWelcomeTemplate($realName, $systemName, $systemAddress, $sysopName)
    {
        $welcomeFile = __DIR__ . '/../config/newuser_welcome.txt';
        
        // Fallback message if template file doesn't exist (same as MessageHandler)
        $defaultMessage = "Welcome to $systemName, $realName!\n\n";
        $defaultMessage .= "Your user account has been approved and is now active.\n";
        $defaultMessage .= "You can now participate in all available echoareas and send netmail.\n\n";
        $defaultMessage .= "System Information:\n";
        $defaultMessage .= "==================\n";
        $defaultMessage .= "System Name: $systemName\n";
        $defaultMessage .= "System Address: $systemAddress\n";
        $defaultMessage .= "Sysop: $sysopName\n\n";
        $defaultMessage .= "Getting Started:\n";
        $defaultMessage .= "===============\n";
        $defaultMessage .= "- Visit the Echomail section to browse available discussion areas\n";
        $defaultMessage .= "- Use the Netmail section to send private messages\n";
        $defaultMessage .= "- Check your Settings to customize your experience\n\n";
        $defaultMessage .= "If you have any questions, feel free to send netmail to the sysop.\n\n";
        $defaultMessage .= "Welcome to the community!";
        
        if (!file_exists($welcomeFile)) {
            return $defaultMessage;
        }
        
        $template = file_get_contents($welcomeFile);
        if ($template === false) {
            return $defaultMessage;
        }
        
        // Perform variable substitutions (same as MessageHandler)
        $replacements = [
            '{REAL_NAME}' => $realName,
            '{SYSTEM_NAME}' => $systemName,
            '{SYSTEM_ADDRESS}' => $systemAddress,
            '{SYSOP_NAME}' => $sysopName,
            '{real_name}' => $realName,
            '{system_name}' => $systemName,
            '{system_address}' => $systemAddress,
            '{sysop_name}' => $sysopName
        ];
        
        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }
    
    private function convertTextToHtml($text): string
    {
        // Convert plain text to basic HTML
        $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        
        // Convert line breaks to HTML
        $html = nl2br($html);
        
        // Basic formatting for sections
        $html = preg_replace('/^([A-Za-z\s]+:)\s*$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^(=+)$/m', '<hr>', $html);
        
        // Wrap in basic HTML structure
        return "
        <html>
        <head>
            <title>Welcome Message</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                h3 { color: #0066cc; margin-top: 20px; margin-bottom: 10px; }
                hr { border: none; border-top: 1px solid #ccc; margin: 15px 0; }
            </style>
        </head>
        <body>
            $html
            <hr>
            <small style='color: #666;'>This message was sent automatically from the BinktermPHP system.</small>
        </body>
        </html>
        ";
    }
}
