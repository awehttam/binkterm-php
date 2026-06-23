<?php

namespace BinktermPHP\Admin\DoorManifest;

class NativeDoorManifestDefinition implements DoorManifestTypeDefinition
{
    public function getTypeKey(): string
    {
        return 'native';
    }

    public function getDisplayName(): string
    {
        return 'Native Doors';
    }

    public function getRootDirectory(): string
    {
        return 'native-doors/doors';
    }

    public function getManifestFilename(): string
    {
        return 'nativedoor.json';
    }

    public function getRuntimeConfigPath(): ?string
    {
        return 'config/nativedoors.json';
    }

    public function getAdminPageUrl(): string
    {
        return '/admin/native-doors';
    }

    public function getAdminPageTitleKey(): string
    {
        return 'ui.admin.nativedoors_config.page_title';
    }

    public function getDefaultManifest(string $doorId): array
    {
        return [
            'version' => '1.0',
            'type'    => 'nativedoor',
            'managed' => 'web',
            'game'    => [
                'name'         => '',
                'short_name'   => '',
                'author'       => '',
                'version'      => '',
                'release_year' => null,
                'description'  => '',
                'genre'        => [],
                'players'      => null,
                'icon'         => null,
                'screenshot'   => null,
            ],
            'door' => [
                'executable'             => '',
                'launch_command'         => null,
                'launch_command_windows' => null,
                'dropfile_format'        => 'DOOR.SYS',
                'output_encoding'        => 'utf8',
                'max_nodes'              => 10,
                'ansi_required'          => true,
                'time_per_day'           => 30,
            ],
            'requirements' => [
                'admin_only' => false,
            ],
            'config' => [
                'enabled'           => false,
                'credit_cost'       => 0,
                'max_time_minutes'  => 30,
                'max_sessions'      => 10,
                'allow_anonymous'   => false,
                'guest_max_sessions' => 2,
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
                ],
            ],
            [
                'key'       => 'door_setup',
                'label_key' => 'ui.admin.door_manifest_editor.section.door_setup',
                'fields'    => [
                    [
                        'path'           => 'door.executable',
                        'label_key'      => 'ui.admin.door_manifest_editor.field.native_executable',
                        'type'           => 'file-picker',
                        'required'       => true,
                        'default'        => '',
                        'picker_profile' => 'nativeExecutable',
                        'help_key'       => 'ui.admin.door_manifest_editor.field.native_executable_help',
                    ],
                    [
                        'path'      => 'door.launch_command',
                        'label_key' => 'ui.admin.door_manifest_editor.field.launch_command',
                        'type'      => 'text',
                        'required'  => false,
                        'default'   => null,
                        'help_key'  => 'ui.admin.door_manifest_editor.field.native_launch_command_help',
                    ],
                    [
                        'path'      => 'door.dropfile_format',
                        'label_key' => 'ui.admin.door_manifest_editor.field.dropfile_format',
                        'type'      => 'select',
                        'required'  => true,
                        'default'   => 'DOOR.SYS',
                        'options'   => [
                            ['value' => 'DOOR.SYS',   'label_key' => 'ui.admin.door_manifest_editor.dropfile.doorsys'],
                            ['value' => 'DOOR32.SYS', 'label_key' => 'ui.admin.door_manifest_editor.dropfile.door32sys'],
                        ],
                    ],
                    [
                        'path'      => 'door.output_encoding',
                        'label_key' => 'ui.admin.door_manifest_editor.field.output_encoding',
                        'type'      => 'select',
                        'required'  => false,
                        'default'   => 'utf8',
                        'options'   => [
                            ['value' => 'utf8',  'label_key' => 'ui.admin.door_manifest_editor.encoding.utf8'],
                            ['value' => 'cp437', 'label_key' => 'ui.admin.door_manifest_editor.encoding.cp437'],
                        ],
                    ],
                    [
                        'path'      => 'door.ansi_required',
                        'label_key' => 'ui.admin.door_manifest_editor.field.ansi_required',
                        'type'      => 'checkbox',
                        'required'  => false,
                        'default'   => true,
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
                        'path'      => 'config.max_sessions',
                        'label_key' => 'ui.admin.door_manifest_editor.field.max_sessions',
                        'type'      => 'number',
                        'required'  => false,
                        'default'   => 10,
                    ],
                    [
                        'path'      => 'config.allow_anonymous',
                        'label_key' => 'ui.admin.door_manifest_editor.field.allow_anonymous',
                        'type'      => 'checkbox',
                        'required'  => false,
                        'default'   => false,
                        'help_key'  => 'ui.admin.door_manifest_editor.field.allow_anonymous_help',
                    ],
                    [
                        'path'      => 'config.guest_max_sessions',
                        'label_key' => 'ui.admin.door_manifest_editor.field.guest_max_sessions',
                        'type'      => 'number',
                        'required'  => false,
                        'default'   => 2,
                        'help_key'  => 'ui.admin.door_manifest_editor.field.guest_max_sessions_help',
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
                ],
            ],
        ];
    }

    public function validateForSave(string $doorId, array $manifest, ?array $existingManifest = null): array
    {
        $errors = [];

        if (($manifest['type'] ?? '') !== 'nativedoor') {
            $errors[] = 'type must be "nativedoor"';
        }

        if (empty($manifest['game']['name'])) {
            $errors[] = 'game.name is required';
        }

        $executable = trim((string)($manifest['door']['executable'] ?? ''));
        if ($executable === '') {
            $errors[] = 'door.executable is required';
        }

        return $errors;
    }

    public function getFilePickerProfiles(): array
    {
        return [
            'nativeExecutable' => [
                'label_key'          => 'ui.admin.door_manifest_editor.picker.native_executable',
                'allowed_extensions' => null, // any file — the runtime checks execution permission
            ],
            'imageAsset' => [
                'label_key'          => 'ui.admin.door_manifest_editor.picker.image_asset',
                'allowed_extensions' => ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'],
            ],
        ];
    }
}
