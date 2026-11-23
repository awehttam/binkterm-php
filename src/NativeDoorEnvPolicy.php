<?php
/**
 * Native Door Environment Policy
 *
 * PHP-side companion to scripts/dosbox-bridge/door-env.js. The bridge enforces
 * the secret-name denylist at launch; this class lets the admin UI and API
 * refuse to approve such names in the first place. Keep the patterns in sync
 * with SECRET_NAME_PATTERNS in door-env.js.
 *
 * @package BinktermPHP
 */

namespace BinktermPHP;

class NativeDoorEnvPolicy
{
    /** @var string[] Regular expressions matching secret-looking variable names */
    private const SECRET_PATTERNS = [
        '/^DB_/i',
        '/^SMTP_/i',
        '/^ADMIN_DAEMON_/i',
        '/SECRET/i',
        '/PASS(WORD|WD)?(_|$)/i',
        '/TOKEN/i',
        '/(^|_)KEY$/i',
        '/(^|_)KEY_/i',
        '/CREDENTIAL/i',
        '/^AWS_/i',
    ];

    /**
     * Check whether an environment variable name is syntactically valid.
     *
     * @param mixed $name Candidate name
     * @return bool True if it is a valid variable name
     */
    public static function isValidName($name): bool
    {
        return is_string($name) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1;
    }

    /**
     * Check whether a variable name looks like a secret and must never be passed to a door.
     *
     * @param string $name Variable name
     * @return bool True if the name matches a secret pattern
     */
    public static function isSecretName(string $name): bool
    {
        foreach (self::SECRET_PATTERNS as $pattern) {
            if (preg_match($pattern, $name) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalize a manifest's env_passthrough request list to unique valid names.
     *
     * @param mixed $requested Raw manifest value
     * @return string[] Valid, de-duplicated names
     */
    public static function normalizeNameList($requested): array
    {
        if (!is_array($requested)) {
            return [];
        }
        $names = [];
        foreach ($requested as $name) {
            if (self::isValidName($name)) {
                $names[$name] = true;
            }
        }
        return array_keys($names);
    }

    /**
     * Describe a manifest's passthrough requests for the admin UI.
     *
     * @param string[] $requested Requested names
     * @return array<int, array{name: string, refused: bool}>
     */
    public static function describeRequests(array $requested): array
    {
        $out = [];
        foreach ($requested as $name) {
            $out[] = ['name' => $name, 'refused' => self::isSecretName($name)];
        }
        return $out;
    }

    /**
     * Remove invalid and secret-named entries from every door's approved list
     * before the config is written.
     *
     * @param array $config Full nativedoors.json structure
     * @return array Sanitized config
     */
    public static function sanitizeConfig(array $config): array
    {
        foreach ($config as $doorId => $entry) {
            if (!is_array($entry) || !array_key_exists('env_passthrough_approved', $entry)) {
                continue;
            }
            $approved = [];
            foreach (self::normalizeNameList($entry['env_passthrough_approved']) as $name) {
                if (!self::isSecretName($name)) {
                    $approved[] = $name;
                }
            }
            if ($approved === []) {
                unset($config[$doorId]['env_passthrough_approved']);
            } else {
                $config[$doorId]['env_passthrough_approved'] = $approved;
            }
        }
        return $config;
    }
}
