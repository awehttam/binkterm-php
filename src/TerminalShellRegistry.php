<?php

namespace BinktermPHP;

/**
 * Registry and discovery layer for terminal shell implementations.
 *
 * Built-in shells are always registered. Additional shell providers can be
 * added by dropping *.plugin.php files into telnet/shells/. Each provider file must
 * return either one definition array or a list of definition arrays.
 */
class TerminalShellRegistry
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $definitions = null;

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getRegisteredShells(): array
    {
        if (self::$definitions !== null) {
            return self::$definitions;
        }

        self::ensureBaseShellClassesLoaded();

        $definitions = [];
        foreach (self::getBuiltInDefinitions() as $definition) {
            self::storeDefinition($definitions, $definition);
        }

        foreach (self::loadPluginDefinitions() as $definition) {
            self::storeDefinition($definitions, $definition);
        }

        self::$definitions = $definitions;
        return self::$definitions;
    }

    /**
     * @return array<int, string>
     */
    public static function getRegisteredShellIds(): array
    {
        return array_keys(self::getRegisteredShells());
    }

    /**
     * @param array<int, string>|null $ids
     * @return array<int, array<string, mixed>>
     */
    public static function getShellDefinitions(?array $ids = null): array
    {
        $registered = self::getRegisteredShells();
        if ($ids === null) {
            return array_values($registered);
        }

        $result = [];
        foreach ($ids as $id) {
            if (isset($registered[$id])) {
                $result[] = $registered[$id];
            }
        }
        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getShellDefinition(string $id): ?array
    {
        $registered = self::getRegisteredShells();
        return $registered[$id] ?? null;
    }

    /**
     * Instantiate a registered shell by id.
     *
     * @param object $server
     * @return object|null
     */
    public static function createShell(string $id, $server): ?object
    {
        $definition = self::getShellDefinition($id);
        if ($definition === null) {
            return null;
        }

        $class = (string)($definition['class'] ?? '');
        if ($class === '' || !class_exists($class)) {
            return null;
        }

        $shell = new $class($server);
        return $shell instanceof \BinktermPHP\TelnetServer\TerminalShellInterface
            ? $shell
            : null;
    }

    /**
     * Forget cached plugin definitions. Useful after adding/removing plugin files.
     */
    public static function reload(): void
    {
        self::$definitions = null;
    }

    private static function ensureBaseShellClassesLoaded(): void
    {
        require_once dirname(__DIR__) . '/telnet/src/TerminalShellInterface.php';
        require_once dirname(__DIR__) . '/telnet/src/TuiShell.php';
        require_once dirname(__DIR__) . '/telnet/src/LineShell.php';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function getBuiltInDefinitions(): array
    {
        return [
            [
                'id' => 'tui',
                'class' => \BinktermPHP\TelnetServer\TuiShell::class,
                'admin_label_key' => 'ui.admin.bbs_settings.terminal_server.shell_tui',
                'admin_label' => 'Full-screen TUI',
                'settings_label_key' => 'ui.terminalserver.settings.terminal.shell_mode_tui',
                'settings_label' => 'Full-screen TUI (always)',
                'source' => 'builtin',
            ],
            [
                'id' => 'line',
                'class' => \BinktermPHP\TelnetServer\LineShell::class,
                'admin_label_key' => 'ui.admin.bbs_settings.terminal_server.shell_line',
                'admin_label' => 'Line mode',
                'settings_label_key' => 'ui.terminalserver.settings.terminal.shell_mode_line',
                'settings_label' => 'Line mode',
                'source' => 'builtin',
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function loadPluginDefinitions(): array
    {
        $dir = dirname(__DIR__) . '/telnet/shells';
        if (!is_dir($dir)) {
            return [];
        }

        $definitions = [];
        $files = glob($dir . '/*.plugin.php') ?: [];
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($files as $file) {
            $raw = require $file;
            foreach (self::normalizePluginReturn($raw, $file) as $definition) {
                $definitions[] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * @param mixed $raw
     * @return array<int, array<string, mixed>>
     */
    private static function normalizePluginReturn($raw, string $file): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $definitions = [];
        $isSingle = array_key_exists('id', $raw) || array_key_exists('class', $raw);
        $rows = $isSingle ? [$raw] : $raw;

        foreach ($rows as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $definition = self::normalizeDefinition($candidate, $file);
            if ($definition !== null) {
                $definitions[] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>|null
     */
    private static function normalizeDefinition(array $definition, string $file): ?array
    {
        $id = strtolower(trim((string)($definition['id'] ?? '')));
        $class = trim((string)($definition['class'] ?? ''));
        if ($id === '' || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $id)) {
            return null;
        }
        if ($class === '') {
            return null;
        }

        $label = trim((string)($definition['label'] ?? ''));
        $adminLabel = trim((string)($definition['admin_label'] ?? $label));
        $settingsLabel = trim((string)($definition['settings_label'] ?? $label));

        if ($adminLabel === '' || $settingsLabel === '') {
            return null;
        }

        return [
            'id' => $id,
            'class' => $class,
            'admin_label_key' => trim((string)($definition['admin_label_key'] ?? '')),
            'admin_label' => $adminLabel,
            'settings_label_key' => trim((string)($definition['settings_label_key'] ?? '')),
            'settings_label' => $settingsLabel,
            'source' => $definition['source'] ?? ('plugin:' . basename($file)),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, mixed> $definition
     */
    private static function storeDefinition(array &$definitions, array $definition): void
    {
        $id = (string)$definition['id'];
        if (isset($definitions[$id])) {
            return;
        }

        $definitions[$id] = $definition;
    }
}
