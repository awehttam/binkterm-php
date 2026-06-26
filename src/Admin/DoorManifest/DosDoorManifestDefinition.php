<?php

namespace BinktermPHP\Admin\DoorManifest;

class DosDoorManifestDefinition implements DoorManifestTypeDefinition
{
    public function getTypeKey(): string
    {
        return 'dos';
    }

    public function getDisplayName(): string
    {
        return 'DOS Doors';
    }

    public function getRootDirectory(): string
    {
        return 'dosbox-bridge/dos/DOORS';
    }

    public function getManifestFilename(): string
    {
        return 'dosdoor.jsn';
    }

    public function getRuntimeConfigPath(): ?string
    {
        return 'config/dosdoors.json';
    }

    public function getAdminPageUrl(): string
    {
        return '/admin/dosdoors';
    }

    public function getAdminPageTitleKey(): string
    {
        return 'ui.admin.dosdoors_config.page_title';
    }

    public function getDefaultManifest(string $doorId): array
    {
        return [
            'version' => '1.0',
            'type'    => 'dosdoor',
            'managed' => 'web',
            'game'    => [
                'name'         => '',
                'short_name'   => '',
                'author'       => '',
                'version'      => '',
                'release_year' => null,
                'description'  => '',
                'genre'        => [],
                'icon'         => null,
                'screenshot'   => null,
            ],
            'door' => [
                'type'           => 'dos',
                'executable'     => '',
                'launch_command' => null,
                'directory'      => 'dosbox-bridge/dos/DOORS/' . strtoupper($doorId),
                'dropfile_format' => 'DOOR.SYS',
                'node_support'   => true,
                'max_nodes'      => 10,
                'fossil_required' => true,
                'ansi_required'  => false,
                'time_per_day'   => 30,
            ],
            'requirements' => [
                'dosbox'       => true,
                'fossil_driver' => true,
                'admin_only'   => false,
            ],
            'config' => [
                'enabled'            => false,
                'credit_cost'        => 0,
                'max_time_minutes'   => 30,
                'cpu_cycles'         => 10000,
                'max_sessions'       => 10,
            ],
        ];
    }

    public function getFieldSections(): array
    {
        return [
            [
                'key'       => 'game_info',
                'label_key' => 'ui.admin.door_manifest_editor.section.game_info',
                'fields'    => [
                    [
                        'path'      => 'game.name',
                        'label_key' => 'ui.admin.door_manifest_editor.field.game_name',
                        'type'      => 'text',
                        'required'  => true,
                        'default'   => '',
                    ],
                    [
                        'path'      => 'game.short_name',
                        'label_key' => 'ui.admin.door_manifest_editor.field.game_short_name',
                        'type'      => 'text',
                        'required'  => false,
                        'default'   => '',
                        'help_key'  => 'ui.admin.door_manifest_editor.field.game_short_name_help',
                    ],
                    [
                        'path'      => 'game.author',
                        'label_key' => 'ui.admin.door_manifest_editor.field.game_author',
                        'type'      => 'text',
                        'required'  => false,
                        'default'   => '',
                    ],
                    [
                        'path'      => 'game.version',
                        'label_key' => 'ui.admin.door_manifest_editor.field.game_version',
                        'type'      => 'text',
                        'required'  => false,
                        'default'   => '',
                    ],
                    [
                        'path'      => 'game.release_year',
                        'label_key' => 'ui.admin.door_manifest_editor.field.game_release_year',
                        'type'      => 'number',
                        'required'  => false,
                        'default'   => null,
                    ],
                    [
                        'path'      => 'game.description',
                        'label_key' => 'ui.admin.door_manifest_editor.field.game_description',
                        'type'      => 'textarea',
                        'required'  => false,
                        'default'   => '',
                    ],
                    [
                        'path'      => 'game.genre',
                        'label_key' => 'ui.admin.door_manifest_editor.field.game_genre',
                        'type'      => 'tags',
                        'required'  => false,
                        'default'   => [],
                        'help_key'  => 'ui.admin.door_manifest_editor.field.tags_help',
                    ],
                    [
                        'path'           => 'game.icon',
                        'label_key'      => 'ui.admin.door_manifest_editor.field.game_icon',
                        'type'           => 'file-picker',
                        'required'       => false,
                        'default'        => null,
                        'picker_profile' => 'imageAsset',
                    ],
                    [
                        'path'           => 'game.screenshot',
                        'label_key'      => 'ui.admin.door_manifest_editor.field.game_screenshot',
                        'type'           => 'file-picker',
                        'required'       => false,
                        'default'        => null,
                        'picker_profile' => 'imageAsset',
                    ],
                ],
            ],
            [
                'key'       => 'door_setup',
                'label_key' => 'ui.admin.door_manifest_editor.section.door_setup',
                'fields'    => [
                    [
                        'path'           => 'door.executable',
                        'label_key'      => 'ui.admin.door_manifest_editor.field.dos_executable',
                        'type'           => 'file-picker',
                        'required'       => true,
                        'default'        => '',
                        'picker_profile' => 'dosExecutable',
                        'help_key'       => 'ui.admin.door_manifest_editor.field.dos_executable_help',
                    ],
                    [
                        'path'      => 'door.launch_command',
                        'label_key' => 'ui.admin.door_manifest_editor.field.launch_command',
                        'type'      => 'text',
                        'required'  => false,
                        'default'   => null,
                        'help_key'  => 'ui.admin.door_manifest_editor.field.launch_command_help',
                    ],
                    [
                        'path'      => 'door.dropfile_format',
                        'label_key' => 'ui.admin.door_manifest_editor.field.dropfile_format',
                        'type'      => 'select',
                        'required'  => true,
                        'default'   => 'DOOR.SYS',
                        'options'   => [
                            ['value' => 'DOOR.SYS', 'label_key' => 'ui.admin.door_manifest_editor.dropfile.doorsys'],
                        ],
                    ],
                    [
                        'path'      => 'door.fossil_required',
                        'label_key' => 'ui.admin.door_manifest_editor.field.fossil_required',
                        'type'      => 'checkbox',
                        'required'  => false,
                        'default'   => true,
                    ],
                    [
                        'path'      => 'door.ansi_required',
                        'label_key' => 'ui.admin.door_manifest_editor.field.ansi_required',
                        'type'      => 'checkbox',
                        'required'  => false,
                        'default'   => false,
                    ],
                    [
                        'path'      => 'door.max_nodes',
                        'label_key' => 'ui.admin.door_manifest_editor.field.max_nodes',
                        'type'      => 'number',
                        'required'  => false,
                        'default'   => 10,
                    ],
                    [
                        'path'      => 'door.time_per_day',
                        'label_key' => 'ui.admin.door_manifest_editor.field.time_per_day',
                        'type'      => 'number',
                        'required'  => false,
                        'default'   => 30,
                        'help_key'  => 'ui.admin.door_manifest_editor.field.time_per_day_help',
                    ],
                ],
            ],
            [
                'key'       => 'runtime_defaults',
                'label_key' => 'ui.admin.door_manifest_editor.section.runtime_defaults',
                'fields'    => [
                    [
                        'path'      => 'config.credit_cost',
                        'label_key' => 'ui.admin.door_manifest_editor.field.credit_cost',
                        'type'      => 'number',
                        'required'  => false,
                        'default'   => 0,
                        'help_key'  => 'ui.admin.door_manifest_editor.field.credit_cost_help',
                    ],
                    [
                        'path'      => 'config.max_time_minutes',
                        'label_key' => 'ui.admin.door_manifest_editor.field.max_time_minutes',
                        'type'      => 'number',
                        'required'  => false,
                        'default'   => 30,
                    ],
                    [
                        'path'      => 'config.cpu_cycles',
                        'label_key' => 'ui.admin.door_manifest_editor.field.cpu_cycles',
                        'type'      => 'number',
                        'required'  => false,
                        'default'   => 10000,
                        'help_key'  => 'ui.admin.door_manifest_editor.field.cpu_cycles_help',
                    ],
                    [
                        'path'      => 'config.max_sessions',
                        'label_key' => 'ui.admin.door_manifest_editor.field.max_sessions',
                        'type'      => 'number',
                        'required'  => false,
                        'default'   => 10,
                    ],
                ],
            ],
            [
                'key'       => 'requirements',
                'label_key' => 'ui.admin.door_manifest_editor.section.requirements',
                'fields'    => [
                    [
                        'path'      => 'requirements.admin_only',
                        'label_key' => 'ui.admin.door_manifest_editor.field.admin_only',
                        'type'      => 'checkbox',
                        'required'  => false,
                        'default'   => false,
                        'help_key'  => 'ui.admin.door_manifest_editor.field.admin_only_help',
                    ],
                    [
                        'path'      => 'requirements.dosbox',
                        'label_key' => 'ui.admin.door_manifest_editor.field.req_dosbox',
                        'type'      => 'checkbox',
                        'required'  => false,
                        'default'   => true,
                    ],
                    [
                        'path'      => 'requirements.fossil_driver',
                        'label_key' => 'ui.admin.door_manifest_editor.field.req_fossil',
                        'type'      => 'checkbox',
                        'required'  => false,
                        'default'   => true,
                    ],
                ],
            ],
        ];
    }

    public function validateForSave(string $doorId, array $manifest, ?array $existingManifest = null): array
    {
        $errors = [];

        if (($manifest['type'] ?? '') !== 'dosdoor') {
            $errors[] = 'type must be "dosdoor"';
        }

        if (empty($manifest['game']['name'])) {
            $errors[] = 'game.name is required';
        }

        $executable = trim((string)($manifest['door']['executable'] ?? ''));
        if ($executable === '') {
            $errors[] = 'door.executable is required';
        }

        $cycles = $manifest['config']['cpu_cycles'] ?? null;
        if ($cycles !== null && (!is_numeric($cycles) || (int)$cycles < 100)) {
            $errors[] = 'config.cpu_cycles must be a number >= 100';
        }

        return $errors;
    }

    public function getFilePickerProfiles(): array
    {
        return [
            'dosExecutable' => [
                'label_key'          => 'ui.admin.door_manifest_editor.picker.dos_executable',
                'allowed_extensions' => ['exe', 'bat', 'com'],
            ],
            'imageAsset' => [
                'label_key'          => 'ui.admin.door_manifest_editor.picker.image_asset',
                'allowed_extensions' => ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'],
            ],
        ];
    }
}
