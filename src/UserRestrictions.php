<?php

namespace BinktermPHP;

/**
 * UserRestrictions - Reserved usernames and real names
 */
class UserRestrictions
{
    private const RESERVED_USERNAMES = [
        'system',
        'root',
        'sysop'
    ];

    private const RESERVED_REAL_NAMES = [
        'system',
        'root',
        'sysop'
    ];

    /**
     * Check if username is reserved
     *
     * @param string $username
     * @return bool
     */
    public static function isRestrictedUsername(string $username): bool
    {
        $normalized = strtolower(trim($username));
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, self::RESERVED_USERNAMES, true);
    }

    /**
     * Check if real name is reserved
     *
     * @param string $realName
     * @return bool
     */
    public static function isRestrictedRealName(string $realName): bool
    {
        $normalized = strtolower(trim($realName));
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, self::RESERVED_REAL_NAMES, true);
    }
}
