<?php
/**
 * Migration: 1.11.0.71 - Echomail moderation queue
 *
 * - Adds user_id (nullable) to echomail for attribution and post counting.
 * - Adds moderation_status to echomail ('approved', 'pending', 'rejected').
 * - Adds can_post_netecho_unmoderated to users (default FALSE).
 * - Grandfathers in existing users who have ever logged in.
 * - Best-effort backfills user_id on echomail where from_name matches a local
 *   user and from_address is a system FTN address.
 */

return function ($db) {
    // --- echomail: user_id column ------------------------------------------
    $db->exec("
        ALTER TABLE echomail
            ADD COLUMN IF NOT EXISTS user_id INTEGER REFERENCES users(id) ON DELETE SET NULL
    ");

    // --- echomail: moderation_status column --------------------------------
    $db->exec("
        ALTER TABLE echomail
            ADD COLUMN IF NOT EXISTS moderation_status VARCHAR(10) NOT NULL DEFAULT 'approved'
    ");

    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_echomail_moderation_status
            ON echomail (moderation_status)
            WHERE moderation_status != 'approved'
    ");

    // --- users: can_post_netecho_unmoderated column ------------------------
    $db->exec("
        ALTER TABLE users
            ADD COLUMN IF NOT EXISTS can_post_netecho_unmoderated BOOLEAN NOT NULL DEFAULT FALSE
    ");

    // Grandfather in all users who have ever started a session
    $db->exec("
        UPDATE users u
        SET can_post_netecho_unmoderated = TRUE
        WHERE EXISTS (
            SELECT 1 FROM user_sessions us WHERE us.user_id = u.id
        )
    ");

    // Admins always bypass moderation regardless
    $db->exec("
        UPDATE users SET can_post_netecho_unmoderated = TRUE WHERE is_admin = TRUE
    ");

    // --- Best-effort backfill user_id on echomail --------------------------
    // Collect the system's own FTN addresses from binkp.json so that only
    // messages sent from this system are attributed to a local user account.
    $myAddresses = _mig_echomod_get_my_addresses();

    if (!empty($myAddresses)) {
        $placeholders = implode(',', array_fill(0, count($myAddresses), '?'));

        // Match on username (case-insensitive)
        $stmt = $db->prepare("
            UPDATE echomail em
            SET user_id = u.id
            FROM users u
            WHERE em.user_id IS NULL
              AND em.from_address IN ($placeholders)
              AND LOWER(em.from_name) = LOWER(u.username)
        ");
        $stmt->execute($myAddresses);

        // Match on real_name where username didn't match
        $stmt = $db->prepare("
            UPDATE echomail em
            SET user_id = u.id
            FROM users u
            WHERE em.user_id IS NULL
              AND em.from_address IN ($placeholders)
              AND LOWER(em.from_name) = LOWER(u.real_name)
        ");
        $stmt->execute($myAddresses);
    }

    return true;
};

/**
 * Read the system's own FTN address list from binkp.json.
 * Returns an array of address strings (may be empty if config is unreadable).
 */
function _mig_echomod_get_my_addresses(): array
{
    $configPath = __DIR__ . '/../../config/binkp.json';
    if (!file_exists($configPath)) {
        return [];
    }

    $data = json_decode(file_get_contents($configPath), true);
    if (!is_array($data)) {
        return [];
    }

    $addresses = [];

    $systemAddr = $data['system']['address'] ?? null;
    if (!empty($systemAddr)) {
        $addresses[] = (string)$systemAddr;
    }

    foreach ($data['uplinks'] ?? [] as $uplink) {
        $addr = $uplink['me'] ?? null;
        if (!empty($addr)) {
            $addresses[] = (string)$addr;
        }
    }

    return array_values(array_unique($addresses));
}
