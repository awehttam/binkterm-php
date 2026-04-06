#!/usr/bin/env php
<?php
/**
 * Rename a local user account
 *
 * Runs in dry-run mode by default. Pass --execute to commit changes.
 *
 * Updates the username across all tables that store it. Echomail from_name
 * is intentionally left untouched — that data has already been propagated
 * across the FTN network to other nodes and cannot be recalled.
 *
 * Usage:
 *   php rename_user.php --from=OldUsername --to=NewUsername [--execute] [--yes]
 *
 * Options:
 *   --from=NAME   Current username (case-insensitive lookup)
 *   --to=NAME     New username to assign
 *   --execute     Actually apply the changes (default is dry-run)
 *   --yes         Skip the interactive confirmation prompt (only with --execute)
 *   --help        Show this help message
 *
 * Examples:
 *   php rename_user.php --from=DarkKnight --to="Dark Knight"
 *   php rename_user.php --from=OldHandle --to=NewHandle --execute
 *   php rename_user.php --from=OldHandle --to=NewHandle --execute --yes
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\Database;
use BinktermPHP\UserRestrictions;
use BinktermPHP\Binkp\Config\BinkpConfig;

// ---------------------------------------------------------------------------
// Argument parsing
// ---------------------------------------------------------------------------

$options = [];
for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if (strpos($arg, '--') === 0) {
        $parts = explode('=', substr($arg, 2), 2);
        $key   = $parts[0];
        $value = isset($parts[1]) ? $parts[1] : true;
        $options[$key] = $value;
    }
}

if (isset($options['help'])) {
    showHelp();
    exit(0);
}

$oldUsername = isset($options['from']) ? (string)$options['from'] : '';
$newUsername = isset($options['to'])   ? (string)$options['to']   : '';
$dryRun      = !isset($options['execute']);
$skipConfirm = isset($options['yes']);

if ($oldUsername === '' || $newUsername === '') {
    echo "Error: --from and --to are required.\n\n";
    showHelp();
    exit(1);
}

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

$logger = getServerLogger();

// Username format: letters, digits, underscores; single internal spaces
// allowed; 3–50 characters (matches the VARCHAR(50) column width).
if (!preg_match('/^(?=.{3,50}$)[a-zA-Z0-9_]+( [a-zA-Z0-9_]+)*$/', $newUsername)) {
    echo "Error: '$newUsername' is not a valid username.\n";
    echo "Usernames must be 3–50 characters, using letters, numbers, underscores,\n";
    echo "and single internal spaces only (no leading/trailing spaces).\n";
    exit(1);
}

if (strtolower($oldUsername) === strtolower($newUsername)) {
    echo "Error: Old and new usernames are identical (case-insensitive).\n";
    exit(1);
}

if (UserRestrictions::isRestrictedUsername($newUsername)) {
    echo "Error: '$newUsername' is a restricted username and cannot be used.\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// Database lookups
// ---------------------------------------------------------------------------

$db = Database::getInstance()->getPdo();

// Look up the user by current username (case-insensitive).
$stmt = $db->prepare("
    SELECT id, username, real_name, is_system
    FROM users
    WHERE LOWER(username) = LOWER(?)
    LIMIT 1
");
$stmt->execute([$oldUsername]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo "Error: No user found with username '$oldUsername'.\n";
    exit(1);
}

$userId      = (int)$user['id'];
$currentName = $user['username'];
$realName    = $user['real_name'] ?? '';

if ($user['is_system']) {
    echo "Error: '$currentName' is a system account and cannot be renamed.\n";
    exit(1);
}

// Ensure the new username does not collide with any existing username or
// real_name (case-insensitive), in both users and pending_users, excluding
// this user's own record so a pure case change is not blocked.
$stmt = $db->prepare("
    SELECT 'users' AS src, username AS name
      FROM users
     WHERE LOWER(username) = LOWER(?) AND id != ?
    UNION ALL
    SELECT 'users', real_name
      FROM users
     WHERE LOWER(real_name) = LOWER(?) AND id != ?
    UNION ALL
    SELECT 'pending_users', username
      FROM pending_users
     WHERE LOWER(username) = LOWER(?)
    UNION ALL
    SELECT 'pending_users', real_name
      FROM pending_users
     WHERE LOWER(real_name) = LOWER(?)
    LIMIT 1
");
$stmt->execute([$newUsername, $userId, $newUsername, $userId, $newUsername, $newUsername]);
$collision = $stmt->fetch(PDO::FETCH_ASSOC);

if ($collision) {
    echo "Error: '$newUsername' already exists as a username or real name in {$collision['src']}.\n";
    exit(1);
}

// Collect local FTN addresses for scoping the netmail updates. Only netmail
// addressed to/from a local FTN address is associated with this user; rows
// with external addresses belong to other systems and must not be touched.
$localAddresses = [];
try {
    $binkpConfig    = BinkpConfig::getInstance();
    $localAddresses = $binkpConfig->getMyAddresses();
    $systemAddress  = $binkpConfig->getSystemAddress();
    if ($systemAddress !== '') {
        $localAddresses[] = $systemAddress;
    }
    $localAddresses = array_values(array_unique($localAddresses));
} catch (\Exception $e) {
    $logger->warning("rename_user: could not load BinkpConfig, netmail name updates will be skipped: "
        . $e->getMessage());
}

// ---------------------------------------------------------------------------
// Preview
// ---------------------------------------------------------------------------

echo "=================================================\n";
echo "Rename User" . ($dryRun ? " [DRY RUN]" : "") . "\n";
echo "=================================================\n\n";
echo "  User ID     : $userId\n";
echo "  Current name: $currentName\n";
echo "  Real name   : $realName\n";
echo "  New username: $newUsername\n\n";

echo "Tables that will be updated:\n";
echo "  users.username                (by user id)\n";
echo "  pending_users.username        (if a pending record exists)\n";
echo "  mrc_local_handles.username    (by user_id FK)\n";
echo "  mrc_local_presence.username   (by user_id FK)\n";
echo "  mrc_users.username            (is_local=true rows only)\n";
echo "  echoareas.moderator           (where moderator matches old name)\n";

if (!empty($localAddresses)) {
    $addrList = implode(', ', $localAddresses);
    echo "  netmail.to_name               (where to_address IN: $addrList)\n";
    echo "  netmail.from_name             (where from_address IN: $addrList)\n";
} else {
    echo "  netmail.to_name               (SKIPPED — no local FTN addresses found)\n";
    echo "  netmail.from_name             (SKIPPED — no local FTN addresses found)\n";
}

echo "\nTables intentionally NOT updated:\n";
echo "  echomail.from_name  — already propagated to peer FTN nodes; cannot be recalled\n";
echo "  echomail.to_name    — verbatim FTN packet data\n";
echo "  mrc_messages        — historical chat log\n\n";

if ($dryRun) {
    echo "*** DRY RUN — no changes made ***\n";
    echo "*** Run with --execute to apply changes ***\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Confirmation
// ---------------------------------------------------------------------------

if (!$skipConfirm) {
    echo "Proceed with rename? (yes/no): ";
    $handle = fopen('php://stdin', 'r');
    $answer = strtolower(trim(fgets($handle)));
    fclose($handle);
    echo "\n";

    if ($answer !== 'yes') {
        echo "Aborted.\n";
        exit(0);
    }
}

// ---------------------------------------------------------------------------
// Rename — single transaction
// ---------------------------------------------------------------------------

try {
    $db->beginTransaction();

    // 1. Canonical record — always keyed by id, never by name string.
    $stmt = $db->prepare("UPDATE users SET username = ? WHERE id = ?");
    $stmt->execute([$newUsername, $userId]);
    $usersUpdated = $stmt->rowCount();

    // 2. pending_users — present if the account was placed back in the queue.
    $stmt = $db->prepare("UPDATE pending_users SET username = ? WHERE LOWER(username) = LOWER(?)");
    $stmt->execute([$newUsername, $oldUsername]);
    $pendingUpdated = $stmt->rowCount();

    // 3. mrc_local_handles — user_id is the PK (FK to users.id).
    $stmt = $db->prepare("UPDATE mrc_local_handles SET username = ? WHERE user_id = ?");
    $stmt->execute([$newUsername, $userId]);
    $mrcHandlesUpdated = $stmt->rowCount();

    // 4. mrc_local_presence — has user_id column since migration v1.11.0.63.
    $stmt = $db->prepare("UPDATE mrc_local_presence SET username = ? WHERE user_id = ?");
    $stmt->execute([$newUsername, $userId]);
    $mrcPresenceUpdated = $stmt->rowCount();

    // 5. mrc_users — transient remote-facing presence; local rows only.
    $stmt = $db->prepare("
        UPDATE mrc_users SET username = ?
        WHERE LOWER(username) = LOWER(?) AND is_local = 'true'
    ");
    $stmt->execute([$newUsername, $oldUsername]);
    $mrcUsersUpdated = $stmt->rowCount();

    // 6. echoareas.moderator — stored by name, not by user_id.
    $stmt = $db->prepare("UPDATE echoareas SET moderator = ? WHERE LOWER(moderator) = LOWER(?)");
    $stmt->execute([$newUsername, $oldUsername]);
    $echoareasUpdated = $stmt->rowCount();

    // 7. netmail.to_name — update rows addressed to a local FTN address only.
    //    The inbox query matches on to_name for the 'received' filter view and
    //    as a fallback for messages without a user_id stamp. Without this update
    //    those messages would disappear from the renamed user's inbox.
    //    Scoping by to_address IN (local addresses) ensures we do not alter
    //    records belonging to external users who share the same name.
    $netmailToUpdated = 0;
    if (!empty($localAddresses)) {
        $placeholders = implode(',', array_fill(0, count($localAddresses), '?'));
        $params = array_merge([$newUsername, $oldUsername], $localAddresses);
        $stmt = $db->prepare("
            UPDATE netmail
               SET to_name = ?
             WHERE LOWER(to_name) = LOWER(?)
               AND to_address IN ($placeholders)
        ");
        $stmt->execute($params);
        $netmailToUpdated = $stmt->rowCount();
    }

    // 8. netmail.from_name — update rows sent from a local FTN address only.
    //    The sent-messages filter matches on from_name; without this update the
    //    user's sent history would vanish from the 'sent' filter view.
    //    Scoping by from_address IN (local addresses) ensures we only touch
    //    messages that originated from this system.
    $netmailFromUpdated = 0;
    if (!empty($localAddresses)) {
        $placeholders = implode(',', array_fill(0, count($localAddresses), '?'));
        $params = array_merge([$newUsername, $oldUsername], $localAddresses);
        $stmt = $db->prepare("
            UPDATE netmail
               SET from_name = ?
             WHERE LOWER(from_name) = LOWER(?)
               AND from_address IN ($placeholders)
        ");
        $stmt->execute($params);
        $netmailFromUpdated = $stmt->rowCount();
    }

    $db->commit();

} catch (\Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo "Error: " . $e->getMessage() . "\n";
    $logger->error("rename_user: transaction failed renaming '$oldUsername' -> '$newUsername': "
        . $e->getMessage());
    exit(1);
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------

echo "Rename complete:\n";
echo "  users                  : $usersUpdated row(s)\n";
echo "  pending_users          : $pendingUpdated row(s)\n";
echo "  mrc_local_handles      : $mrcHandlesUpdated row(s)\n";
echo "  mrc_local_presence     : $mrcPresenceUpdated row(s)\n";
echo "  mrc_users (local)      : $mrcUsersUpdated row(s)\n";
echo "  echoareas.moderator    : $echoareasUpdated row(s)\n";
echo "  netmail.to_name        : $netmailToUpdated row(s)\n";
echo "  netmail.from_name      : $netmailFromUpdated row(s)\n";

$logger->info(
    "rename_user: '$currentName' (id=$userId) renamed to '$newUsername'"
    . " [users=$usersUpdated, pending=$pendingUpdated"
    . ", mrc_handles=$mrcHandlesUpdated, mrc_presence=$mrcPresenceUpdated"
    . ", mrc_users=$mrcUsersUpdated, echoareas=$echoareasUpdated"
    . ", netmail_to=$netmailToUpdated, netmail_from=$netmailFromUpdated]"
);

exit(0);

// ---------------------------------------------------------------------------
// Help
// ---------------------------------------------------------------------------

/**
 * Print usage information.
 */
function showHelp(): void
{
    echo <<<HELP
rename_user.php — Rename a local user account

Runs in dry-run mode by default. Pass --execute to commit changes.

Usage:
  php rename_user.php --from=OldUsername --to=NewUsername [--execute] [--yes]

Options:
  --from=NAME   Current username (case-insensitive lookup)
  --to=NAME     New username to assign
  --execute     Actually apply the changes (default is dry-run)
  --yes         Skip the interactive confirmation prompt (only with --execute)
  --help        Show this help message

What is updated:
  users.username              canonical record, keyed by user id
  pending_users.username      if the account has a pending record
  mrc_local_handles.username  MRC handle mapping, keyed by user_id FK
  mrc_local_presence.username WebDoor MRC presence, keyed by user_id FK
  mrc_users.username          active MRC session rows where is_local=true
  echoareas.moderator         echoarea moderator assignments stored by name
  netmail.to_name             rows where to_address is a local FTN address
  netmail.from_name           rows where from_address is a local FTN address

What is NOT updated (intentionally):
  echomail.from_name  already propagated to peer FTN nodes; cannot be recalled
  echomail.to_name    verbatim FTN packet data
  mrc_messages        historical chat log

Examples:
  php rename_user.php --from=DarkKnight --to="Dark Knight"
  php rename_user.php --from=OldHandle --to=NewHandle --execute
  php rename_user.php --from=OldHandle --to=NewHandle --execute --yes

HELP;
}
