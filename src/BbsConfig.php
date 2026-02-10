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

class BbsConfig
{
    private static ?array $config = null;
    private static bool $loaded = false;

    private static function getConfigPath(): string
    {
        return __DIR__ . '/../config/bbs.json';
    }

    private static function getExamplePath(): string
    {
        return __DIR__ . '/../config/bbs.json.example';
    }

    private static function loadJsonFile(string $path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }

        $json = file_get_contents($path);
        $data = json_decode($json, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return $data;
        }

        return null;
    }

    private static function getDefaults(): array
    {
        $example = self::loadJsonFile(self::getExamplePath());
        if ($example !== null) {
            return $example;
        }
        // We shouldn't get here..
        error_log("example bbs.json.example missing or corrupt?");
        return [
            'features' => [
                'webdoors' => true,
                'shoutbox' => true,
                'advertising' => true,
                'voting_booth' => true
            ]
        ];
    }

    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        self::$loaded = true;

        $config = self::loadJsonFile(self::getConfigPath());
        $defaults = self::getDefaults();

        if ($config === null) {
            self::$config = $defaults;
            if(self::$config==null){
                error_log("Unable to load ANY bbs configuration");
                throw new \Exception("Unable to load any BBS configuration");
            }
            return;
        }

        $merged = $defaults;
        foreach ($config as $key => $value) {
            if ($key === 'features') {
                continue;
            }
            $merged[$key] = $value;
        }

        $features = $defaults['features'] ?? [];
        if (isset($config['features']) && is_array($config['features'])) {
            foreach ($config['features'] as $key => $value) {
                if (array_key_exists($key, $features)) {
                    $features[$key] = (bool)$value;
                } else {
                    $features[$key] = $value;
                }
            }
        }
        $merged['features'] = $features;
        self::$config = $merged;
    }

    public static function getConfig(): array
    {
        self::load();
        return self::$config ?? self::getDefaults();
    }

    public static function reload(): void
    {
        self::$loaded = false;
        self::$config = null;
    }

    public static function isFeatureEnabled(string $feature): bool
    {
        self::load();
        $features = self::$config['features'] ?? [];
        return !empty($features[$feature]);
    }

    /**
     * Get a feature setting value with optional default
     *
     * @param string $feature Feature key to retrieve
     * @param mixed $default Default value if feature not set
     * @return mixed Feature value or default
     */
    public static function getFeatureSetting(string $feature, $default = null)
    {
        self::load();
        $features = self::$config['features'] ?? [];
        return $features[$feature] ?? $default;
    }

    public static function saveConfig(array $config): bool
    {
        $defaults = self::getDefaults();
        $path = self::getConfigPath();
        $existing = self::loadJsonFile($path) ?? [];

        $sanitized = $defaults;
        foreach ($existing as $key => $value) {
            if ($key === 'features') {
                continue;
            }
            $sanitized[$key] = $value;
        }
        foreach ($config as $key => $value) {
            if ($key === 'features') {
                continue;
            }
            $sanitized[$key] = $value;
        }

        $features = $defaults['features'] ?? [];
        if (isset($existing['features']) && is_array($existing['features'])) {
            foreach ($existing['features'] as $key => $value) {
                if (array_key_exists($key, $features)) {
                    $features[$key] = (bool)$value;
                } else {
                    $features[$key] = $value;
                }
            }
        }
        if (isset($config['features']) && is_array($config['features'])) {
            foreach ($config['features'] as $key => $value) {
                if (array_key_exists($key, $features)) {
                    $features[$key] = (bool)$value;
                } else {
                    $features[$key] = $value;
                }
            }
        }
        $sanitized['features'] = $features;

        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $json = json_encode($sanitized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        $result = @file_put_contents($path, $json . PHP_EOL);
        if ($result === false) {
            return false;
        }

        self::$config = $sanitized;
        return true;
    }
}

