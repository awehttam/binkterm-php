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

class GameConfig
{
    private static ?array $config = null;
    private static bool $loaded = false;

    private static function getConfigPath(): string
    {
        return __DIR__ . '/../config/webdoors.json';
    }

    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        self::$loaded = true;
        $path = self::getConfigPath();

        if (!file_exists($path)) {
            self::$config = null;
            return;
        }

        $json = file_get_contents($path);
        $data = json_decode($json, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            self::$config = $data;
        } else {
            self::$config = null;
        }
    }

    public static function isGameSystemEnabled(): bool
    {
        self::load();
        return self::$config !== null;
    }

    public static function isEnabled(string $game): bool
    {
        self::load();

        if (self::$config === null) {
            return false;
        }

        if (!isset(self::$config[$game])) {
            return false;
        }

        return !empty(self::$config[$game]['enabled']);
    }

    public static function getGameConfig(string $game): ?array
    {
        self::load();

        if (self::$config === null) {
            return null;
        }

        return self::$config[$game] ?? null;
    }
}

