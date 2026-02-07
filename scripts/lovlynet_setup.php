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
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Config;
use BinktermPHP\Database;
use BinktermPHP\MessageHandler;
use BinktermPHP\Version;

define('LOVLYNET_REGISTRY_URL', 'https://lovlynet.lovelybits.org/api/register.php');
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

    // Check for existing LovlyNet uplink (manual configuration)
    $requestedFtnNode = null;
    if (!$existingConfig) {
        $fullConfig = $binkpConfig->getFullConfig();
        if (isset($fullConfig['uplinks'])) {
            foreach ($fullConfig['uplinks'] as $uplink) {
                if (isset($uplink['domain']) && $uplink['domain'] === LOVLYNET_DOMAIN) {
                    // Found existing LovlyNet uplink - extract node number from 'me' field
                    if (isset($uplink['me']) && preg_match('/227:1\/(\d+)/', $uplink['me'], $matches)) {
                        $requestedFtnNode = (int)$matches[1];
                        echo "Found existing LovlyNet uplink.\n";
                        echo "Your address: {$uplink['me']}\n";
                        echo "Will request node number {$requestedFtnNode} from registry.\n\n";
                    }
                    break;
                }
            }
        }
    }

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

    echo "Please verify the following information:\n\n";

    $systemName = readInput("System Name", $systemName);
    $sysopName = readInput("Sysop Name", $sysopName);

    // Ask if the BBS is publicly accessible
    echo "\n";
    $isPublic = confirm("Is your BBS accessible from the public internet?", true);
    echo "\n";

    if ($isPublic) {
        // Public node - will accept inbound connections
        echo "Public Node Configuration\n";
        echo str_repeat("-", 40) . "\n";
        echo "Your node will be able to receive inbound binkp connections.\n\n";

        $hostname = readInput("Public Hostname/IP (for binkp connections)", $hostname);
        $binkpPort = (int)readInput("Binkp Port", (string)$binkpPort);

        // Default site URL to https://<hostname>
        $defaultSiteUrl = 'https://' . $hostname;

        // Override with SITE_URL env var if set
        $envSiteUrl = getenv('SITE_URL');
        if ($envSiteUrl) {
            $defaultSiteUrl = rtrim($envSiteUrl, '/');
        }

        $siteUrl = readInput("Site URL (public web address)", $defaultSiteUrl);

        if (empty($siteUrl)) {
            echo "\nWarning: No site URL provided. Verification will not be possible.\n";
            $siteUrl = 'http://localhost';
        }

        // Verify the /api/verify endpoint is accessible locally
        echo "\nVerifying local /api/verify endpoint... ";
        $verifyUrl = rtrim($siteUrl, '/') . '/api/verify';
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 10, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ]);
        $response = @file_get_contents($verifyUrl, false, $context);

        if ($response === false) {
            echo "FAILED\n";
            echo "Note: The registry will attempt verification, but it may fail.\n";
        } else {
            $verifyData = json_decode($response, true);
            if (isset($verifyData['system_name'])) {
                echo "OK ({$verifyData['system_name']})\n";
            } else {
                echo "WARNING: Unexpected response format\n";
            }
        }
    } else {
        // Passive node - poll-only (community wireless, NAT, etc.)
        echo "Passive Node Configuration\n";
        echo str_repeat("-", 40) . "\n";
        echo "Your node will poll the hub for mail but will NOT accept\n";
        echo "inbound connections. This is suitable for:\n";
        echo "  - Community wireless networks\n";
        echo "  - Nodes behind NAT/firewall\n";
        echo "  - Dynamic IP addresses\n";
        echo "  - Development/testing systems\n\n";

        $hostname = 'passive.lovelybits.org';
        $binkpPort = 24554;
        $siteUrl = 'http://localhost';

        echo "Using passive node defaults:\n";
        echo "  Hostname: {$hostname} (not used for inbound)\n";
        echo "  Your node will poll the hub for mail\n";
    }

    echo "\n";
    echo "Registration Summary:\n";
    echo str_repeat("=", 50) . "\n";
    echo "  System Name:  {$systemName}\n";
    echo "  Sysop:        {$sysopName}\n";
    echo "  Node Type:    " . ($isPublic ? "Public (accepts inbound)" : "Passive (poll-only)") . "\n";
    echo "  Hostname:     {$hostname}\n";
    echo "  Binkp Port:   {$binkpPort}\n";
    echo "  Site URL:     {$siteUrl}\n";
    echo "  Software:     " . Version::getFullVersion() . "\n";
    echo str_repeat("=", 50) . "\n";
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
        'is_passive' => !$isPublic,
        'system_info' => [
            'software' => Version::getFullVersion(),
            'php_version' => PHP_VERSION
        ]
    ];

    // Include node_id for updates
    if ($isUpdate && $existingConfig && !empty($existingConfig['node_id'])) {
        $payload['node_id'] = $existingConfig['node_id'];
    }

    // Include requested FTN node number if found from existing uplink
    if ($requestedFtnNode !== null) {
        $payload['requested_ftn_node'] = $requestedFtnNode;
    }

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
    echo "FTN Address:      {$regData['ftn_address']}\n";
    echo "Hub Address:      {$regData['hub_address']}\n";
    echo "Hub Hostname:     {$regData['hub_hostname']}\n";
    echo "Hub Port:         {$regData['hub_port']}\n";
    echo "Binkp Password:   {$regData['binkp_password']}\n";
    echo "Areafix Password: {$regData['areafix_password']}\n";

    if (!empty($regData['api_key'])) {
        echo "API Key:          " . substr($regData['api_key'], 0, 8) . "...\n";
    }
    echo "\n";

    // Save registration config
    $lovlyNetConfig = [
        'node_id' => $regData['node_id'] ?? ($existingConfig['node_id'] ?? null),
        'api_key' => $regData['api_key'] ?? ($existingConfig['api_key'] ?? ''),
        'ftn_address' => $regData['ftn_address'],
        'hub_address' => $regData['hub_address'],
        'hub_hostname' => $regData['hub_hostname'],
        'hub_port' => $regData['hub_port'],
        'binkp_password' => $regData['binkp_password'],
        'areafix_password' => $regData['areafix_password'],
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
            $areafixSubject = $regData['areafix_password'];
            $messageHandler->sendNetmail(
                $sysopUser['id'],
                $regData['hub_address'],
                'AreaFix',
                $areafixSubject,
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

    // Send welcome message from hub
    echo "Sending welcome message... ";

    try {
        if (!isset($sysopUser) || !$sysopUser) {
            // Try to find sysop user again if not already set
            $db = Database::getInstance()->getPdo();
            $stmt = $db->prepare("
                SELECT id, real_name FROM users
                WHERE LOWER(real_name) = LOWER(?) OR LOWER(username) = LOWER(?)
                LIMIT 1
            ");
            $stmt->execute([$sysopName, $sysopName]);
            $sysopUser = $stmt->fetch();

            if (!$sysopUser) {
                $stmt = $db->prepare("SELECT id, real_name FROM users WHERE is_admin = TRUE ORDER BY id LIMIT 1");
                $stmt->execute();
                $sysopUser = $stmt->fetch();
            }
        }

        if (!$sysopUser) {
            echo "SKIPPED (no user found)\n";
        } else {
            // Read welcome template
            $templatePath = __DIR__ . '/../config/lovlynet_welcome.txt';
            if (!file_exists($templatePath)) {
                echo "SKIPPED (template not found)\n";
            } else {
                $welcomeText = file_get_contents($templatePath);

                // Replace placeholders
                $nodeType = $isPublic ? "Public (accepts inbound connections)" : "Passive (poll-only)";
                $pollingInfo = $isPublic
                    ? "Your node accepts inbound connections. The hub will deliver mail directly.\nOptional: Set up polling as a fallback (every 30 minutes recommended)."
                    : "Your node is passive (poll-only). You MUST poll the hub regularly.\nRecommended: Set up a cron job to poll every 15-30 minutes.";

                $welcomeText = str_replace('{FTN_ADDRESS}', $regData['ftn_address'], $welcomeText);
                $welcomeText = str_replace('{SYSTEM_NAME}', $systemName, $welcomeText);
                $welcomeText = str_replace('{NODE_TYPE}', $nodeType, $welcomeText);
                $welcomeText = str_replace('{AREAFIX_PASSWORD}', $regData['areafix_password'], $welcomeText);
                $welcomeText = str_replace('{POLLING_INFO}', $pollingInfo, $welcomeText);

                // Send welcome netmail (from sysop to themselves, appearing as from hub)
                $messageHandler = new MessageHandler();
                $messageHandler->sendNetmail(
                    $sysopUser['id'],              // From user (sysop)
                    $regData['ftn_address'],       // To address (sysop's new address)
                    $sysopName,                    // To name
                    'Welcome to LovlyNet!',        // Subject
                    $welcomeText,                  // Body
                    'LovlyNet Hub'                 // From name (appears as hub)
                );

                echo "OK\n";
            }
        }
    } catch (\Exception $e) {
        echo "SKIPPED\n";
        echo "Note: " . $e->getMessage() . "\n";
    }

    echo "\n";
    echo str_repeat("=", 50) . "\n";
    echo "LovlyNet setup complete!\n\n";
    echo "Your FTN address is: {$regData['ftn_address']}\n";

    if (!$isPublic) {
        echo "Node Type: Passive (poll-only)\n";
    }

    echo "\nNext steps:\n";
    echo "  1. Restart the admin daemon to pick up config changes\n";
    echo "  2. Run a poll to connect to the hub: php scripts/binkp_poll.php\n";
    echo "  3. Check the LovlyNet echo areas for messages\n";

    if (!$isPublic) {
        echo "\nNote: As a passive node, you will need to poll the hub\n";
        echo "regularly to send and receive mail. The hub cannot connect\n";
        echo "to you directly. Consider setting up a cron job to poll\n";
        echo "every 15-30 minutes.\n";
    }

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
