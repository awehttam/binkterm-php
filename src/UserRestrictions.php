<?php

/*
 * Copright Matthew Asham and BinktermPHP Contributors
 * 
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the 
 * following conditions are met:
 * 
 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
 * Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE
 * 
 */


namespace BinktermPHP;

/**
 * UserRestrictions - Reserved usernames and real names
 */
class UserRestrictions
{
    private const RESERVED_USERNAMES = [
        'system',
        'root',
        'sysop',
        'admin',
        'administrator',
        'sysadmin',
        'sysadm',
        'moderator',
        'mod',
        'staff',
        'support',
        'helpdesk',
        'postmaster',
        'webmaster',
        'nobody',
        'anonymous',
        'guest',
    ];

    private const RESERVED_REAL_NAMES = [
        'system',
        'root',
        'sysop',
        'system operator',
        'system administrator',
        'admin',
        'administrator',
        'sysadmin',
        'sysadm',
        'moderator',
        'staff',
        'support',
        'anonymous',
        'guest',
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

