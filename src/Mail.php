<?php

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
            
            // Server settings
            $mail->isSMTP();
            $mail->Host = Config::env('SMTP_HOST');
            $mail->SMTPAuth = true;
            $mail->Username = Config::env('SMTP_USER');
            $mail->Password = Config::env('SMTP_PASS');
            $mail->Port = (int) Config::env('SMTP_PORT', 587);
            
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
            return true;
            
        } catch (Exception $e) {
            error_log("Mail sending failed: " . $mail->ErrorInfo);
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
        $plainTextMessage .= "Welcome to the FidoNet community!";
        
        // Convert plain text to HTML for email
        $htmlMessage = $this->convertTextToHtml($plainTextMessage);
        
        return $this->sendMail($email, $subject, $htmlMessage, $plainTextMessage);
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
        $defaultMessage .= "Welcome to the FidoNet community!";
        
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