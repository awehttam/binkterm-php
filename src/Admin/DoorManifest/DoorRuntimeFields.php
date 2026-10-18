<?php

namespace BinktermPHP\Admin\DoorManifest;

/**
 * Describes the per-door runtime settings stored in config/{type}doors.json so
 * the manifest editor can show and save them alongside the manifest.
 *
 * Runtime field paths are prefixed with "runtime." in the editor form; the
 * prefix is stripped when the values are merged into the config file.
 */
class DoorRuntimeFields
{
    public const PATH_PREFIX = 'runtime.';

    /**
     * Allowed runtime keys per door type, mapped to their value type.
     *
     * @return array<string,string> key => bool|int|string
     */
    public static function getAllowedKeys(string $doorType): array
    {
        $keys = ['enabled' => 'bool'];

        switch ($doorType) {
            case 'dos':
                $keys += [
                    'credit_cost' => 'int',
                    'max_time_minutes' => 'int',
                    'cpu_cycles' => 'int',
                    'max_concurrent_sessions' => 'int',
                    'hide_from_web' => 'bool',
                ];
                break;
            case 'native':
                $keys += [
                    'credit_cost' => 'int',
                    'max_time_minutes' => 'int',
                    'max_concurrent_sessions' => 'int',
                    'allow_anonymous' => 'bool',
                    'guest_max_sessions' => 'int',
                    'hide_from_web' => 'bool',
                ];
                break;
            case 'jsdos':
                $keys += [
                    'display_name' => 'string',
                    'display_description' => 'string',
                ];
                break;
        }

        return $keys;
    }

    /**
     * Build the editor form section for a door type's runtime settings.
     *
     * @return array Section definition in the same shape as getFieldSections().
     */
    public static function getSection(string $doorType): array
    {
        $p = 'ui.admin.door_manifest_editor.field.';
        $defs = [
            'enabled' => ['label_key' => $p . 'runtime_enabled', 'type' => 'checkbox', 'default' => false,
                'help_key' => $p . 'runtime_enabled_help'],
            'display_name' => ['label_key' => $p . 'runtime_display_name', 'type' => 'text', 'default' => '',
                'help_key' => $p . 'runtime_display_name_help'],
            'display_description' => ['label_key' => $p . 'runtime_display_description', 'type' => 'textarea', 'default' => ''],
            'credit_cost' => ['label_key' => $p . 'credit_cost', 'type' => 'number', 'default' => 0,
                'help_key' => $p . 'credit_cost_help'],
            'max_time_minutes' => ['label_key' => $p . 'max_time_minutes', 'type' => 'number', 'default' => 30],
            'max_concurrent_sessions' => ['label_key' => $p . 'max_sessions', 'type' => 'number', 'default' => 10],
            'cpu_cycles' => ['label_key' => $p . 'cpu_cycles', 'type' => 'number', 'default' => 10000,
                'help_key' => $p . 'cpu_cycles_help'],
            'allow_anonymous' => ['label_key' => $p . 'allow_anonymous', 'type' => 'checkbox', 'default' => false,
                'help_key' => $p . 'allow_anonymous_help', 'checkbox_label_key' => $p . 'allow_anonymous'],
            'guest_max_sessions' => ['label_key' => $p . 'guest_max_sessions', 'type' => 'number', 'default' => 2,
                'help_key' => $p . 'guest_max_sessions_help'],
            'hide_from_web' => ['label_key' => $p . 'runtime_hide_from_web', 'type' => 'checkbox', 'default' => false,
                'help_key' => $p . 'runtime_hide_from_web_help', 'checkbox_label_key' => $p . 'runtime_hide_from_web'],
        ];

        $fields = [];
        foreach (array_keys(self::getAllowedKeys($doorType)) as $key) {
            $fields[] = ['path' => self::PATH_PREFIX . $key, 'required' => false] + $defs[$key];
        }

        return [
            'key' => 'runtime',
            'label_key' => 'ui.admin.door_manifest_editor.section.runtime',
            'fields' => $fields,
        ];
    }

    /**
     * Merge submitted runtime values into an existing config entry.
     *
     * Only whitelisted keys are applied; keys the form does not know about
     * (e.g. webdoor game config) are preserved. Empty strings remove string keys.
     *
     * @param array $existing Current config entry for the door.
     * @param array $submitted Values posted by the editor.
     * @return array The merged entry.
     * @throws \InvalidArgumentException When a value is invalid.
     */
    public static function merge(string $doorType, array $existing, array $submitted): array
    {
        foreach (self::getAllowedKeys($doorType) as $key => $type) {
            if (!array_key_exists($key, $submitted)) {
                continue;
            }
            $value = $submitted[$key];

            if ($type === 'bool') {
                $existing[$key] = (bool)$value;
            } elseif ($type === 'int') {
                if ($value === null || $value === '') {
                    unset($existing[$key]);
                    continue;
                }
                if (!is_numeric($value) || (int)$value < 0) {
                    throw new \InvalidArgumentException("Invalid value for {$key}");
                }
                $existing[$key] = (int)$value;
            } else {
                $value = trim((string)$value);
                if ($value === '') {
                    unset($existing[$key]);
                } else {
                    $existing[$key] = $value;
                }
            }
        }

        return $existing;
    }
}
