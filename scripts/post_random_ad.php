#!/usr/bin/php
<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Advertising;
use BinktermPHP\Database;
use BinktermPHP\MessageHandler;

function showUsage()
{
    echo "Usage: php scripts/post_random_ad.php [options]\n\n";
    echo "Required options:\n";
    echo "  --echoarea=TAG        Echo area tag (e.g., GENERAL)\n";
    echo "  --domain=DOMAIN       Network domain (e.g., fidonet)\n\n";
    echo "Optional:\n";
    echo "  --subject=TEXT        Subject line (default: BBS Advertisement)\n";
    echo "  --subject-line=TEXT   Subject line (alias)\n";
    echo "  --to-name=NAME        To name (default: All)\n";
    echo "  --from=USERNAME       Post as specific user (default: first admin)\n";
    echo "  --help                Show this help message\n\n";
}

function parseArgs($argv)
{
    $args = [];
    foreach ($argv as $arg) {
        if (strpos($arg, '--') === 0) {
            if (strpos($arg, '=') !== false) {
                list($key, $value) = explode('=', substr($arg, 2), 2);
                $args[$key] = $value;
            } else {
                $args[substr($arg, 2)] = true;
            }
        }
    }
    return $args;
}

function getUserByUsername(string $username)
{
    $db = Database::getInstance()->getPdo();
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    return $stmt->fetch();
}

function getSysopUser()
{
    $db = Database::getInstance()->getPdo();
    $stmt = $db->query("SELECT * FROM users WHERE is_admin = TRUE ORDER BY id LIMIT 1");
    $user = $stmt->fetch();
    if ($user) {
        return $user;
    }
    $stmt = $db->query("SELECT * FROM users ORDER BY id LIMIT 1");
    return $stmt->fetch();
}

$args = parseArgs($argv);

if (isset($args['help'])) {
    showUsage();
    exit(0);
}

try {
    if (empty($args['echoarea']) || empty($args['domain'])) {
        throw new RuntimeException('Missing required arguments: --echoarea and --domain');
    }

    $user = null;
    if (!empty($args['from'])) {
        $user = getUserByUsername($args['from']);
        if (!$user) {
            throw new RuntimeException('User not found: ' . $args['from']);
        }
    } else {
        $user = getSysopUser();
    }

    if (!$user) {
        throw new RuntimeException('No users found in database');
    }

    $ads = new Advertising();
    $ad = $ads->getRandomAd();
    if (!$ad) {
        throw new RuntimeException('No ads found in bbs_ads');
    }

    $subject = $args['subject'] ?? ($args['subject-line'] ?? 'BBS Advertisement');
    $toName = $args['to-name'] ?? 'All';

    $handler = new MessageHandler();
    $result = $handler->postEchomail(
        $user['id'],
        $args['echoarea'],
        $args['domain'],
        $toName,
        $subject,
        $ad['content']
    );

    if (!$result) {
        throw new RuntimeException('Failed to post advertisement');
    }

    echo "Posted advertisement to {$args['echoarea']} ({$args['domain']}).\n";
    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
