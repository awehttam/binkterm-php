<?php
/**
 * Guest User Helper
 *
 * Provides access to the shared system user account (_guest) that is used
 * as the user_id for anonymous door sessions.
 *
 * @package BinktermPHP
 */

namespace BinktermPHP;

class GuestUser
{
    private static ?int $guestUserId = null;

    /**
     * Get the database ID of the shared guest system user.
     *
     * Returns null if the guest user does not exist (migration not yet run).
     */
    public static function getId(): ?int
    {
        if (self::$guestUserId !== null) {
            return self::$guestUserId;
        }

        $db = Database::getInstance()->getPdo();
        $stmt = $db->prepare(
            "SELECT id FROM users WHERE username = '_guest' AND is_system = TRUE LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        self::$guestUserId = $row ? (int)$row['id'] : null;
        return self::$guestUserId;
    }
}
