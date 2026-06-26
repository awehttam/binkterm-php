<?php

namespace BinktermPHP\Admin\DoorManifest;

/**
 * Contract for a door type adapter used by the manifest editor.
 *
 * Each adapter describes one door family (DOS, Native, JS-DOS, WebDoor):
 * its root directory, manifest filename, form field sections, default manifest
 * shape, validation rules, and file-picker profiles.
 */
interface DoorManifestTypeDefinition
{
    /** Short key: 'dos', 'native', 'jsdos', or 'web'. */
    public function getTypeKey(): string;

    /** Human-readable display name for the door type. */
    public function getDisplayName(): string;

    /**
     * Root directory for installations of this door type, relative to the
     * project root with no leading or trailing slash.
     */
    public function getRootDirectory(): string;

    /** Filename of the manifest file that lives inside each door directory. */
    public function getManifestFilename(): string;

    /**
     * Path to the runtime config file (e.g. 'config/dosdoors.json'), or null
     * if this door type has no separate runtime config.
     */
    public function getRuntimeConfigPath(): ?string;

    /** URL of the admin page that links to this editor (e.g. '/admin/dosdoors'). */
    public function getAdminPageUrl(): string;

    /** i18n key for the admin page title (used in breadcrumbs). */
    public function getAdminPageTitleKey(): string;

    /** Build a default manifest array for a new door directory. */
    public function getDefaultManifest(string $doorId): array;

    /**
     * Return field section definitions for the editor form.
     *
     * Each section is:
     * [
     *   'key'       => string,
     *   'label_key' => string,   // i18n key
     *   'fields'    => [         // array of field definitions
     *     [
     *       'path'          => string,   // dot-notation path in manifest
     *       'label_key'     => string,   // i18n key
     *       'type'          => string,   // text|textarea|number|checkbox|select|file-picker|tags|array-text|key-value|repeater|readonly
     *       'required'      => bool,
     *       'default'       => mixed,
     *       'help_key'      => ?string,  // i18n key for help text
     *       'options'       => ?array,   // for select: [['value'=>v,'label_key'=>k], ...]
     *       'picker_profile'=> ?string,  // for file-picker: profile name
     *       'sub_fields'    => ?array,   // for repeater: sub-field definitions
     *     ],
     *   ],
     * ]
     */
    public function getFieldSections(): array;

    /**
     * Validate the manifest payload before it is saved.
     *
     * @param string     $doorId           The door directory name.
     * @param array      $manifest         The proposed manifest to save.
     * @param array|null $existingManifest The on-disk manifest, or null if creating.
     * @return string[]  Empty array on success, or a list of human-readable error strings.
     */
    public function validateForSave(string $doorId, array $manifest, ?array $existingManifest = null): array;

    /**
     * Return file-picker profile definitions keyed by profile name.
     *
     * Each profile:
     * [
     *   'label_key'          => string,       // i18n key
     *   'allowed_extensions' => ?string[],     // null = all files allowed
     * ]
     */
    public function getFilePickerProfiles(): array;
}
