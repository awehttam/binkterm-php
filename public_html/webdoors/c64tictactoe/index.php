<?php
/**
 * C64 Example WebDoor
 *
 * To create a new C64 game door:
 *  1. Copy this folder, rename it to your game slug (e.g. "galaga")
 *  2. Drop your .prg (or .d64) file into the folder
 *  3. Update $c64Config below
 *  4. Update webdoor.json (id, name, description)
 *  5. Enable the door in the admin panel
 *
 * For a D64 disk image with multiple programs, use:
 *   'd64'      => 'game.d64',
 *   'prg_name' => 'GAMENAME',   // optional: auto-select a specific PRG from the D64
 */

$c64Config = [
    'door_id' => 'c64tictactoe',        // Must match webdoor.json "id"
    'title'   => 'Tic Tac Toe',  // Shown in loading spinner
    'prg'     => '3DTICTCT.P00',          // PRG file sitting next to this index.php
];

require __DIR__ . '/../_c64engine/player.php';
