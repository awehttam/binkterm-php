#!/usr/bin/env php
<?php
/**
 * LovlyNet Automatic Network Registration Tool
 *
 * Registers this BBS with the LovlyNet registry, receives an FTN address,
 * configures the uplink, creates echo areas, and sends an areafix request.
 *
 * Usage:
 *   php lovlynet_setup.php                  # Interactive registration
 *   php lovlynet_setup.php --status         # Show current registration status
 *   php lovlynet_setup.php --update         # Re-register / update existing registration
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Config;
use BinktermPHP\Database;
use BinktermPHP\MessageHandler;
use BinktermPHP\Version;

define('LOVLYNET_REGISTRY_URL', 'https://lovlynet.lovelybits.org/api/register');
define('LOVLYNET_CONFIG_PATH', __DIR__ . '/../config/lovlynet.json');
define('LOVLYNET_DOMAIN', 'lovlynet');

/**
 * Read a line of input from the user with an optional default value.
 *
 * @param string $prompt The prompt text
 * @param string $default Default value shown in brackets
 * @return string The user's input or the default
 */
function readInput($prompt, $default = '') {
    if ($default !== '') {
        echo "{$prompt} [{$default}]: ";
    } else {
        echo "{$prompt}: ";
    }

    $input = trim(fgets(STDIN));
    return ($input !== '') ? $input : $default;
}

/**
 * Read a yes/no confirmation from the user.
 *
 * @param string $prompt The question to ask
 * @param bool $default Default answer (true = yes)
 * @return bool
 */
function confirm($prompt, $default = true) {
    $hint = $default ? 'Y/n' : 'y/N';
    echo "{$prompt} [{$hint}]: ";
    $input = strtolower(trim(fgets(STDIN)));

    if ($input === '') {
        return $default;
    }

    return in_array($input, ['y', 'yes']);
}

/**
 * Load existing LovlyNet registration config.
 *
 * @return array|null The config array or null if not registered
 */
function loadLovlyNetConfig() {
    if (!file_exists(LOVLYNET_CONFIG_PATH)) {
        return null;
    }

    $json = file_get_contents(LOVLYNET_CONFIG_PATH);
    $config = json_decode($json, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }

    return $config;
}

/**
 * Save LovlyNet registration config.
 *
 * @param array $config The config to save
 * @return bool
 */
function saveLovlyNetConfig($config) {
    $configDir = dirname(LOVLYNET_CONFIG_PATH);
    if (!is_dir($configDir)) {
        mkdir($configDir, 0755, true);
    }

    return file_put_contents(
        LOVLYNET_CONFIG_PATH,
        json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    ) !== false;
}

/**
 * Show current registration status.
 */
function showStatus() {
    $config = loadLovlyNetConfig();

    if (!$config) {
        echo "Not registered with LovlyNet.\n";
        echo "Run 'php lovlynet_setup.php' to register.\n";
        return;
    }

    echo "\nLovlyNet Registration Status\n";
    echo str_repeat("=", 40) . "\n";
    echo "FTN Address:   {$config['ftn_address']}\n";
    echo "Hub Address:   {$config['hub_address']}\n";
    echo "Registered:    {$config['registered_at']}\n";

    if (!empty($config['updated_at'])) {
        echo "Last Updated:  {$config['updated_at']}\n";
    }

    echo "\nAPI Key:       " . substr($config['api_key'], 0, 8) . "..." . substr($config['api_key'], -4) . "\n";
    echo "\n";
}

/**
 * Register or update this BBS with the LovlyNet registry.
 *
 * @param bool $isUpdate Whether this is an update of an existing registration
 */
function doRegistration($isUpdate = false) {
    $binkpConfig = BinkpConfig::getInstance();
    $existingConfig = loadLovlyNetConfig();

    echo "\n";
    echo "============================================\n";
    echo "  LovlyNet Network Registration\n";
    echo "  Zone 227 - Powered by BinktermPHP\n";
    echo "============================================\n\n";

    if ($existingConfig && !$isUpdate) {
        echo "You are already registered with LovlyNet.\n";
        echo "FTN Address: {$existingConfig['ftn_address']}\n\n";

        if (!confirm("Would you like to update your registration?")) {
            echo "Registration cancelled.\n";
            return;
        }
        $isUpdate = true;
    }

    if ($isUpdate && !$existingConfig) {
        echo "No existing registration found. Proceeding with new registration.\n\n";
        $isUpdate = false;
    }

    // Gather information
    $systemName = $binkpConfig->getSystemName();
    $sysopName = $binkpConfig->getSystemSysop();
    $hostname = $binkpConfig->getSystemHostname();
    $binkpPort = $binkpConfig->getBinkpPort();

    // Try to get site URL - works in CLI context if SITE_URL env var is set
    $defaultSiteUrl = '';
    $siteUrl = getenv('SITE_URL');
    if ($siteUrl) {
        $defaultSiteUrl = rtrim($siteUrl, '/');
    }

    echo "Please verify the following information:\n\n";

    $systemName = readInput("System Name", $systemName);
    $sysopName = readInput("Sysop Name", $sysopName);
    $hostname = readInput("Hostname (for binkp connections)", $hostname);
    $binkpPort = (int)readInput("Binkp Port", (string)$binkpPort);

    $siteUrl = readInput("Site URL (public web address of your BBS)", $defaultSiteUrl);

    if (empty($siteUrl)) {
        echo "\nError: Site URL is required for registration verification.\n";
        echo "The LovlyNet registry will call {site_url}/api/verify to confirm ownership.\n";
        echo "Set the SITE_URL environment variable or provide it here.\n";
        return;
    }

    // Verify the /api/verify endpoint is accessible locally before attempting registration
    echo "\nVerifying local /api/verify endpoint... ";
    $verifyUrl = rtrim($siteUrl, '/') . '/api/verify';
    $context = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => 10, 'ignore_errors' => true],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ]);
    $response = @file_get_contents($verifyUrl, false, $context);

    if ($response === false) {
        echo "FAILED\n\n";
        echo "Could not reach {$verifyUrl}\n";
        echo "Make sure your BBS web server is running and accessible.\n";
        echo "The registry needs to verify your site to complete registration.\n\n";

        if (!confirm("Continue anyway?", false)) {
            return;
        }
    } else {
        $verifyData = json_decode($response, true);
        if (isset($verifyData['system_name'])) {
            echo "OK ({$verifyData['system_name']})\n";
        } else {
            echo "WARNING: Unexpected response format\n";
        }
    }

    echo "\n";
    echo "Registration Summary:\n";
    echo "  System Name:  {$systemName}\n";
    echo "  Sysop:        {$sysopName}\n";
    echo "  Hostname:     {$hostname}\n";
    echo "  Binkp Port:   {$binkpPort}\n";
    echo "  Site URL:     {$siteUrl}\n";
    echo "  Software:     " . Version::getFullVersion() . "\n";
    echo "\n";

    if (!confirm("Proceed with registration?")) {
        echo "Registration cancelled.\n";
        return;
    }

    // Build request payload
    $payload = [
        'system_name' => $systemName,
        'hostname' => $hostname,
        'site_url' => $siteUrl,
        'sysop_name' => $sysopName,
        'binkp_port' => $binkpPort,
        'system_info' => [
            'software' => Version::getFullVersion(),
            'php_version' => PHP_VERSION
        ]
    ];

    // Build HTTP headers
    $headers = ['Content-Type: application/json'];

    if ($isUpdate && $existingConfig) {
        $headers[] = 'X-API-Key: ' . $existingConfig['api_key'];
    }

    echo "\nRegistering with LovlyNet... ";

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => json_encode($payload),
            'timeout' => 30,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true
        ]
    ]);

    $response = @file_get_contents(LOVLYNET_REGISTRY_URL, false, $context);

    if ($response === false) {
        echo "FAILED\n\n";
        echo "Could not connect to the LovlyNet registry at:\n";
        echo "  " . LOVLYNET_REGISTRY_URL . "\n\n";
        echo "Check your internet connection and try again.\n";
        return;
    }

    $result = json_decode($response, true);

    if (!$result || !$result['success']) {
        echo "FAILED\n\n";
        echo "Registry error: " . ($result['message'] ?? 'Unknown error') . "\n";
        return;
    }

    echo "OK\n\n";

    $regData = $result['data'];

    echo "Registration successful!\n";
    echo str_repeat("=", 40) . "\n";
    echo "FTN Address:     {$regData['ftn_address']}\n";
    echo "Hub Address:     {$regData['hub_address']}\n";
    echo "Hub Hostname:    {$regData['hub_hostname']}\n";
    echo "Hub Port:        {$regData['hub_port']}\n";
    echo "Binkp Password:  {$regData['binkp_password']}\n";

    if (!empty($regData['api_key'])) {
        echo "API Key:         " . substr($regData['api_key'], 0, 8) . "...\n";
    }
    echo "\n";

    // Save registration config
    $lovlyNetConfig = [
        'api_key' => $regData['api_key'] ?? ($existingConfig['api_key'] ?? ''),
        'ftn_address' => $regData['ftn_address'],
        'hub_address' => $regData['hub_address'],
        'hub_hostname' => $regData['hub_hostname'],
        'hub_port' => $regData['hub_port'],
        'binkp_password' => $regData['binkp_password'],
        'registered_at' => $existingConfig['registered_at'] ?? date('c'),
        'updated_at' => date('c')
    ];

    echo "Saving registration to config/lovlynet.json... ";
    if (saveLovlyNetConfig($lovlyNetConfig)) {
        echo "OK\n";
    } else {
        echo "FAILED\n";
        echo "Warning: Could not save registration config. You may need to re-register.\n";
    }

    // Configure binkp uplink
    echo "Configuring LovlyNet uplink... ";

    try {
        $existingUplink = $binkpConfig->getUplinkByDomain(LOVLYNET_DOMAIN);

        if ($existingUplink) {
            // Update existing uplink
            $binkpConfig->updateUplink($existingUplink['address'], [
                'me' => $regData['ftn_address'],
                'address' => $regData['hub_address'],
                'hostname' => $regData['hub_hostname'],
                'port' => $regData['hub_port'],
                'password' => $regData['binkp_password'],
                'domain' => LOVLYNET_DOMAIN,
                'networks' => ['227:*/*'],
                'enabled' => true,
                'compression' => false,
                'crypt' => false,
                'poll_schedule' => '*/15 * * * *'
            ]);
        } else {
            // Add new uplink
            $binkpConfig->addUplink(
                $regData['hub_address'],
                $regData['hub_hostname'],
                $regData['hub_port'],
                $regData['binkp_password'],
                [
                    'me' => $regData['ftn_address'],
                    'domain' => LOVLYNET_DOMAIN,
                    'networks' => ['227:*/*'],
                    'compression' => false,
                    'crypt' => false,
                    'poll_schedule' => '*/15 * * * *'
                ]
            );
        }
        echo "OK\n";
    } catch (\Exception $e) {
        echo "FAILED\n";
        echo "Error: " . $e->getMessage() . "\n";
        echo "You may need to manually configure the uplink in config/binkp.json\n";
    }

    // Create echo areas in the database
    $echoAreas = $regData['echoareas'] ?? [];

    if (!empty($echoAreas)) {
        echo "Creating LovlyNet echo areas... ";

        try {
            $db = Database::getInstance()->getPdo();
            $created = 0;
            $skipped = 0;

            foreach ($echoAreas as $area) {
                $tag = $area['tag'];
                $description = $area['description'] ?? '';

                // Check if already exists
                $stmt = $db->prepare("SELECT id FROM echoareas WHERE tag = ? AND domain = ?");
                $stmt->execute([$tag, LOVLYNET_DOMAIN]);

                if ($stmt->fetch()) {
                    $skipped++;
                    continue;
                }

                // Insert the echo area
                $stmt = $db->prepare("
                    INSERT INTO echoareas (tag, description, domain, uplink_address, is_active, is_default_subscription)
                    VALUES (?, ?, ?, ?, TRUE, TRUE)
                ");
                $stmt->execute([$tag, $description, LOVLYNET_DOMAIN, $regData['hub_address']]);
                $created++;
            }

            echo "OK ({$created} created, {$skipped} already existed)\n";
        } catch (\Exception $e) {
            echo "FAILED\n";
            echo "Error: " . $e->getMessage() . "\n";
            echo "You may need to create echo areas manually.\n";
        }
    }

    // Send areafix netmail to hub
    echo "Sending areafix request to hub... ";

    try {
        $db = Database::getInstance()->getPdo();

        // Find the sysop user (admin) to send from
        $stmt = $db->prepare("
            SELECT id FROM users
            WHERE LOWER(real_name) = LOWER(?) OR LOWER(username) = LOWER(?)
            LIMIT 1
        ");
        $stmt->execute([$sysopName, $sysopName]);
        $sysopUser = $stmt->fetch();

        if (!$sysopUser) {
            // Fall back to first admin user
            $stmt = $db->prepare("SELECT id FROM users WHERE is_admin = TRUE ORDER BY id LIMIT 1");
            $stmt->execute();
            $sysopUser = $stmt->fetch();
        }

        if (!$sysopUser) {
            echo "SKIPPED (no sysop user found)\n";
            echo "Create an admin user first, then send areafix manually.\n";
        } else {
            $messageHandler = new MessageHandler();
            $messageHandler->sendNetmail(
                $sysopUser['id'],
                $regData['hub_address'],
                'AreaFix',
                'LovlyNet Area Request',
                "%HELP\n",
                $sysopName
            );
            echo "OK\n";
        }
    } catch (\Exception $e) {
        echo "SKIPPED\n";
        echo "Note: " . $e->getMessage() . "\n";
        echo "You can send an areafix netmail manually later.\n";
    }

    echo "\n";
    echo str_repeat("=", 50) . "\n";
    echo "LovlyNet setup complete!\n\n";
    echo "Your FTN address is: {$regData['ftn_address']}\n\n";
    echo "Next steps:\n";
    echo "  1. Restart the admin daemon to pick up config changes\n";
    echo "  2. Run a poll to connect to the hub: php scripts/binkp_poll.php\n";
    echo "  3. Check the LovlyNet echo areas for messages\n";
    echo "\n";
}

// Main execution
if ($argc > 1) {
    switch ($argv[1]) {
        case '--status':
        case 'status':
            showStatus();
            exit(0);

        case '--update':
        case 'update':
            doRegistration(true);
            exit(0);

        case '--help':
        case 'help':
        case '-h':
            echo "LovlyNet Setup Tool\n";
            echo "===================\n\n";
            echo "Usage:\n";
            echo "  php lovlynet_setup.php              Interactive registration\n";
            echo "  php lovlynet_setup.php --status      Show registration status\n";
            echo "  php lovlynet_setup.php --update      Update existing registration\n";
            echo "  php lovlynet_setup.php --help        Show this help\n\n";
            exit(0);

        default:
            echo "Unknown option: {$argv[1]}\n";
            echo "Use --help for usage information.\n";
            exit(1);
    }
}

doRegistration();
