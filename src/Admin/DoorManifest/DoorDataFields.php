<?php

namespace BinktermPHP\Admin\DoorManifest;

use BinktermPHP\DoorData\DoorDataParserRegistry;

/**
 * Editor field definitions for a door's optional game data parser.
 *
 * Shared by the DOS and native door manifest definitions so both expose the
 * same top-level "data_parser" and "data_dir" manifest keys.
 *
 * @package BinktermPHP
 */
class DoorDataFields
{
    /**
     * Build the data parser select and data directory text fields.
     *
     * @return array[] Field definitions in the getFieldSections() shape
     */
    public static function getFields(): array
    {
        $options = [
            ['value' => '', 'label_key' => 'ui.admin.door_manifest_editor.data_parser.none'],
        ];
        foreach (DoorDataParserRegistry::ids() as $id) {
            $parser = DoorDataParserRegistry::get($id);
            $options[] = [
                'value' => $id,
                'label' => $parser !== null ? $parser->getGameName() . ' (' . $id . ')' : $id,
            ];
        }

        return [
            [
                'path'      => 'data_parser',
                'label_key' => 'ui.admin.door_manifest_editor.field.data_parser',
                'type'      => 'select',
                'required'  => false,
                'default'   => '',
                'options'   => $options,
                'help_key'  => 'ui.admin.door_manifest_editor.field.data_parser_help',
            ],
            [
                'path'      => 'data_dir',
                'label_key' => 'ui.admin.door_manifest_editor.field.data_dir',
                'type'      => 'text',
                'required'  => false,
                'default'   => '.',
                'help_key'  => 'ui.admin.door_manifest_editor.field.data_dir_help',
            ],
        ];
    }
}
