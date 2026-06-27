<?php

namespace BinktermPHP\Admin\DoorManifest;

class WebDoorManifestDefinition implements DoorManifestTypeDefinition
{
    public function getTypeKey(): string
    {
        return 'web';
    }

    public function getDisplayName(): string
    {
        return 'WebDoors';
    }

    public function getRootDirectory(): string
    {
        return 'public_html/webdoors';
    }

    public function getManifestFilename(): string
    {
        return 'webdoor.json';
    }

    public function getRuntimeConfigPath(): ?string
    {
        return 'config/webdoors.json';
    }

    public function getAdminPageUrl(): string
    {
        return '/admin/webdoors';
    }

    public function getAdminPageTitleKey(): string
    {
        return 'ui.admin.webdoors_config.page_title';
    }

    public function getDefaultManifest(string $doorId): array
    {
        return [
            'webdoor_version' => '1.0',
            'managed'         => 'web',
            'game'            => [
                'id'          => $doorId,
                'name'        => '',
                'version'     => '1.0',
                'author'      => '',
                'description' => '',
                'entry_point' => 'index.html',
                'icon'        => null,
                'screenshots' => [],
            ],
            'requirements' => [
                'min_host_version' => null,
                'features'         => [],
                'permissions'      => [],
            ],
            'storage' => [
                'max_size_kb' => 1024,
                'save_slots'  => 1,
            ],
            'multiplayer' => [
                'enabled' => false,
            ],
            'config' => [],
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
                        'path'      => 'game.version',
                        'label_key' => 'ui.admin.door_manifest_editor.field.game_version',
                        'type'      => 'text',
                        'required'  => false,
                        'default'   => '1.0',
                    ],
                    [
                        'path'      => 'game.author',
                        'label_key' => 'ui.admin.door_manifest_editor.field.game_author',
                        'type'      => 'text',
                        'required'  => false,
                        'default'   => '',
                    ],
                    [
                        'path'      => 'game.description',
                        'label_key' => 'ui.admin.door_manifest_editor.field.game_description',
                        'type'      => 'textarea',
                        'required'  => false,
                        'default'   => '',
                    ],
                    [
                        'path'           => 'game.entry_point',
                        'label_key'      => 'ui.admin.door_manifest_editor.field.web_entry_point',
                        'type'           => 'file-picker',
                        'required'       => true,
                        'default'        => 'index.html',
                        'picker_profile' => 'webEntryPoint',
                        'help_key'       => 'ui.admin.door_manifest_editor.field.web_entry_point_help',
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
                        'path'      => 'game.screenshots',
                        'label_key' => 'ui.admin.door_manifest_editor.field.game_screenshots',
                        'type'      => 'array-text',
                        'required'  => false,
                        'default'   => [],
                        'help_key'  => 'ui.admin.door_manifest_editor.field.game_screenshots_help',
                    ],
                ],
            ],
            [
                'key'       => 'requirements',
                'label_key' => 'ui.admin.door_manifest_editor.section.requirements',
                'fields'    => [
                    [
                        'path'      => 'requirements.min_host_version',
                        'label_key' => 'ui.admin.door_manifest_editor.field.min_host_version',
                        'type'      => 'text',
                        'required'  => false,
                        'default'   => null,
                        'help_key'  => 'ui.admin.door_manifest_editor.field.min_host_version_help',
                    ],
                    [
                        'path'      => 'requirements.features',
                        'label_key' => 'ui.admin.door_manifest_editor.field.req_features',
                        'type'      => 'tags',
                        'required'  => false,
                        'default'   => [],
                        'help_key'  => 'ui.admin.door_manifest_editor.field.req_features_help',
                    ],
                ],
            ],
            [
                'key'       => 'storage',
                'label_key' => 'ui.admin.door_manifest_editor.section.storage',
                'fields'    => [
                    [
                        'path'      => 'storage.max_size_kb',
                        'label_key' => 'ui.admin.door_manifest_editor.field.storage_max_size_kb',
                        'type'      => 'number',
                        'required'  => false,
                        'default'   => 1024,
                    ],
                    [
                        'path'      => 'storage.save_slots',
                        'label_key' => 'ui.admin.door_manifest_editor.field.storage_save_slots',
                        'type'      => 'number',
                        'required'  => false,
                        'default'   => 1,
                    ],
                ],
            ],
            [
                'key'       => 'multiplayer',
                'label_key' => 'ui.admin.door_manifest_editor.section.multiplayer',
                'fields'    => [
                    [
                        'path'      => 'multiplayer.enabled',
                        'label_key' => 'ui.admin.door_manifest_editor.field.multiplayer_enabled',
                        'type'      => 'checkbox',
                        'required'  => false,
                        'default'   => false,
                    ],
                ],
            ],
            [
                'key'       => 'config',
                'label_key' => 'ui.admin.door_manifest_editor.section.web_config',
                'fields'    => [
                    [
                        'path'      => 'config',
                        'label_key' => 'ui.admin.door_manifest_editor.field.web_config',
                        'type'      => 'key-value',
                        'required'  => false,
                        'default'   => [],
                        'help_key'  => 'ui.admin.door_manifest_editor.field.web_config_help',
                    ],
                ],
            ],
        ];
    }

    public function validateForSave(string $doorId, array $manifest, ?array $existingManifest = null): array
    {
        $errors = [];

        if (empty($manifest['webdoor_version'])) {
            $errors[] = 'webdoor_version is required';
        }

        if (empty($manifest['game']['name'])) {
            $errors[] = 'game.name is required';
        }

        $entryPoint = trim((string)($manifest['game']['entry_point'] ?? ''));
        if ($entryPoint === '') {
            $errors[] = 'game.entry_point is required';
        }

        return $errors;
    }

    public function getFilePickerProfiles(): array
    {
        return [
            'webEntryPoint' => [
                'label_key'          => 'ui.admin.door_manifest_editor.picker.web_entry_point',
                'allowed_extensions' => ['html', 'htm', 'php'],
            ],
            'imageAsset' => [
                'label_key'          => 'ui.admin.door_manifest_editor.picker.image_asset',
                'allowed_extensions' => ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'],
            ],
        ];
    }
}
