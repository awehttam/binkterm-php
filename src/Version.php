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
 * Centralized version management for BinktermPHP
 * 
 * This class provides a single source of truth for the application version
 * that can be used throughout the application for tearlines, web interface,
 * and other version display needs.
 */
class Version
{
    /**
     * The current version of BinktermPHP
     *
     * This should be updated when releasing new versions.
     * Format: MAJOR.MINOR.PATCH
     */
    private const VERSION = '1.7.9';
    
    /**
     * Get the current application version
     *
     * @return string The version string (e.g., "1.4.2")
     */
    public static function getVersion(): string
    {
        return self::VERSION;
    }
    
    /**
     * Get the application name
     *
     * @return string The application name
     */
    public static function getAppName(): string
    {
        return 'BinktermPHP';
    }
    
    /**
     * Get the full application name with version
     *
     * @return string The full app name and version (e.g., "BinktermPHP v1.4.2")
     */
    public static function getFullVersion(): string
    {
        return self::getAppName() . ' v' . self::getVersion();
    }
    
    /**
     * Get the tearline string for FidoNet messages
     *
     * @return string The tearline string (e.g., "--- BinktermPHP v1.4.2")
     */
    public static function getTearline(): string
    {
        return '--- ' . self::getFullVersion();
    }
    
    /**
     * Get version info as an array
     *
     * @return array Associative array with version information
     */
    public static function getVersionInfo(): array
    {
        $parts = explode('.', self::VERSION);
        
        return [
            'version' => self::VERSION,
            'app_name' => self::getAppName(),
            'full_version' => self::getFullVersion(),
            'tearline' => self::getTearline(),
            'major' => (int)($parts[0] ?? 0),
            'minor' => (int)($parts[1] ?? 0),
            'patch' => (int)($parts[2] ?? 0)
        ];
    }
    
    /**
     * Compare this version with another version string
     *
     * @param string $version Version to compare against
     * @return int -1 if this version is lower, 0 if equal, 1 if higher
     */
    public static function compareVersion(string $version): int
    {
        return version_compare(self::VERSION, $version);
    }
    
    /**
     * Check if this version is newer than the given version
     *
     * @param string $version Version to compare against
     * @return bool True if this version is newer
     */
    public static function isNewerThan(string $version): bool
    {
        return self::compareVersion($version) > 0;
    }
    
    /**
     * Check if this version is older than the given version
     *
     * @param string $version Version to compare against
     * @return bool True if this version is older
     */
    public static function isOlderThan(string $version): bool
    {
        return self::compareVersion($version) < 0;
    }
}

