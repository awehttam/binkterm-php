<?php
/**
 * Native Door Launch Policy
 *
 * Validates and describes the per-door launch settings stored in
 * config/nativedoors.json: `launch_mode` (direct, sudo or socket), `run_as`
 * (the sudo user) and `launcher_socket` (the socket launcher to use).
 *
 * This is a convenience layer for the admin UI. The real enforcement is in the
 * door bridge (scripts/dosbox-bridge/door-launchers.js), which only accepts
 * door users from the root-controlled door group and sockets that exist in the
 * root-controlled socket directory, and refuses root and the app user. A
 * compromised admin session therefore cannot widen what a door may run as.
 *
 * @package BinktermPHP
 */

namespace BinktermPHP;

class NativeDoorLaunchPolicy
{
    public const MODES = ['direct', 'sudo', 'socket'];

    private const DEFAULT_SOCKET_DIR = '/run/binkterm-doors';
    private const DEFAULT_DOORS_GROUP = 'doors';
    private const NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_-]{0,31}$/';

    /**
     * Whether non-direct launch modes can work on this server (Linux and BSD only).
     *
     * @return bool
     */
    public static function isSupported(): bool
    {
        return !in_array(PHP_OS_FAMILY, ['Windows', 'Darwin'], true);
    }

    /**
     * @param mixed $name Candidate user or socket name
     * @return bool True if it is a plausible account or socket name
     */
    public static function isValidName($name): bool
    {
        return is_string($name) && preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * Directory holding one subdirectory per launcher socket.
     *
     * @return string
     */
    public static function getSocketDir(): string
    {
        return rtrim((string)Config::env('NATIVEDOOR_SOCKET_DIR', self::DEFAULT_SOCKET_DIR), '/\\');
    }

    /**
     * Name of the group whose members doors may be run as (sudo mode).
     *
     * @return string
     */
    public static function getDoorsGroup(): string
    {
        $group = (string)Config::env('NATIVEDOOR_DOORS_GROUP', self::DEFAULT_DOORS_GROUP);
        return self::isValidName($group) ? $group : self::DEFAULT_DOORS_GROUP;
    }

    /**
     * Users doors may be run as under sudo: members of the door group, excluding root.
     *
     * @return string[]
     */
    public static function getPermittedUsers(): array
    {
        if (!self::isSupported()) {
            return [];
        }

        $members = [];
        $group = self::getDoorsGroup();
        if (function_exists('posix_getgrnam')) {
            $info = @posix_getgrnam($group);
            $members = is_array($info) ? ($info['members'] ?? []) : [];
        } elseif (is_readable('/etc/group')) {
            foreach (file('/etc/group', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $parts = explode(':', $line);
                if (($parts[0] ?? '') === $group) {
                    $members = array_filter(array_map('trim', explode(',', $parts[3] ?? '')));
                    break;
                }
            }
        }

        $users = array_values(array_filter($members, static function ($name) {
            return self::isValidName($name) && $name !== 'root';
        }));
        sort($users);
        return $users;
    }

    /**
     * Launcher sockets that currently exist in the socket directory.
     *
     * @return string[] Socket names (one per door user)
     */
    public static function getAvailableSockets(): array
    {
        if (!self::isSupported()) {
            return [];
        }

        $names = [];
        foreach (glob(self::getSocketDir() . '/*/launcher.sock') ?: [] as $path) {
            if (@filetype($path) !== 'socket') {
                continue;
            }
            $name = basename(dirname($path));
            if (self::isValidName($name)) {
                $names[] = $name;
            }
        }
        sort($names);
        return $names;
    }

    /**
     * Everything the admin UI needs to offer launch settings.
     *
     * @return array{supported: bool, modes: string[], global_mode: string, global_run_as: string, users: string[], sockets: string[], doors_group: string, socket_dir: string}
     */
    public static function getOptions(): array
    {
        return [
            'supported'     => self::isSupported(),
            'modes'         => self::MODES,
            'global_mode'   => (string)Config::env('NATIVEDOOR_LAUNCH_MODE', ''),
            'global_run_as' => (string)Config::env('NATIVEDOOR_RUN_AS_USER', ''),
            'users'         => self::getPermittedUsers(),
            'sockets'       => self::getAvailableSockets(),
            'doors_group'   => self::getDoorsGroup(),
            'socket_dir'    => self::getSocketDir(),
        ];
    }

    /**
     * Drop invalid launch settings from every door before the config is written.
     *
     * An unknown mode, a malformed or root user name, or a malformed socket name
     * is removed rather than saved. Removing a mode while another door uses a
     * non-direct mode makes the bridge refuse the door, which fails closed.
     *
     * @param array $config Full nativedoors.json structure
     * @return array Sanitized config
     */
    public static function sanitizeConfig(array $config): array
    {
        foreach ($config as $doorId => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (isset($entry['launch_mode']) && !in_array($entry['launch_mode'], self::MODES, true)) {
                unset($config[$doorId]['launch_mode']);
            }
            if (isset($entry['run_as']) && (!self::isValidName($entry['run_as']) || $entry['run_as'] === 'root')) {
                unset($config[$doorId]['run_as']);
            }
            if (isset($entry['launcher_socket']) && !self::isValidName($entry['launcher_socket'])) {
                unset($config[$doorId]['launcher_socket']);
            }
        }
        return $config;
    }
}
