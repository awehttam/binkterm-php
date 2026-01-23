#!/usr/bin/env php
<?php
/**
 * Crashmail Queue Processor
 *
 * Processes the crashmail queue, attempting delivery of messages marked for
 * immediate/direct delivery (crash attribute).
 *
 * Run via cron every 5 minutes:
 * */5 * * * * php /path/to/binkterm/scripts/crashmail_poll.php
 *
 * Options:
 *   --limit=N    Maximum items to process (default: 10)
 *   --verbose    Show detailed output
 *   --dry-run    Check queue without attempting delivery
 */

// Find and load autoloader
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
];

$autoloaderFound = false;
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $autoloaderFound = true;
        break;
    }
}

if (!$autoloaderFound) {
    fwrite(STDERR, "Error: Could not find vendor/autoload.php\n");
    exit(1);
}

use BinktermPHP\Crashmail\CrashmailService;
use BinktermPHP\Binkp\Config\BinkpConfig;

// Parse command line options
$options = getopt('', ['limit:', 'verbose', 'dry-run', 'help']);

if (isset($options['help'])) {
    echo "Crashmail Queue Processor\n\n";
    echo "Usage: php crashmail_poll.php [options]\n\n";
    echo "Options:\n";
    echo "  --limit=N    Maximum items to process (default: 10)\n";
    echo "  --verbose    Show detailed output\n";
    echo "  --dry-run    Check queue without attempting delivery\n";
    echo "  --help       Show this help message\n\n";
    exit(0);
}

$limit = isset($options['limit']) ? (int)$options['limit'] : 10;
$verbose = isset($options['verbose']);
$dryRun = isset($options['dry-run']);

$timestamp = date('Y-m-d H:i:s');

// Check if crashmail is enabled
$config = BinkpConfig::getInstance();
if (!$config->getCrashmailEnabled()) {
    if ($verbose) {
        echo "[{$timestamp}] Crashmail processing is disabled in configuration\n";
    }
    exit(0);
}

try {
    $service = new CrashmailService();

    if ($dryRun) {
        // Just show queue status
        $stats = $service->getQueueStats();
        echo "[{$timestamp}] Crashmail queue status (dry run):\n";
        echo "  Pending:    {$stats['pending']}\n";
        echo "  Attempting: {$stats['attempting']}\n";
        echo "  Sent (24h): {$stats['sent_24h']}\n";
        echo "  Failed:     {$stats['failed']}\n";
        echo "  Total:      {$stats['total']}\n";

        if ($verbose && $stats['pending'] > 0) {
            echo "\nPending items:\n";
            $items = $service->getQueueItems('pending', 20);
            foreach ($items as $item) {
                echo "  #{$item['id']}: To {$item['destination_address']} ";
                echo "- {$item['subject']} ";
                echo "(attempts: {$item['attempts']})\n";
            }
        }

        exit(0);
    }

    // Process the queue
    $results = $service->processQueue($limit);

    // Output results
    echo "[{$timestamp}] Crashmail queue processed: ";
    echo "total={$results['processed']}, ";
    echo "success={$results['success']}, ";
    echo "failed={$results['failed']}, ";
    echo "deferred={$results['deferred']}\n";

    if ($verbose) {
        $stats = $service->getQueueStats();
        echo "  Queue status: {$stats['pending']} pending, {$stats['failed']} failed\n";
    }

    // Exit with error code if any permanently failed
    exit($results['failed'] > 0 ? 1 : 0);

} catch (\Exception $e) {
    fwrite(STDERR, "[{$timestamp}] Error: " . $e->getMessage() . "\n");
    if ($verbose) {
        fwrite(STDERR, "Stack trace:\n" . $e->getTraceAsString() . "\n");
    }
    exit(1);
}
