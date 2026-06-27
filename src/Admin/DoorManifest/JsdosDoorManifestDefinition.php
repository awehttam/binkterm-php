<?php

namespace BinktermPHP\Admin\DoorManifest;

class JsdosDoorManifestDefinition implements DoorManifestTypeDefinition
{
    public function getTypeKey(): string
    {
        return 'jsdos';
    }

    public function getDisplayName(): string
    {
        return 'JS-DOS Doors';
    }

    public function getRootDirectory(): string
    {
        return 'public_html/jsdos-doors';
    }

    public function getManifestFilename(): string
    {
        return 'jsdosdoor.json';
    }

    public function getRuntimeConfigPath(): ?string
    {
        return 'config/jsdosdoors.json';
    }

    public function getAdminPageUrl(): string
    {
        return '/admin/jsdosdoors';
    }

    public function getAdminPageTitleKey(): string
    {
        return 'ui.admin.jsdosdoors_config.page_title';
    }

    public function getDefaultManifest(string $doorId): array
    {
        return [
            'id'          => $doorId,
            'name'        => '',
            'version'     => '1.0',
            'author'      => '',
            'description' => '',
            'icon'        => null,
            'emulator'    => 'jsdos',
            'managed'     => 'web',
            'emulator_config' => [
                'output'     => 'surface',
                'autolock'   => false,
                'cpu_cycles' => 'auto',
                'memory_mb'  => 16,
                'machine'    => 'svga_s3',
                'game_files' => [],
                'autoexec'   => [],
            ],
            'saves' => [
                'enabled'    => false,
                'scope'      => 'user',
                'save_paths' => [],
                'max_size_kb' => 512,
            ],
            'credits' => [
                'session_cost' => 0,
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
                        'path'      => 'name',
                        'label_key' => 'ui.admin.door_manifest_editor.field.game_name',
                        'type'      => 'text',
                        'required'  => true,
                        'default'   => '',
                    ],
                    [
                        'path'      => 'author',
                        'label_key' => 'ui.admin.door_manifest_editor.field.game_author',
                        'type'      => 'text',
                        'required'  => false,
                        'default'   => '',
                    ],
                    [
                        'path'      => 'version',
                        'label_key' => 'ui.admin.door_manifest_editor.field.game_version',
                        'type'      => 'text',
                        'required'  => false,
                        'default'   => '1.0',
                    ],
                    [
                        'path'      => 'description',
                        'label_key' => 'ui.admin.door_manifest_editor.field.game_description',
                        'type'      => 'textarea',
                        'required'  => false,
                        'default'   => '',
                    ],
                    [
                        'path'           => 'icon',
                        'label_key'      => 'ui.admin.door_manifest_editor.field.game_icon',
                        'type'           => 'file-picker',
                        'required'       => false,
                        'default'        => null,
                        'picker_profile' => 'imageAsset',
                    ],
                ],
            ],
            [
                'key'       => 'emulator_config',
                'label_key' => 'ui.admin.door_manifest_editor.section.emulator_config',
                'fields'    => [
                    [
                        'path'      => 'emulator_config.output',
                        'label_key' => 'ui.admin.door_manifest_editor.field.jsdos_output',
                        'type'      => 'select',
                        'required'  => false,
                        'default'   => 'surface',
                        'options'   => [
                            ['value' => 'surface',  'label_key' => 'ui.admin.door_manifest_editor.jsdos_output.surface'],
                            ['value' => 'canvas',   'label_key' => 'ui.admin.door_manifest_editor.jsdos_output.canvas'],
                            ['value' => 'webgl',    'label_key' => 'ui.admin.door_manifest_editor.jsdos_output.webgl'],
                        ],
                    ],
                    [
                        'path'      => 'emulator_config.cpu_cycles',
                        'label_key' => 'ui.admin.door_manifest_editor.field.cpu_cycles',
                        'type'      => 'text',
                        'required'  => false,
                        'default'   => 'auto',
                        'help_key'  => 'ui.admin.door_manifest_editor.field.jsdos_cpu_cycles_help',
                    ],
                    [
                        'path'      => 'emulator_config.memory_mb',
                        'label_key' => 'ui.admin.door_manifest_editor.field.jsdos_memory_mb',
                        'type'      => 'number',
                        'required'  => false,
                        'default'   => 16,
                    ],
                    [
                        'path'      => 'emulator_config.machine',
                        'label_key' => 'ui.admin.door_manifest_editor.field.jsdos_machine',
                        'type'      => 'select',
                        'required'  => false,
                        'default'   => 'svga_s3',
                        'options'   => [
                            ['value' => 'svga_s3',  'label_key' => 'ui.admin.door_manifest_editor.jsdos_machine.svga_s3'],
                            ['value' => 'vgaonly',  'label_key' => 'ui.admin.door_manifest_editor.jsdos_machine.vgaonly'],
                            ['value' => 'ega',      'label_key' => 'ui.admin.door_manifest_editor.jsdos_machine.ega'],
                            ['value' => 'cga',      'label_key' => 'ui.admin.door_manifest_editor.jsdos_machine.cga'],
                        ],
                    ],
                    [
                        'path'      => 'emulator_config.autolock',
                        'label_key' => 'ui.admin.door_manifest_editor.field.jsdos_autolock',
                        'type'      => 'checkbox',
                        'required'  => false,
                        'default'   => false,
                        'help_key'  => 'ui.admin.door_manifest_editor.field.jsdos_autolock_help',
                    ],
                ],
            ],
            [
                'key'       => 'game_files',
                'label_key' => 'ui.admin.door_manifest_editor.section.game_files',
                'fields'    => [
                    [
                        'path'       => 'emulator_config.game_files',
                        'label_key'  => 'ui.admin.door_manifest_editor.field.jsdos_game_files',
                        'type'       => 'repeater',
                        'required'   => false,
                        'default'    => [],
                        'help_key'   => 'ui.admin.door_manifest_editor.field.jsdos_game_files_help',
                        'sub_fields' => [
                            [
                                'path'           => 'asset_path',
                                'label_key'      => 'ui.admin.door_manifest_editor.field.jsdos_asset_path',
                                'type'           => 'file-picker',
                                'required'       => true,
                                'picker_profile' => 'jsdosAsset',
                            ],
                            [
                                'path'      => 'dos_path',
                                'label_key' => 'ui.admin.door_manifest_editor.field.jsdos_dos_path',
                                'type'      => 'text',
                                'required'  => true,
                                'help_key'  => 'ui.admin.door_manifest_editor.field.jsdos_dos_path_help',
                            ],
                        ],
                    ],
                    [
                        'path'      => 'emulator_config.autoexec',
                        'label_key' => 'ui.admin.door_manifest_editor.field.jsdos_autoexec',
                        'type'      => 'array-text',
                        'required'  => false,
                        'default'   => [],
                        'help_key'  => 'ui.admin.door_manifest_editor.field.jsdos_autoexec_help',
                    ],
                ],
            ],
            [
                'key'       => 'saves',
                'label_key' => 'ui.admin.door_manifest_editor.section.saves',
                'fields'    => [
                    [
                        'path'      => 'saves.enabled',
                        'label_key' => 'ui.admin.door_manifest_editor.field.saves_enabled',
                        'type'      => 'checkbox',
                        'required'  => false,
                        'default'   => false,
                    ],
                    [
                        'path'      => 'saves.scope',
                        'label_key' => 'ui.admin.door_manifest_editor.field.saves_scope',
                        'type'      => 'select',
                        'required'  => false,
                        'default'   => 'user',
                        'options'   => [
                            ['value' => 'user',   'label_key' => 'ui.admin.door_manifest_editor.saves_scope.user'],
                            ['value' => 'shared', 'label_key' => 'ui.admin.door_manifest_editor.saves_scope.shared'],
                        ],
                        'help_key'  => 'ui.admin.door_manifest_editor.field.saves_scope_help',
                    ],
                    [
                        'path'      => 'saves.max_size_kb',
                        'label_key' => 'ui.admin.door_manifest_editor.field.saves_max_size_kb',
                        'type'      => 'number',
                        'required'  => false,
                        'default'   => 512,
                    ],
                    [
                        'path'      => 'saves.save_paths',
                        'label_key' => 'ui.admin.door_manifest_editor.field.saves_paths',
                        'type'      => 'array-text',
                        'required'  => false,
                        'default'   => [],
                        'help_key'  => 'ui.admin.door_manifest_editor.field.saves_paths_help',
                    ],
                ],
            ],
            [
                'key'       => 'credits',
                'label_key' => 'ui.admin.door_manifest_editor.section.credits',
                'fields'    => [
                    [
                        'path'      => 'credits.session_cost',
                        'label_key' => 'ui.admin.door_manifest_editor.field.session_cost',
                        'type'      => 'number',
                        'required'  => false,
                        'default'   => 0,
                        'help_key'  => 'ui.admin.door_manifest_editor.field.session_cost_help',
                    ],
                ],
            ],
        ];
    }

    public function validateForSave(string $doorId, array $manifest, ?array $existingManifest = null): array
    {
        $errors = [];

        if (($manifest['emulator'] ?? '') !== 'jsdos') {
            $errors[] = 'emulator must be "jsdos"';
        }

        if (empty($manifest['name'])) {
            $errors[] = 'name is required';
        }

        // Validate game_files entries
        $gameFiles = $manifest['emulator_config']['game_files'] ?? [];
        foreach ($gameFiles as $i => $entry) {
            if (empty($entry['asset_path'])) {
                $errors[] = "emulator_config.game_files[{$i}].asset_path is required";
            }
            if (empty($entry['dos_path'])) {
                $errors[] = "emulator_config.game_files[{$i}].dos_path is required";
            }
            // Reject absolute or traversal DOS paths
            $dosPath = (string)($entry['dos_path'] ?? '');
            if (str_contains($dosPath, '..')) {
                $errors[] = "emulator_config.game_files[{$i}].dos_path must not contain '..'";
            }
        }

        return $errors;
    }

    public function getFilePickerProfiles(): array
    {
        return [
            'jsdosAsset' => [
                'label_key'          => 'ui.admin.door_manifest_editor.picker.jsdos_asset',
                'allowed_extensions' => null, // any static asset
            ],
            'imageAsset' => [
                'label_key'          => 'ui.admin.door_manifest_editor.picker.image_asset',
                'allowed_extensions' => ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'],
            ],
        ];
    }
}
