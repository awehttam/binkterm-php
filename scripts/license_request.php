#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Config;
use BinktermPHP\Mail;
use BinktermPHP\Binkp\Config\BinkpConfig;

/**
 * Prompt for input with an optional default value.
 *
 * @param string $prompt  The prompt text
 * @param string $default Pre-populated default value
 * @return string         The user's input, or the default if left blank
 */
function readInput(string $prompt, string $default = ''): string
{
    if ($default !== '') {
        echo "{$prompt} [{$default}]: ";
    } else {
        echo "{$prompt}: ";
    }

    $input = trim(fgets(STDIN));
    return $input !== '' ? $input : $default;
}

// Load system defaults from binkp config
$systemName  = '';
$sysopName   = '';
$sysopEmail  = '';
$ftnAddress  = '';

try {
    $binkpConfig = BinkpConfig::getInstance();
    $systemName  = $binkpConfig->getSystemName();
    $sysopName   = $binkpConfig->getSystemSysop();
    $ftnAddress  = $binkpConfig->getSystemAddress();
} catch (Exception $e) {
    // Proceed with empty defaults
}

// Fall back to SMTP from-email for the sysop email default
$sysopEmail = Config::env('SMTP_FROM_EMAIL', '');

echo "BinktermPHP License Request\n";
echo "===========================\n\n";

echo "BinktermPHP is open source and the community edition is fully functional —\n";
echo "registration is never required to run a BBS. But if BinktermPHP has been\n";
echo "valuable to your system, registering is the right thing to do. It keeps the\n";
echo "project alive and gives you some genuinely useful extras.\n\n";

echo "To register, visit: https://paypal.me/awehttam\n";
echo "Pay whatever feels right to you.\n";
echo "In the PayPal note, include the name of your BBS so your payment can be\n";
echo "matched to your license request.\n\n";

$paypalTxn = readInput('PayPal confirmation / transaction number');
if ($paypalTxn === '') {
    echo "A PayPal transaction number is required to process your license request.\n";
    exit(1);
}

echo "\nThis script will now send your license request to the BinktermPHP licensing team.\n\n";

// Prompt for each field, pre-populated from system config
$systemName = readInput('System Name', $systemName);
$sysopName  = readInput('Sysop Name',  $sysopName);
$sysopEmail = readInput('Email Address', $sysopEmail);
$ftnAddress = readInput('FidoNet Address', $ftnAddress);

// Confirm before sending
echo "\nThe following license request will be sent:\n";
echo "  System Name:      {$systemName}\n";
echo "  Sysop Name:       {$sysopName}\n";
echo "  Email Address:    {$sysopEmail}\n";
echo "  FidoNet Address:  {$ftnAddress}\n";
echo "  PayPal Txn:       {$paypalTxn}\n\n";

echo "Send license request? [Y/n]: ";
$confirm = strtolower(trim(fgets(STDIN)));
if ($confirm !== '' && $confirm !== 'y' && $confirm !== 'yes') {
    echo "Cancelled.\n";
    exit(0);
}

// Build the email
$mailer = new Mail();

if (!$mailer->isEnabled()) {
    echo "\nError: SMTP is not configured or not enabled.\n";
    echo "Please configure SMTP settings in your .env file (SMTP_ENABLED, SMTP_HOST, etc.) and try again.\n";
    exit(1);
}

$subject = "BinktermPHP License Request - {$systemName}";

$plainBody  = "BinktermPHP License Request\n";
$plainBody .= "===========================\n\n";
$plainBody .= "System Name:      {$systemName}\n";
$plainBody .= "Sysop Name:       {$sysopName}\n";
$plainBody .= "Email Address:    {$sysopEmail}\n";
$plainBody .= "FidoNet Address:  {$ftnAddress}\n";
$plainBody .= "PayPal Txn:       {$paypalTxn}\n";

$safeSystemName = htmlspecialchars($systemName, ENT_QUOTES, 'UTF-8');
$safeSysopName  = htmlspecialchars($sysopName,  ENT_QUOTES, 'UTF-8');
$safeSysopEmail = htmlspecialchars($sysopEmail, ENT_QUOTES, 'UTF-8');
$safeFtnAddress = htmlspecialchars($ftnAddress, ENT_QUOTES, 'UTF-8');
$safePaypalTxn  = htmlspecialchars($paypalTxn,  ENT_QUOTES, 'UTF-8');

$htmlBody = "
<html>
<head>
    <title>BinktermPHP License Request</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        table { border-collapse: collapse; margin-top: 10px; }
        td { padding: 6px 14px 6px 0; vertical-align: top; }
        td:first-child { font-weight: bold; white-space: nowrap; }
    </style>
</head>
<body>
    <h2>BinktermPHP License Request</h2>
    <table>
        <tr><td>System Name:</td><td>{$safeSystemName}</td></tr>
        <tr><td>Sysop Name:</td><td>{$safeSysopName}</td></tr>
        <tr><td>Email Address:</td><td>{$safeSysopEmail}</td></tr>
        <tr><td>FidoNet Address:</td><td>{$safeFtnAddress}</td></tr>
        <tr><td>PayPal Txn:</td><td>{$safePaypalTxn}</td></tr>
    </table>
</body>
</html>
";

$result = $mailer->sendMail('awehttam@gmail.com', $subject, $htmlBody, $plainBody);

if ($result) {
    echo "\nLicense request sent successfully.\n";
} else {
    echo "\nFailed to send license request. Check your SMTP configuration and try again.\n";
    exit(1);
}
