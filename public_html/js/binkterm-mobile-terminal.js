/**
 * BinkTermPHP Mobile Terminal Addon
 *
 * Implements an xterm.js addon that enables responsive auto-scaling and game-specific
 * touch gamepads for DOS/BBS door sessions on mobile browsers (iPhone, iPad, Android).
 *
 * Features:
 * - Automatically scales fixed 80x25 terminal grid to fit mobile viewports, anchored to the top
 * - High Contrast Vivid mode by default for sharp readability on mobile screens (LORD, OOII, etc.)
 * - Game-Specific Gamepad Layouts:
 *   * Operation Overkill II (OOII): N/S/E/W movement on D-Pad, large action combat spacebar, * go back key
 *   * Legend of the Red Dragon (LORD): Standard D-Pad + instant Forest/Town hotkeys (F,S,K,A,H,R,B...)
 *   * Trade Wars 2002 (TW2002): Sector, Port, Move, Scan hotkeys
 *   * Standard Gamepad: Classic D-Pad, Actions, Numbers 0-9
 * - One-tap layout switcher allowing users or games to customize layouts on the fly
 * - Quick Zoom controls [−] 100% [+] to increase text size
 * - Dynamically adapts to software keyboard show/hide via visualViewport API
 * - Persistent configuration stored in localStorage
 */

(function () {
    'use strict';

    class BinktermMobileTerminalAddon {
        // Built-in Color Themes
        static THEMES = {
            standard: {
                background: '#000000',
                foreground: '#AAAAAA',
                cursor: '#00FF00',
                black: '#000000',
                red: '#AA0000',
                green: '#00AA00',
                yellow: '#AA5500',
                blue: '#0000AA',
                magenta: '#AA00AA',
                cyan: '#00AAAA',
                white: '#AAAAAA',
                brightBlack: '#555555',
                brightRed: '#FF5555',
                brightGreen: '#55FF55',
                brightYellow: '#FFFF55',
                brightBlue: '#5555FF',
                brightMagenta: '#FF55FF',
                brightCyan: '#55FFFF',
                brightWhite: '#FFFFFF'
            },
            vivid: {
                background: '#000000',
                foreground: '#FFFFFF',
                cursor: '#00FF66',
                black: '#000000',
                red: '#FF4444',       // Vivid High-Contrast Red
                green: '#00FF66',     // Bright Neon Lime Green (makes LORD / OOII pop!)
                yellow: '#FFEE00',    // Vivid Bright Gold
                blue: '#5599FF',      // Bright Azure Blue (easily readable against black)
                magenta: '#FF55FF',   // Bright Hot Pink/Magenta (makes menu hotkeys stand out!)
                cyan: '#00FFFF',      // Electric Cyan
                white: '#FFFFFF',     // Pure Bright White
                brightBlack: '#888888',
                brightRed: '#FF6666',
                brightGreen: '#77FF99',
                brightYellow: '#FFFF77',
                brightBlue: '#77BBFF',
                brightMagenta: '#FFAAFF',
                brightCyan: '#88FFFF',
                brightWhite: '#FFFFFF'
            },
            amber: {
                background: '#000000',
                foreground: '#FFB000',
                cursor: '#FFC800',
                black: '#000000',
                red: '#FF7700',
                green: '#FFB000',
                yellow: '#FFD700',
                blue: '#FFA000',
                magenta: '#FF8800',
                cyan: '#FFB800',
                white: '#FFE4A0',
                brightBlack: '#885500',
                brightRed: '#FFAA33',
                brightGreen: '#FFCC33',
                brightYellow: '#FFFF66',
                brightBlue: '#FFB833',
                brightMagenta: '#FF9933',
                brightCyan: '#FFDD44',
                brightWhite: '#FFFFFF'
            }
        };

        // Built-in Gamepad Profiles
        static LAYOUT_PROFILES = {
            ooii: {
                id: 'ooii',
                name: 'Operation Overkill II',
                shortName: 'OOII',
                desc: 'Dynamic: N/S/E/W D-Pad (Movement) / Giant Strike Spacebar (Combat)',
                matches: ['ooii', 'oo2', 'overkill', 'test-ooii', 'bbslink-ooii', 'operation overkill'],
                defaultSubMode: 'movement',
                detectSubMode: function (text) {
                    if (!text) return null;
                    // Detect Combat screens in OOII:
                    if (/hit\s+the\s+spacebar|hit\s+spacebar|long[-\s]?range.*hand[-\s]?to[-\s]?hand|to\s+the\s+death|run\s+from\s+combat|surrender!|exchange\s+weapons|view\s+opponent|combat\s+accuracy|combat\s+action|<combat>|combat\s+round|\[A\]\s*attack|-\[A\]|-\[T\]\s*to\s+the\s+death|to\s+the\s+death\s*\(y\/n\)/i.test(text)) {
                        return 'combat';
                    }
                    // Detect Movement / Wastelands / Base / Menus in OOII:
                    if (/infrared\s+scanner|wasteland|headquarters|barracks|comm\s+post|main\s+hall|c\)heck\s+status|d\)rop\s+item|p\)ick-up|u\)se\s+equip|\*\)\s*camp|camp\s*:\[|camp\s+command/i.test(text)) {
                        return 'movement';
                    }
                    return null;
                },
                getDynamicMenuSignature: function (text, subMode) {
                    if (!text) return 'movement';
                    const lines = text.split('\n').map(l => l.trim()).filter(Boolean);
                    const bottomText = lines.slice(-8).join('\n');

                    // 1. Prompts (Yes/No, More, Fight to Death confirmation)
                    if (/to\s+the\s+death\s*\(y\/n\)|\(y\/n\)|are you sure\?|more\?\s*\[/i.test(bottomText)) {
                        return 'prompt_yes_no';
                    }

                    // 2. Active Combat (highest priority combat detection)
                    if (subMode === 'combat' || /hit\s+the\s+spacebar|hit\s+spacebar|long[-\s]?range.*hand[-\s]?to[-\s]?hand|run\s+from\s+combat|surrender!|exchange\s+weapons|view\s+opponent|<combat>|combat\s+round|\[A\]\s*attack|-\[T\]\s*to\s+the\s+death/i.test(text)) {
                        return 'combat';
                    }

                    // 3. Inventory / Equipment / Use / Drop screens
                    if (/which item\?|use which|drop which|equipment carried|items carried|items on ground|select item/i.test(bottomText || text)) {
                        return 'inventory';
                    }

                    // 4. Base / Complex / Bunker / Barracks screens
                    if (/Base Command|Hydrite Prison|Complex Command|Headquarters|Barracks|Comm Post/i.test(text)) {
                        return 'base';
                    }

                    // 5. Movement / Wastelands map
                    if (/infrared\s+scanner|wasteland|c\)heck\s+status|d\)rop\s+item|p\)ick-up|u\)se\s+equip|\*\)\s*camp/i.test(text)) {
                        return 'movement';
                    }

                    return (subMode === 'combat') ? 'combat' : 'movement';
                },
                getDynamicRows: function (text, subMode, signature) {
                    const sysMove = [
                        { label: 'ESC', code: 'Escape', cls: 'btn-esc' },
                        { label: 'ENTER', code: 'Enter', cls: 'btn-enter' },
                        { label: '* Camp', send: '*', cls: 'btn-action btn-back-key', title: 'Camp / Logoff (*)' },
                        { label: '⌫', code: 'Backspace', cls: 'btn-action' },
                        { label: '⌨', cls: 'btn-utility btn-keyboard', allowFocus: true, title: 'Toggle Keyboard' },
                        { label: '◐', cls: 'btn-utility btn-contrast-key', action: 'contrast', title: 'Toggle High Contrast' },
                        { label: '⚔ Combat', action: 'submode:combat', cls: 'btn-submode-toggle', title: 'Switch to Combat Layout' },
                        { type: 'zoom' }
                    ];

                    const sysCombat = [
                        { label: 'ESC', code: 'Escape', cls: 'btn-esc' },
                        { label: 'ENTER', code: 'Enter', cls: 'btn-enter' },
                        { label: '👍 Y', send: 'Y', cls: 'btn-confirm-yes', title: 'Confirm Yes (Y)' },
                        { label: '👎 N', send: 'N', cls: 'btn-confirm-no', title: 'Confirm No (N)' },
                        { label: '⚔️ A (Atk)', send: 'A', cls: 'btn-action btn-combat-atk', title: 'Attack (A)' },
                        { label: '⚡ T (Death)', send: 'T', cls: 'btn-action btn-combat-death', title: 'Fight to the Death (T)' },
                        { label: '🏃 R (Run)', send: 'R', cls: 'btn-action btn-combat-flee', title: 'Run from Combat (R)' },
                        { label: '⌨', cls: 'btn-utility btn-keyboard', allowFocus: true, title: 'Toggle Keyboard' },
                        { label: '🧭 Move', action: 'submode:movement', cls: 'btn-submode-toggle', title: 'Switch to Movement Layout' }
                    ];

                    if (signature === 'prompt_yes_no') {
                        return [
                            sysMove,
                            [
                                { label: '👍 YES (Y)', send: 'Y', cls: 'btn-confirm-yes', title: 'Yes (Y)' },
                                { label: '👎 NO (N)', send: 'N', cls: 'btn-confirm-no', title: 'No (N)' },
                                { label: '⎵ SPACE', send: ' ', cls: 'btn-action', title: 'Spacebar' },
                                { label: '⏎ ENTER', code: 'Enter', cls: 'btn-enter', title: 'Enter' }
                            ],
                            [
                                { label: '1', send: '1' },
                                { label: '2', send: '2' },
                                { label: '3', send: '3' },
                                { label: '4', send: '4' },
                                { label: '5', send: '5' },
                                { label: '6', send: '6' },
                                { label: '7', send: '7' },
                                { label: '8', send: '8' },
                                { label: '9', send: '9' },
                                { label: '0', send: '0' },
                                { label: '*', send: '*', cls: 'btn-action', title: 'Camp / Cancel (*)' },
                                { label: '⌫', code: 'Backspace', cls: 'btn-action' }
                            ]
                        ];
                    }

                    if (signature === 'inventory') {
                        // Scan buffer for equipment lines like "1) Eat Rations", "6) Use MedPack", "99) Shift Pack Items"
                        const equipItems = [];
                        const equipRegex = /(\d+)\)\s+([A-Za-z0-9\s'\-]+)/g;
                        let em;
                        while ((em = equipRegex.exec(text)) !== null) {
                            const num = em[1];
                            const name = em[2].trim();
                            if (!equipItems.some(it => it.num === num)) {
                                equipItems.push({ num, name });
                            }
                        }

                        // Check if MedPack or Rations are in the list
                        const medItem = equipItems.find(it => /medpack|medkit|heal/i.test(it.name));
                        const rationItem = equipItems.find(it => /ration|eat/i.test(it.name));

                        const row2 = [];
                        if (medItem) {
                            row2.push({
                                label: `💊 Quick Heal (${medItem.num})`,
                                send: medItem.num,
                                cls: 'btn-lord-healer',
                                title: `Quick Heal - ${medItem.name} (${medItem.num})`
                            });
                        } else {
                            row2.push({
                                label: '💊 Quick Heal (6)',
                                send: '6',
                                cls: 'btn-lord-healer',
                                title: 'Quick Heal - Use MedPack (6)'
                            });
                        }

                        if (rationItem) {
                            row2.push({
                                label: `🍞 Rations (${rationItem.num})`,
                                send: rationItem.num,
                                cls: 'btn-lord-forest',
                                title: `Eat Rations (${rationItem.num})`
                            });
                        }

                        // Add remaining items to row 2
                        equipItems.forEach(it => {
                            if (it !== medItem && it !== rationItem && row2.length < 5) {
                                let icon = '📦';
                                if (/rope/i.test(it.name)) icon = '🪢';
                                if (/shift/i.test(it.name)) icon = '🔄';
                                row2.push({
                                    label: `${icon} ${it.num} (${it.name.split(' ')[0]})`,
                                    send: it.num,
                                    cls: 'btn-action',
                                    title: `${it.name} (${it.num})`
                                });
                            }
                        });

                        row2.push({
                            label: '0 (Cancel)',
                            send: '0',
                            cls: 'btn-confirm-no',
                            title: 'Cancel / Exit (0)'
                        });

                        return [
                            sysMove,
                            row2,
                            [
                                { label: '1', send: '1' },
                                { label: '2', send: '2' },
                                { label: '3', send: '3' },
                                { label: '4', send: '4' },
                                { label: '5', send: '5' },
                                { label: '6', send: '6' },
                                { label: '99 (Shift)', send: '99', cls: 'btn-hotkey', title: 'Shift Pack Items (99)' },
                                { label: 'ALL (A)', send: 'A', cls: 'btn-lord-weapons', title: 'All Items (A)' },
                                { label: '⎵ SPACE', send: ' ', cls: 'btn-action' },
                                { label: '⏎ ENTER', code: 'Enter', cls: 'btn-enter' }
                            ],
                            [
                                { label: '7', send: '7' },
                                { label: '8', send: '8' },
                                { label: '9', send: '9' },
                                { label: '0', send: '0' },
                                { label: '*', send: '*', cls: 'btn-confirm-no', title: 'Cancel (*)' },
                                { label: '👍 Y', send: 'Y', cls: 'btn-confirm-yes' },
                                { label: '👎 N', send: 'N', cls: 'btn-confirm-no' },
                                { label: '⌫', code: 'Backspace', cls: 'btn-action' }
                            ]
                        ];
                    }

                    if (signature === 'base') {
                        return [
                            sysMove,
                            [
                                { label: '🚪 E (Enter)', send: 'E', cls: 'btn-lord-forest', title: 'Enter Base (E)' },
                                { label: '🏗️ B (Build)', send: 'B', cls: 'btn-lord-weapons', title: 'Build Base (B)' },
                                { label: '🛡️ D (Defend)', send: 'D', cls: 'btn-lord-armour', title: 'Defenses (D)' },
                                { label: '📦 S (Store)', send: 'S', cls: 'btn-lord-bank', title: 'Store Items (S)' },
                                { label: '🔧 R (Repair)', send: 'R', cls: 'btn-action', title: 'Repair (R)' },
                                { label: '* (Exit)', send: '*', cls: 'btn-confirm-no', title: 'Exit (*)' }
                            ],
                            [
                                { label: '📜 C (Status)', send: 'C', cls: 'btn-lord-stats', title: 'Base Status (C)' },
                                { label: '👍 Y (Yes)', send: 'Y', cls: 'btn-confirm-yes', title: 'Yes (Y)' },
                                { label: '👎 N (No)', send: 'N', cls: 'btn-confirm-no', title: 'No (N)' },
                                { label: '⎵ SPACE', send: ' ', cls: 'btn-action', title: 'Spacebar' },
                                { label: '⏎ ENTER', code: 'Enter', cls: 'btn-enter', title: 'Enter' }
                            ],
                            [
                                { label: '1', send: '1' },
                                { label: '2', send: '2' },
                                { label: '3', send: '3' },
                                { label: '4', send: '4' },
                                { label: '5', send: '5' },
                                { label: '6', send: '6' },
                                { label: '7', send: '7' },
                                { label: '8', send: '8' },
                                { label: '9', send: '9' },
                                { label: '0', send: '0' },
                                { label: '⌫', code: 'Backspace', cls: 'btn-action' }
                            ]
                        ];
                    }

                    if (signature === 'combat' || subMode === 'combat') {
                        return [
                            sysCombat,
                            [
                                { label: '🏹 L (Range)', send: 'L', cls: 'btn-action btn-combat-range', title: 'Long-Range Combat (L)' },
                                { label: '⚔️ ACTION SPACEBAR ⚔️', send: ' ', cls: 'btn-space-combat-giant', title: 'Strike Opponent (Hit Spacebar)' },
                                { label: '🥊 H (Melee)', send: 'H', cls: 'btn-action btn-combat-melee', title: 'Hand-to-Hand Combat (H)' }
                            ],
                            [
                                { label: '🏳️ S (Surr)', send: 'S', cls: 'btn-hotkey', title: 'Surrender (S)' },
                                { label: '🔄 E (Exch)', send: 'E', cls: 'btn-hotkey', title: 'Exchange Weapons (E)' },
                                { label: '👁️ V (View)', send: 'V', cls: 'btn-hotkey', title: 'View Opponent (V)' },
                                { label: '📜 C (Stat)', send: 'C', cls: 'btn-lord-stats', title: 'Check Status / HP (C)' },
                                { label: '⚙️ M (Mode)', send: 'M', cls: 'btn-hotkey', title: 'Toggle Action/Random Mode (M)' },
                                { label: '⎵ SPACE', send: ' ', cls: 'btn-action', title: 'Spacebar' },
                                { label: '⏎ ENTER', code: 'Enter', cls: 'btn-enter', title: 'Enter' }
                            ],
                            [
                                { label: '1', send: '1' },
                                { label: '2', send: '2' },
                                { label: '3', send: '3' },
                                { label: '4', send: '4' },
                                { label: '5', send: '5' },
                                { label: '6', send: '6' },
                                { label: '7', send: '7' },
                                { label: '8', send: '8' },
                                { label: '9', send: '9' },
                                { label: '0', send: '0' },
                                { label: '⌫', code: 'Backspace', cls: 'btn-action' }
                            ]
                        ];
                    }

                    // Default Movement / Wastelands 4-row layout
                    return [
                        sysMove,
                        [
                            { label: '◄ W', send: 'W', cls: 'btn-nav', title: 'West (W)' },
                            { label: '▲ N', send: 'N', cls: 'btn-nav', title: 'North (N)' },
                            { label: '▼ S', send: 'S', cls: 'btn-nav', title: 'South (S)' },
                            { label: '► E', send: 'E', cls: 'btn-nav', title: 'East (E)' },
                            { label: '⎵ SPACE', send: ' ', cls: 'btn-action', title: 'Spacebar / Continue (<Cr>)' },
                            { label: '📦 P (Get)', send: 'P', cls: 'btn-lord-forest', title: 'Pick-up Items (P)' },
                            { label: '🎒 D (Drop)', send: 'D', cls: 'btn-action', title: 'Drop Items (D)' }
                        ],
                        [
                            { label: '💊 U (Heal/Use)', send: 'U', cls: 'btn-lord-healer', title: 'Quick Heal / Use Equipment (U)' },
                            { label: '📜 C (Status)', send: 'C', cls: 'btn-lord-stats', title: 'Check Status (C)' },
                            { label: '🏰 B (Bases)', send: 'B', cls: 'btn-lord-bank', title: 'Bases (build/enter) (B)' },
                            { label: '🔍 I (Camp)', send: 'I', cls: 'btn-lord-inn', title: 'Investigate Camp (I)' },
                            { label: '⚙️ A (Specs)', send: 'A', cls: 'btn-hotkey', title: 'Adjust User Specs (A)' },
                            { label: '🔄 R (Redraw)', send: 'R', cls: 'btn-action', title: 'Redraw Screen (R)' },
                            { label: '👍 Y (Yes)', send: 'Y', cls: 'btn-confirm-yes', title: 'Yes (Y)' },
                            { label: '👎 N (No)', send: 'N', cls: 'btn-confirm-no', title: 'No (N)' }
                        ],
                        [
                            { label: '1', send: '1', title: '1 / SW diagonal' },
                            { label: '2', send: '2', title: '2 / South' },
                            { label: '3', send: '3', title: '3 / SE diagonal' },
                            { label: '4', send: '4', title: '4 / West' },
                            { label: '5', send: '5', title: '5' },
                            { label: '6', send: '6', title: '6 / East' },
                            { label: '7', send: '7', title: '7 / NW diagonal' },
                            { label: '8', send: '8', title: '8 / North' },
                            { label: '9', send: '9', title: '9 / NE diagonal' },
                            { label: '0', send: '0', title: '0' },
                            { label: '*', send: '*', cls: 'btn-action', title: 'Camp (*)' },
                            { label: '⌫', code: 'Backspace', cls: 'btn-action' }
                        ]
                    ];
                },
                subModes: {
                    movement: {
                        id: 'movement',
                        name: 'Movement & Exploration',
                        shortName: 'Move',
                        icon: '🧭',
                        rows: [
                            [
                                { label: 'ESC', code: 'Escape', cls: 'btn-esc' },
                                { label: 'ENTER', code: 'Enter', cls: 'btn-enter' },
                                { label: '* Camp', send: '*', cls: 'btn-action btn-back-key', title: 'Camp / Logoff (*)' },
                                { label: '⌫', code: 'Backspace', cls: 'btn-action' },
                                { label: '⌨', cls: 'btn-utility btn-keyboard', allowFocus: true, title: 'Toggle Keyboard' },
                                { label: '◐', cls: 'btn-utility btn-contrast-key', action: 'contrast', title: 'Toggle High Contrast' },
                                { label: '⚔ Combat', action: 'submode:combat', cls: 'btn-submode-toggle', title: 'Switch to Combat Layout' },
                                { type: 'zoom' }
                            ],
                            [
                                { label: '◄ W', send: 'W', cls: 'btn-nav', title: 'West (W)' },
                                { label: '▲ N', send: 'N', cls: 'btn-nav', title: 'North (N)' },
                                { label: '▼ S', send: 'S', cls: 'btn-nav', title: 'South (S)' },
                                { label: '► E', send: 'E', cls: 'btn-nav', title: 'East (E)' },
                                { label: '⎵ SPACE', send: ' ', cls: 'btn-action', title: 'Spacebar / Continue (<Cr>)' },
                                { label: '📦 P (Get)', send: 'P', cls: 'btn-lord-forest', title: 'Pick-up Items (P)' },
                                { label: '🎒 D (Drop)', send: 'D', cls: 'btn-action', title: 'Drop Items (D)' }
                            ],
                            [
                                { label: '💊 U (Heal/Use)', send: 'U', cls: 'btn-lord-healer', title: 'Quick Heal / Use Equipment (U)' },
                                { label: '📜 C (Status)', send: 'C', cls: 'btn-lord-stats', title: 'Check Status (C)' },
                                { label: '🏰 B (Bases)', send: 'B', cls: 'btn-lord-bank', title: 'Bases (build/enter) (B)' },
                                { label: '🔍 I (Camp)', send: 'I', cls: 'btn-lord-inn', title: 'Investigate Camp (I)' },
                                { label: '⚙️ A (Specs)', send: 'A', cls: 'btn-hotkey', title: 'Adjust User Specs (A)' },
                                { label: '🔄 R (Redraw)', send: 'R', cls: 'btn-action', title: 'Redraw Screen (R)' },
                                { label: '👍 Y (Yes)', send: 'Y', cls: 'btn-confirm-yes', title: 'Yes (Y)' },
                                { label: '👎 N (No)', send: 'N', cls: 'btn-confirm-no', title: 'No (N)' }
                            ],
                            [
                                { label: '1', send: '1', title: '1 / SW diagonal' },
                                { label: '2', send: '2', title: '2 / South' },
                                { label: '3', send: '3', title: '3 / SE diagonal' },
                                { label: '4', send: '4', title: '4 / West' },
                                { label: '5', send: '5', title: '5' },
                                { label: '6', send: '6', title: '6 / East' },
                                { label: '7', send: '7', title: '7 / NW diagonal' },
                                { label: '8', send: '8', title: '8 / North' },
                                { label: '9', send: '9', title: '9 / NE diagonal' },
                                { label: '0', send: '0', title: '0' },
                                { label: '*', send: '*', cls: 'btn-action', title: 'Camp (*)' },
                                { label: '⌫', code: 'Backspace', cls: 'btn-action' }
                            ]
                        ]
                    },
                    combat: {
                        id: 'combat',
                        name: 'Action Combat',
                        shortName: 'Combat',
                        icon: '⚔️',
                        rows: [
                            [
                                { label: 'ESC', code: 'Escape', cls: 'btn-esc' },
                                { label: 'ENTER', code: 'Enter', cls: 'btn-enter' },
                                { label: '👍 Y', send: 'Y', cls: 'btn-confirm-yes', title: 'Confirm Yes (Y)' },
                                { label: '👎 N', send: 'N', cls: 'btn-confirm-no', title: 'Confirm No (N)' },
                                { label: '⚔️ A (Atk)', send: 'A', cls: 'btn-action btn-combat-atk', title: 'Attack (A)' },
                                { label: '⚡ T (Death)', send: 'T', cls: 'btn-action btn-combat-death', title: 'Fight to the Death (T)' },
                                { label: '🏃 R (Run)', send: 'R', cls: 'btn-action btn-combat-flee', title: 'Run from Combat (R)' },
                                { label: '⌨', cls: 'btn-utility btn-keyboard', allowFocus: true, title: 'Toggle Keyboard' },
                                { label: '🧭 Move', action: 'submode:movement', cls: 'btn-submode-toggle', title: 'Switch to Movement Layout' }
                            ],
                            [
                                { label: '🏹 L (Range)', send: 'L', cls: 'btn-action btn-combat-range', title: 'Long-Range Combat (L)' },
                                { label: '⚔️ ACTION SPACEBAR ⚔️', send: ' ', cls: 'btn-space-combat-giant', title: 'Strike Opponent (Hit Spacebar)' },
                                { label: '🥊 H (Melee)', send: 'H', cls: 'btn-action btn-combat-melee', title: 'Hand-to-Hand Combat (H)' }
                            ],
                            [
                                { label: '🏳️ S (Surr)', send: 'S', cls: 'btn-hotkey', title: 'Surrender (S)' },
                                { label: '🔄 E (Exch)', send: 'E', cls: 'btn-hotkey', title: 'Exchange Weapons (E)' },
                                { label: '👁️ V (View)', send: 'V', cls: 'btn-hotkey', title: 'View Opponent (V)' },
                                { label: '📜 C (Stat)', send: 'C', cls: 'btn-lord-stats', title: 'Check Status / HP (C)' },
                                { label: '⚙️ M (Mode)', send: 'M', cls: 'btn-hotkey', title: 'Toggle Action/Random Mode (M)' },
                                { label: '⎵ SPACE', send: ' ', cls: 'btn-action', title: 'Spacebar' },
                                { label: '⏎ ENTER', code: 'Enter', cls: 'btn-enter', title: 'Enter' }
                            ],
                            [
                                { label: '1', send: '1' },
                                { label: '2', send: '2' },
                                { label: '3', send: '3' },
                                { label: '4', send: '4' },
                                { label: '5', send: '5' },
                                { label: '6', send: '6' },
                                { label: '7', send: '7' },
                                { label: '8', send: '8' },
                                { label: '9', send: '9' },
                                { label: '0', send: '0' },
                                { label: '⌫', code: 'Backspace', cls: 'btn-action' }
                            ]
                        ]
                    }
                },
                rows: [
                    [
                        { label: 'ESC', code: 'Escape', cls: 'btn-esc' },
                        { label: 'ENTER', code: 'Enter', cls: 'btn-enter' },
                        { label: '* Camp', send: '*', cls: 'btn-action btn-back-key' },
                        { label: '⌫', code: 'Backspace', cls: 'btn-action' },
                        { label: '⌨', cls: 'btn-utility btn-keyboard', allowFocus: true },
                        { label: '◐', cls: 'btn-utility btn-contrast-key', action: 'contrast' },
                        { type: 'zoom' }
                    ],
                    [
                        { label: '◄ W', send: 'W', cls: 'btn-nav' },
                        { label: '▲ N', send: 'N', cls: 'btn-nav' },
                        { label: '▼ S', send: 'S', cls: 'btn-nav' },
                        { label: '► E', send: 'E', cls: 'btn-nav' },
                        { label: '⎵ SPACE', send: ' ', cls: 'btn-action' },
                        { label: '📦 P (Get)', send: 'P', cls: 'btn-lord-forest' },
                        { label: '🎒 D (Drop)', send: 'D', cls: 'btn-action' }
                    ],
                    [
                        { label: '⚡ U (Use)', send: 'U', cls: 'btn-lord-weapons' },
                        { label: '📜 C (Status)', send: 'C', cls: 'btn-lord-stats' },
                        { label: '🏰 B (Bases)', send: 'B', cls: 'btn-lord-bank' },
                        { label: '🔍 I (Camp)', send: 'I', cls: 'btn-lord-inn' },
                        { label: '⚙️ A (Specs)', send: 'A', cls: 'btn-hotkey' },
                        { label: '🔄 R (Redraw)', send: 'R', cls: 'btn-action' },
                        { label: '👍 Y', send: 'Y', cls: 'btn-confirm-yes' },
                        { label: '👎 N', send: 'N', cls: 'btn-confirm-no' }
                    ],
                    [
                        { label: '1', send: '1' },
                        { label: '2', send: '2' },
                        { label: '3', send: '3' },
                        { label: '4', send: '4' },
                        { label: '5', send: '5' },
                        { label: '6', send: '6' },
                        { label: '7', send: '7' },
                        { label: '8', send: '8' },
                        { label: '9', send: '9' },
                        { label: '0', send: '0' }
                    ]
                ]
            },
            lord: {
                id: 'lord',
                name: 'Legend of the Red Dragon',
                shortName: 'LORD',
                desc: 'Dynamic: Auto-detects Town Square, Combat, Bank, Shops, Healer, Inn & Prompts',
                matches: ['lord', 'lord2', 'bbslink-lord', 'bbslink-lord2', 'red dragon'],
                defaultSubMode: 'town',
                detectSubMode: function (text) {
                    if (!text) return null;
                    // Combat check
                    if (/what will you do\?|\(A,R,C,H|\(A\)ttack.*\(R\)un|battle with.*hit points|you have encountered/i.test(text)) {
                        return 'combat';
                    }
                    // Town & Exploration (Town Square, Forest, Shops, Inn, Healer, Bank, Training, etc.)
                    if (/Town Square|The Forest|\(L,H,R,Q\)|Dragon.*-\s*Forest|\(L\)ook for something|Ye Old Bank|Abduls Armour|King Arthur|Healers Hut|Warrior Training|The Dragon's Inn|Other Places|\(F,S,K,A,H,V,I,T,Y,L,W,D,C,O,X,M,P,Q\)|\(F\)orest.*\(S\)laughter/i.test(text)) {
                        return 'town';
                    }
                    return null;
                },
                getDynamicMenuSignature: function (text, subMode) {
                    if (!text) return 'menu_town';
                    
                    const lines = text.split('\n').map(l => l.trim()).filter(Boolean);
                    const bottomText = lines.slice(-8).join('\n');

                    // 1. Prompts (Yes/No, More, News) - check active prompt area first
                    if (/More\?\s*\[[YyNnQq]?\]|\(Y\/N\)|\(Yes\/No\)|\(Y,N\)/i.test(bottomText)) {
                        return 'prompt_yes_no';
                    }
                    if (/\(C,T,Y\)|\(T,Y\)|\(C\)ontinue,.*\(T\)oday.*\(Y\)esterday/i.test(bottomText)) {
                        return 'prompt_news';
                    }

                    // 2. Active Combat (highest priority combat detection)
                    if (subMode === 'combat' || /what will you do\?|\(A,R,C,H|\(A\)ttack.*\(R\)un|battle with.*hit points|you have encountered.*what will/i.test(text)) {
                        return 'combat';
                    }

                    // 3. Town Square (MUST be checked before shops because Town Square lists all shops like Turgons Training, Healers, etc.)
                    if (/The Town Square|Town Square|\(F,S,K,A,H,V,I,T,Y,L,W,D,C,O,X,M,P,Q\)|\(F\)orest.*\(S\)laughter/i.test(text)) {
                        return 'menu_town';
                    }

                    // 4. The Forest
                    if (/The Forest|\(L,H,R,Q\)|Dragon.*-\s*Forest|\(L\)ook for something/i.test(text)) {
                        return 'menu_forest';
                    }

                    // 5. Ye Old Bank (inside the bank)
                    if (/Ye Old Bank\s*\(D|\(D,W,V,R,Q\)|\(D\)eposit.*\(W\)ithdraw/i.test(text)) {
                        return 'menu_bank';
                    }

                    // 6. Abduls Armour (inside the armour shop)
                    if (/Abduls Armour\s*\(B|\(B\)uy armour|\(S\)ell armour/i.test(text)) {
                        return 'menu_armour';
                    }

                    // 7. King Arthurs Weapons (inside the weapons shop)
                    if (/King Arthurs Weapons\s*\(B|\(B\)uy weapons|\(S\)ell weapon/i.test(text)) {
                        return 'menu_weapons';
                    }

                    // 8. Healers Hut (inside the healer shop)
                    if (/\(H\)eal all.*hit points|\(H\)eal all|Welcome to.*Healers Hut|Healers Hut\s*\(H,1/i.test(text)) {
                        return 'menu_healer';
                    }

                    // 9. Turgons Warrior Training (inside training - specific to Question/Fight Master)
                    if (/\(Q\)uestion Master|\(F\)ight Master|Turgons Training\s*\(Q,F/i.test(text)) {
                        return 'menu_training';
                    }

                    // 10. The Dragon's Inn (inside the inn)
                    if (/Dragons Inn\s*\(B|\(B\)artender|\(S\)peak with patrons/i.test(text)) {
                        return 'menu_inn';
                    }

                    return (subMode === 'combat') ? 'combat' : 'menu_town';
                },
                getDynamicRows: function (text, subMode, signature) {
                    const sysTown = [
                        { label: 'ESC', code: 'Escape', cls: 'btn-esc' },
                        { label: 'ENTER', code: 'Enter', cls: 'btn-enter' },
                        { label: 'SPACE', send: ' ', cls: 'btn-action' },
                        { label: '⌫', code: 'Backspace', cls: 'btn-action' },
                        { label: '⌨', cls: 'btn-utility btn-keyboard', allowFocus: true, title: 'Toggle Keyboard' },
                        { label: '◐', cls: 'btn-utility btn-contrast-key', action: 'contrast', title: 'Toggle High Contrast' },
                        { label: '⚔ Battle', action: 'submode:combat', cls: 'btn-submode-toggle', title: 'Switch to Combat Layout' },
                        { type: 'zoom' }
                    ];
                    const sysCombat = [
                        { label: 'ESC', code: 'Escape', cls: 'btn-esc' },
                        { label: 'ENTER', code: 'Enter', cls: 'btn-enter' },
                        { label: 'SPACE', send: ' ', cls: 'btn-action' },
                        { label: '⌫', code: 'Backspace', cls: 'btn-action' },
                        { label: '⌨', cls: 'btn-utility btn-keyboard', allowFocus: true, title: 'Toggle Keyboard' },
                        { label: '◐', cls: 'btn-utility btn-contrast-key', action: 'contrast', title: 'Toggle High Contrast' },
                        { label: '🏰 Town', action: 'submode:town', cls: 'btn-submode-toggle', title: 'Switch to Town Layout' },
                        { type: 'zoom' }
                    ];

                    if (signature === 'prompt_yes_no') {
                        return [
                            sysTown,
                            [
                                { label: '👍 YES (Y)', send: 'Y', cls: 'btn-confirm-yes', title: 'Yes (Y)' },
                                { label: '👎 NO (N)', send: 'N', cls: 'btn-confirm-no', title: 'No (N)' },
                                { label: '⎵ SPACE', send: ' ', cls: 'btn-action', title: 'Spacebar' },
                                { label: '⏎ ENTER', code: 'Enter', cls: 'btn-enter', title: 'Enter' }
                            ],
                            [
                                { label: '1', send: '1' },
                                { label: '2', send: '2' },
                                { label: '3', send: '3' },
                                { label: '4', send: '4' },
                                { label: '5', send: '5' },
                                { label: '6', send: '6' },
                                { label: '7', send: '7' },
                                { label: '8', send: '8' },
                                { label: '9', send: '9' },
                                { label: '0', send: '0' },
                                { label: 'Q', send: 'Q', cls: 'btn-action', title: 'Quit (Q)' }
                            ]
                        ];
                    }

                    if (signature === 'prompt_news') {
                        return [
                            sysTown,
                            [
                                { label: '▶️ C (Continue)', send: 'C', cls: 'btn-action', title: 'Continue (C)' },
                                { label: '📅 T (Today)', send: 'T', cls: 'btn-lord-news', title: "Today's News (T)" },
                                { label: '⏪ Y (Yesterday)', send: 'Y', cls: 'btn-lord-news', title: "Yesterday's News (Y)" },
                                { label: '⏎ ENTER', code: 'Enter', cls: 'btn-enter', title: 'Enter' }
                            ],
                            [
                                { label: '1', send: '1' },
                                { label: '2', send: '2' },
                                { label: '3', send: '3' },
                                { label: '4', send: '4' },
                                { label: '5', send: '5' },
                                { label: '6', send: '6' },
                                { label: '7', send: '7' },
                                { label: '8', send: '8' },
                                { label: '9', send: '9' },
                                { label: '0', send: '0' },
                                { label: 'SPACE', send: ' ', cls: 'btn-action' }
                            ]
                        ];
                    }

                    if (signature === 'menu_bank') {
                        return [
                            sysTown,
                            [
                                { label: '💰 D (Deposit)', send: 'D', cls: 'btn-lord-bank', title: 'Deposit Gold (D)' },
                                { label: '💸 W (Withdraw)', send: 'W', cls: 'btn-lord-bank', title: 'Withdraw Gold (W)' },
                                { label: '📜 V (Stats/Bal)', send: 'V', cls: 'btn-lord-stats', title: 'View Balance & Stats (V)' },
                                { label: '🏰 R (Return)', send: 'R', cls: 'btn-action', title: 'Return to Town (R)' },
                                { label: '👋 Q (Quit)', send: 'Q', cls: 'btn-confirm-no', title: 'Quit (Q)' }
                            ],
                            [
                                { label: 'ALL (A)', send: 'A', cls: 'btn-action', title: 'All / Max (A)' },
                                { label: 'Y (Yes)', send: 'Y', cls: 'btn-confirm-yes', title: 'Yes (Y)' },
                                { label: 'N (No)', send: 'N', cls: 'btn-confirm-no', title: 'No (N)' },
                                { label: 'SPACE', send: ' ', cls: 'btn-action' },
                                { label: 'ENTER', code: 'Enter', cls: 'btn-enter' }
                            ],
                            [
                                { label: '1', send: '1' },
                                { label: '2', send: '2' },
                                { label: '3', send: '3' },
                                { label: '4', send: '4' },
                                { label: '5', send: '5' },
                                { label: '6', send: '6' },
                                { label: '7', send: '7' },
                                { label: '8', send: '8' },
                                { label: '9', send: '9' },
                                { label: '0', send: '0' },
                                { label: '⌫', code: 'Backspace', cls: 'btn-action' }
                            ]
                        ];
                    }

                    if (signature === 'menu_armour' || signature === 'menu_weapons') {
                        const isWeapons = signature === 'menu_weapons';
                        return [
                            sysTown,
                            [
                                { label: isWeapons ? '🗡️ B (Buy Weapons)' : '🛡️ B (Buy Armour)', send: 'B', cls: isWeapons ? 'btn-lord-weapons' : 'btn-lord-armour', title: 'Buy (B)' },
                                { label: '💰 S (Sell Old)', send: 'S', cls: 'btn-action', title: 'Sell Old (S)' },
                                { label: '📜 V (Stats)', send: 'V', cls: 'btn-lord-stats', title: 'View Stats (V)' },
                                { label: '🏰 R (Return)', send: 'R', cls: 'btn-action', title: 'Return to Town (R)' },
                                { label: '👋 Q (Quit)', send: 'Q', cls: 'btn-confirm-no', title: 'Quit (Q)' }
                            ],
                            [
                                { label: 'Y (Yes)', send: 'Y', cls: 'btn-confirm-yes', title: 'Yes (Y)' },
                                { label: 'N (No)', send: 'N', cls: 'btn-confirm-no', title: 'No (N)' },
                                { label: 'SPACE', send: ' ', cls: 'btn-action' },
                                { label: 'ENTER', code: 'Enter', cls: 'btn-enter' }
                            ],
                            [
                                { label: '1', send: '1' },
                                { label: '2', send: '2' },
                                { label: '3', send: '3' },
                                { label: '4', send: '4' },
                                { label: '5', send: '5' },
                                { label: '6', send: '6' },
                                { label: '7', send: '7' },
                                { label: '8', send: '8' },
                                { label: '9', send: '9' },
                                { label: '0', send: '0' }
                            ]
                        ];
                    }

                    if (signature === 'menu_healer') {
                        return [
                            sysTown,
                            [
                                { label: '💚 H (Heal All)', send: 'H', cls: 'btn-lord-healer', title: 'Heal All HP (H)' },
                                { label: '🌿 1 (Heal 1 HP)', send: '1', cls: 'btn-action', title: 'Heal 1 HP (1)' },
                                { label: '📜 V (Stats)', send: 'V', cls: 'btn-lord-stats', title: 'View Stats (V)' },
                                { label: '🏰 R (Return)', send: 'R', cls: 'btn-action', title: 'Return to Town (R)' },
                                { label: '👋 Q (Quit)', send: 'Q', cls: 'btn-confirm-no', title: 'Quit (Q)' }
                            ],
                            [
                                { label: 'Y (Yes)', send: 'Y', cls: 'btn-confirm-yes', title: 'Yes (Y)' },
                                { label: 'N (No)', send: 'N', cls: 'btn-confirm-no', title: 'No (N)' },
                                { label: 'SPACE', send: ' ', cls: 'btn-action' },
                                { label: 'ENTER', code: 'Enter', cls: 'btn-enter' }
                            ],
                            [
                                { label: '1', send: '1' },
                                { label: '2', send: '2' },
                                { label: '3', send: '3' },
                                { label: '4', send: '4' },
                                { label: '5', send: '5' },
                                { label: '6', send: '6' },
                                { label: '7', send: '7' },
                                { label: '8', send: '8' },
                                { label: '9', send: '9' },
                                { label: '0', send: '0' }
                            ]
                        ];
                    }

                    if (signature === 'menu_training') {
                        return [
                            sysTown,
                            [
                                { label: '⚔️ F (Fight Master)', send: 'F', cls: 'btn-lord-train', title: 'Fight Master (F)' },
                                { label: '❓ Q (Question)', send: 'Q', cls: 'btn-action', title: 'Question Master (Q)' },
                                { label: '📜 V (Stats)', send: 'V', cls: 'btn-lord-stats', title: 'View Stats (V)' },
                                { label: '🏰 R (Return)', send: 'R', cls: 'btn-action', title: 'Return to Town (R)' }
                            ],
                            [
                                { label: 'Y (Yes)', send: 'Y', cls: 'btn-confirm-yes', title: 'Yes (Y)' },
                                { label: 'N (No)', send: 'N', cls: 'btn-confirm-no', title: 'No (N)' },
                                { label: 'SPACE', send: ' ', cls: 'btn-action' },
                                { label: 'ENTER', code: 'Enter', cls: 'btn-enter' }
                            ],
                            [
                                { label: '1', send: '1' },
                                { label: '2', send: '2' },
                                { label: '3', send: '3' },
                                { label: '4', send: '4' },
                                { label: '5', send: '5' },
                                { label: '6', send: '6' },
                                { label: '7', send: '7' },
                                { label: '8', send: '8' },
                                { label: '9', send: '9' },
                                { label: '0', send: '0' }
                            ]
                        ];
                    }

                    if (signature === 'menu_inn') {
                        return [
                            sysTown,
                            [
                                { label: '🍺 B (Bartender)', send: 'B', cls: 'btn-lord-inn', title: 'Bartender (B)' },
                                { label: '🗣️ C (Patrons)', send: 'C', cls: 'btn-action', title: 'Converse (C)' },
                                { label: '🎲 G (Gamble)', send: 'G', cls: 'btn-action', title: 'Gamble (G)' },
                                { label: '🏰 R (Return)', send: 'R', cls: 'btn-action', title: 'Return to Town (R)' },
                                { label: '👋 Q (Quit)', send: 'Q', cls: 'btn-confirm-no', title: 'Quit (Q)' }
                            ],
                            [
                                { label: 'Y (Yes)', send: 'Y', cls: 'btn-confirm-yes', title: 'Yes (Y)' },
                                { label: 'N (No)', send: 'N', cls: 'btn-confirm-no', title: 'No (N)' },
                                { label: 'SPACE', send: ' ', cls: 'btn-action' },
                                { label: 'ENTER', code: 'Enter', cls: 'btn-enter' }
                            ],
                            [
                                { label: '1', send: '1' },
                                { label: '2', send: '2' },
                                { label: '3', send: '3' },
                                { label: '4', send: '4' },
                                { label: '5', send: '5' },
                                { label: '6', send: '6' },
                                { label: '7', send: '7' },
                                { label: '8', send: '8' },
                                { label: '9', send: '9' },
                                { label: '0', send: '0' }
                            ]
                        ];
                    }

                    if (signature === 'menu_forest') {
                        return [
                            sysTown,
                            [
                                { label: '⚔️ L (Look / Kill)', send: 'L', cls: 'btn-lord-slaught', title: 'Look for something to kill (L)' },
                                { label: '🌿 H (Healers Hut)', send: 'H', cls: 'btn-lord-healer', title: 'Healers Hut (H)' },
                                { label: '🏰 R (Return)', send: 'R', cls: 'btn-lord-forest', title: 'Return to Town (R)' },
                                { label: '👋 Q (Quit)', send: 'Q', cls: 'btn-confirm-no', title: 'Quit to Town (Q)' }
                            ],
                            [
                                { label: '📜 V (Stats)', send: 'V', cls: 'btn-lord-stats', title: 'View Stats (V)' },
                                { label: '❓ ? (Menu)', send: '?', cls: 'btn-hotkey', title: 'Show Forest Menu (?)' },
                                { label: '👍 Y (Yes)', send: 'Y', cls: 'btn-confirm-yes', title: 'Confirm Yes (Y)' },
                                { label: '👎 N (No)', send: 'N', cls: 'btn-confirm-no', title: 'Confirm No (N)' },
                                { label: '⎵ SPACE', send: ' ', cls: 'btn-action', title: 'Spacebar' },
                                { label: '⏎ ENTER', code: 'Enter', cls: 'btn-enter', title: 'Enter' }
                            ],
                            [
                                { label: '1', send: '1' },
                                { label: '2', send: '2' },
                                { label: '3', send: '3' },
                                { label: '4', send: '4' },
                                { label: '5', send: '5' },
                                { label: '6', send: '6' },
                                { label: '7', send: '7' },
                                { label: '8', send: '8' },
                                { label: '9', send: '9' },
                                { label: '0', send: '0' }
                            ]
                        ];
                    }

                    if (signature === 'combat' || subMode === 'combat') {
                        return [
                            sysCombat,
                            [
                                { label: '⚔️ ATTACK (A)', send: 'A', cls: 'btn-space-combat btn-combat-atk', title: 'Attack Creature (A)' },
                                { label: '🏃 RUN (R)', send: 'R', cls: 'btn-action btn-combat-flee', title: 'Run Away (R)' },
                                { label: '✨ SPELL (C)', send: 'C', cls: 'btn-action', title: 'Cast Spell (C)' },
                                { label: '💚 HEAL (H)', send: 'H', cls: 'btn-action btn-lord-healer', title: 'Heal (H)' }
                            ],
                            [
                                { label: '📜 V (Stats)', send: 'V', cls: 'btn-lord-stats', title: 'View Stats (V)' },
                                { label: '🗡️ S (Stance)', send: 'S', cls: 'btn-hotkey', title: 'Special / Stance (S)' },
                                { label: '⚔️ D (Death)', send: 'D', cls: 'btn-combat-death', title: 'Fight to Death (D)' },
                                { label: '👍 Y (Yes)', send: 'Y', cls: 'btn-confirm-yes', title: 'Confirm Yes (Y)' },
                                { label: '👎 N (No)', send: 'N', cls: 'btn-confirm-no', title: 'Confirm No (N)' },
                                { label: '⎵ SPACE', send: ' ', cls: 'btn-action', title: 'Spacebar' },
                                { label: '⏎ ENTER', code: 'Enter', cls: 'btn-enter', title: 'Enter' }
                            ],
                            [
                                { label: '1', send: '1' },
                                { label: '2', send: '2' },
                                { label: '3', send: '3' },
                                { label: '4', send: '4' },
                                { label: '5', send: '5' },
                                { label: '6', send: '6' },
                                { label: '7', send: '7' },
                                { label: '8', send: '8' },
                                { label: '9', send: '9' },
                                { label: '0', send: '0' },
                                { label: '⌫', code: 'Backspace', cls: 'btn-action' }
                            ]
                        ];
                    }

                    // Default Town Square 4-row layout
                    return [
                        sysTown,
                        [
                            { label: '🌲 F (Forest)', send: 'F', cls: 'btn-lord-forest', title: 'Forest (F)' },
                            { label: '⚔️ S (Slaught)', send: 'S', cls: 'btn-lord-slaught', title: 'Slaughter (S)' },
                            { label: '🗡️ K (Weapons)', send: 'K', cls: 'btn-lord-weapons', title: 'King Arthurs Weapons (K)' },
                            { label: '🛡️ A (Armour)', send: 'A', cls: 'btn-lord-armour', title: 'Abduls Armour (A)' },
                            { label: '🌿 H (Healer)', send: 'H', cls: 'btn-lord-healer', title: 'Healers Hut (H)' },
                            { label: '🍺 I (Inn)', send: 'I', cls: 'btn-lord-inn', title: 'The Dragons Inn (I)' }
                        ],
                        [
                            { label: '🏦 Y (Bank)', send: 'Y', cls: 'btn-lord-bank', title: 'Ye Old Bank (Y)' },
                            { label: '🏋️ T (Train)', send: 'T', cls: 'btn-lord-train', title: 'Turgons Warrior Training (T)' },
                            { label: '📜 V (Stats)', send: 'V', cls: 'btn-lord-stats', title: 'View Stats (V)' },
                            { label: '📰 D (News)', send: 'D', cls: 'btn-lord-news', title: 'Daily News (D)' },
                            { label: '👥 L (List)', send: 'L', cls: 'btn-hotkey', title: 'List Warriors (L)' },
                            { label: '✉️ W (Mail)', send: 'W', cls: 'btn-hotkey', title: 'Write Mail (W)' },
                            { label: '🗺️ O (Other)', send: 'O', cls: 'btn-hotkey', title: 'Other Places (O)' },
                            { label: '👋 Q (Quit)', send: 'Q', cls: 'btn-confirm-no', title: 'Quit to Fields (Q)' }
                        ],
                        [
                            { label: '1', send: '1' },
                            { label: '2', send: '2' },
                            { label: '3', send: '3' },
                            { label: '4', send: '4' },
                            { label: '5', send: '5' },
                            { label: '6', send: '6' },
                            { label: '7', send: '7' },
                            { label: '8', send: '8' },
                            { label: '9', send: '9' },
                            { label: '0', send: '0' },
                            { label: 'Y', send: 'Y', cls: 'btn-confirm-yes', title: 'Yes (Y)' },
                            { label: 'N', send: 'N', cls: 'btn-confirm-no', title: 'No (N)' },
                            { label: 'C', send: 'C', cls: 'btn-hotkey', title: 'Check Mail (C)' },
                            { label: 'M', send: 'M', cls: 'btn-hotkey', title: 'Make Announcement (M)' }
                        ]
                    ];
                },
                subModes: {
                    town: {
                        id: 'town',
                        name: 'Town & Exploration',
                        shortName: 'Town',
                        icon: '🏰',
                        rows: [
                            [
                                { label: 'ESC', code: 'Escape', cls: 'btn-esc' },
                                { label: 'ENTER', code: 'Enter', cls: 'btn-enter' },
                                { label: 'SPACE', send: ' ', cls: 'btn-action' },
                                { label: '⌫', code: 'Backspace', cls: 'btn-action' },
                                { label: '⌨', cls: 'btn-utility btn-keyboard', allowFocus: true, title: 'Toggle Keyboard' },
                                { label: '◐', cls: 'btn-utility btn-contrast-key', action: 'contrast', title: 'Toggle High Contrast' },
                                { label: '⚔ Battle', action: 'submode:combat', cls: 'btn-submode-toggle', title: 'Switch to Combat Layout' },
                                { type: 'zoom' }
                            ],
                            [
                                { label: '🌲 F (Forest)', send: 'F', cls: 'btn-lord-forest', title: 'Forest (F)' },
                                { label: '⚔️ S (Slaught)', send: 'S', cls: 'btn-lord-slaught', title: 'Slaughter (S)' },
                                { label: '🗡️ K (Weapons)', send: 'K', cls: 'btn-lord-weapons', title: 'King Arthurs Weapons (K)' },
                                { label: '🛡️ A (Armour)', send: 'A', cls: 'btn-lord-armour', title: 'Abduls Armour (A)' },
                                { label: '🌿 H (Healer)', send: 'H', cls: 'btn-lord-healer', title: 'Healers Hut (H)' },
                                { label: '🍺 I (Inn)', send: 'I', cls: 'btn-lord-inn', title: 'The Dragons Inn (I)' }
                            ],
                            [
                                { label: '🏦 Y (Bank)', send: 'Y', cls: 'btn-lord-bank', title: 'Ye Old Bank (Y)' },
                                { label: '🏋️ T (Train)', send: 'T', cls: 'btn-lord-train', title: 'Turgons Warrior Training (T)' },
                                { label: '📜 V (Stats)', send: 'V', cls: 'btn-lord-stats', title: 'View Stats (V)' },
                                { label: '📰 D (News)', send: 'D', cls: 'btn-lord-news', title: 'Daily News (D)' },
                                { label: '👥 L (List)', send: 'L', cls: 'btn-hotkey', title: 'List Warriors (L)' },
                                { label: '✉️ W (Mail)', send: 'W', cls: 'btn-hotkey', title: 'Write Mail (W)' },
                                { label: '🗺️ O (Other)', send: 'O', cls: 'btn-hotkey', title: 'Other Places (O)' },
                                { label: '👋 Q (Quit)', send: 'Q', cls: 'btn-confirm-no', title: 'Quit to Fields (Q)' }
                            ],
                            [
                                { label: '1', send: '1' },
                                { label: '2', send: '2' },
                                { label: '3', send: '3' },
                                { label: '4', send: '4' },
                                { label: '5', send: '5' },
                                { label: '6', send: '6' },
                                { label: '7', send: '7' },
                                { label: '8', send: '8' },
                                { label: '9', send: '9' },
                                { label: '0', send: '0' },
                                { label: 'Y', send: 'Y', cls: 'btn-confirm-yes', title: 'Yes (Y)' },
                                { label: 'N', send: 'N', cls: 'btn-confirm-no', title: 'No (N)' },
                                { label: 'C', send: 'C', cls: 'btn-hotkey', title: 'Check Mail (C)' },
                                { label: 'M', send: 'M', cls: 'btn-hotkey', title: 'Make Announcement (M)' }
                            ]
                        ]
                    },
                    combat: {
                        id: 'combat',
                        name: 'Battle & Combat',
                        shortName: 'Combat',
                        icon: '⚔️',
                        rows: [
                            [
                                { label: 'ESC', code: 'Escape', cls: 'btn-esc' },
                                { label: 'ENTER', code: 'Enter', cls: 'btn-enter' },
                                { label: 'SPACE', send: ' ', cls: 'btn-action' },
                                { label: '⌫', code: 'Backspace', cls: 'btn-action' },
                                { label: '⌨', cls: 'btn-utility btn-keyboard', allowFocus: true, title: 'Toggle Keyboard' },
                                { label: '◐', cls: 'btn-utility btn-contrast-key', action: 'contrast', title: 'Toggle High Contrast' },
                                { label: '🏰 Town', action: 'submode:town', cls: 'btn-submode-toggle', title: 'Switch to Town Layout' },
                                { type: 'zoom' }
                            ],
                            [
                                { label: '⚔️ ATTACK (A)', send: 'A', cls: 'btn-space-combat btn-combat-atk', title: 'Attack Creature (A)' },
                                { label: '🏃 RUN (R)', send: 'R', cls: 'btn-action btn-combat-flee', title: 'Run Away (R)' },
                                { label: '✨ SPELL (C)', send: 'C', cls: 'btn-action', title: 'Cast Spell (C)' },
                                { label: '💚 HEAL (H)', send: 'H', cls: 'btn-action btn-lord-healer', title: 'Heal (H)' }
                            ],
                            [
                                { label: '📜 V (Stats)', send: 'V', cls: 'btn-lord-stats', title: 'View Stats (V)' },
                                { label: '🗡️ S (Stance)', send: 'S', cls: 'btn-hotkey', title: 'Special / Stance (S)' },
                                { label: '⚔️ D (Death)', send: 'D', cls: 'btn-combat-death', title: 'Fight to Death (D)' },
                                { label: '👍 Y (Yes)', send: 'Y', cls: 'btn-confirm-yes', title: 'Confirm Yes (Y)' },
                                { label: '👎 N (No)', send: 'N', cls: 'btn-confirm-no', title: 'Confirm No (N)' },
                                { label: '⎵ SPACE', send: ' ', cls: 'btn-action', title: 'Spacebar' },
                                { label: '⏎ ENTER', code: 'Enter', cls: 'btn-enter', title: 'Enter' }
                            ],
                            [
                                { label: '1', send: '1' },
                                { label: '2', send: '2' },
                                { label: '3', send: '3' },
                                { label: '4', send: '4' },
                                { label: '5', send: '5' },
                                { label: '6', send: '6' },
                                { label: '7', send: '7' },
                                { label: '8', send: '8' },
                                { label: '9', send: '9' },
                                { label: '0', send: '0' },
                                { label: '⌫', code: 'Backspace', cls: 'btn-action' }
                            ]
                        ]
                    }
                },
                rows: [
                    [
                        { label: 'ESC', code: 'Escape', cls: 'btn-esc' },
                        { label: 'ENTER', code: 'Enter', cls: 'btn-enter' },
                        { label: 'SPACE', send: ' ', cls: 'btn-action' },
                        { label: '⌫', code: 'Backspace', cls: 'btn-action' },
                        { label: '⌨', cls: 'btn-utility btn-keyboard', allowFocus: true },
                        { label: '◐', cls: 'btn-utility btn-contrast-key', action: 'contrast' },
                        { type: 'zoom' }
                    ],
                    [
                        { label: '🌲 F (Forest)', send: 'F', cls: 'btn-lord-forest' },
                        { label: '⚔️ S (Slaught)', send: 'S', cls: 'btn-lord-slaught' },
                        { label: '🗡️ K (Weapons)', send: 'K', cls: 'btn-lord-weapons' },
                        { label: '🛡️ A (Armour)', send: 'A', cls: 'btn-lord-armour' },
                        { label: '🌿 H (Healer)', send: 'H', cls: 'btn-lord-healer' },
                        { label: '🍺 I (Inn)', send: 'I', cls: 'btn-lord-inn' }
                    ],
                    [
                        { label: '🏦 Y (Bank)', send: 'Y', cls: 'btn-lord-bank' },
                        { label: '🏋️ T (Train)', send: 'T', cls: 'btn-lord-train' },
                        { label: '📜 V (Stats)', send: 'V', cls: 'btn-lord-stats' },
                        { label: '📰 D (News)', send: 'D', cls: 'btn-lord-news' },
                        { label: '👥 L (List)', send: 'L', cls: 'btn-hotkey' },
                        { label: '✉️ W (Mail)', send: 'W', cls: 'btn-hotkey' },
                        { label: '🗺️ O (Other)', send: 'O', cls: 'btn-hotkey' },
                        { label: '👋 Q (Quit)', send: 'Q', cls: 'btn-confirm-no' }
                    ],
                    [
                        { label: '1', send: '1' },
                        { label: '2', send: '2' },
                        { label: '3', send: '3' },
                        { label: '4', send: '4' },
                        { label: '5', send: '5' },
                        { label: '6', send: '6' },
                        { label: '7', send: '7' },
                        { label: '8', send: '8' },
                        { label: '9', send: '9' },
                        { label: '0', send: '0' },
                        { label: 'Y', send: 'Y', cls: 'btn-confirm-yes' },
                        { label: 'N', send: 'N', cls: 'btn-confirm-no' }
                    ]
                ]
            },
            tw2002: {
                id: 'tw2002',
                name: 'Trade Wars 2002',
                shortName: 'TW2002',
                desc: 'Sector navigation, Port & Scan hotkeys',
                matches: ['tw', 'tw2002', 'bbslink-tw', 'tradewars', 'trade wars'],
                rows: [
                    // Row 1
                    [
                        { label: 'ESC', code: 'Escape', cls: 'btn-esc' },
                        { label: 'ENTER', code: 'Enter', cls: 'btn-enter' },
                        { label: 'SPACE', send: ' ', cls: 'btn-action' },
                        { label: '⌫', code: 'Backspace', cls: 'btn-action' },
                        { label: '⌨', cls: 'btn-utility btn-keyboard', allowFocus: true, title: 'Toggle Keyboard' },
                        { label: '◐', cls: 'btn-utility btn-contrast-key', action: 'contrast', title: 'Toggle High Contrast' },
                        { type: 'zoom' }
                    ],
                    // Row 2
                    [
                        { label: '◄', code: 'ArrowLeft', cls: 'btn-nav' },
                        { label: '▲', code: 'ArrowUp', cls: 'btn-nav' },
                        { label: '▼', code: 'ArrowDown', cls: 'btn-nav' },
                        { label: '►', code: 'ArrowRight', cls: 'btn-nav' },
                        { label: 'D', send: 'D', cls: 'btn-action', title: 'Display Sector' },
                        { label: 'P', send: 'P', cls: 'btn-action', title: 'Port' },
                        { label: 'M', send: 'M', cls: 'btn-action', title: 'Move' },
                        { label: 'S', send: 'S', cls: 'btn-action', title: 'Scan' }
                    ],
                    // Row 3
                    [
                        { label: '1', send: '1' },
                        { label: '2', send: '2' },
                        { label: '3', send: '3' },
                        { label: '4', send: '4' },
                        { label: '5', send: '5' },
                        { label: '6', send: '6' },
                        { label: '7', send: '7' },
                        { label: '8', send: '8' },
                        { label: '9', send: '9' },
                        { label: '0', send: '0' },
                        { type: 'sep' },
                        { label: 'C', send: 'C', cls: 'btn-hotkey', title: 'Computer' },
                        { label: 'R', send: 'R', cls: 'btn-hotkey', title: 'Report' },
                        { label: 'T', send: 'T', cls: 'btn-hotkey', title: 'TransWarp' },
                        { label: 'Y', send: 'Y', cls: 'btn-action', title: 'Yes' },
                        { label: 'N', send: 'N', cls: 'btn-action', title: 'No' },
                        { label: 'Q', send: 'Q', cls: 'btn-action', title: 'Quit' }
                    ]
                ]
            },
            pimpwars: {
                id: 'pimpwars',
                name: 'Pimp Wars',
                shortName: 'Pimp Wars',
                desc: "Dynamic: Street Corner, Sheinen's, Stiff Pete, Casino & Movement",
                matches: ['pimpwars', 'pimp wars', 'pimp-wars', 'bbslink-pimpwars', 'bbslink-pimp', 'pwars', 'pimp'],
                defaultSubMode: 'street',
                detectSubMode: function (text) {
                    if (!text) return null;
                    if (/steal\s*\[m\]oney|select pimp to|select whore|\[(whore|pimp)\s*roster\]/i.test(text)) {
                        return 'crime';
                    }
                    if (/\[\d+:\d+[AP]M\]\s*\[[^\]]+\]\s*:|Street Corner Options/i.test(text)) {
                        return 'street';
                    }
                    return null;
                },
                getDynamicMenuSignature: function (text, subMode) {
                    if (!text) return 'street';
                    const lines = text.split('\n').map(l => l.trim()).filter(Boolean);
                    const bottomText = lines.slice(-8).join('\n');

                    // 1. Prompts (Y/N, More)
                    if (/\[Y\/N\]|\(Y\/N\)|\(Yes\/No\)|More\?\s*\[|Do you give him the money\?|Are you sure you want to/i.test(bottomText)) {
                        return 'prompt_yes_no';
                    }

                    // 2. Move to Another Street
                    if (/move to which street|which street\s*\[1-99\]/i.test(bottomText)) {
                        return 'menu_move';
                    }

                    // 3. Steal / Sabotage / Rosters
                    if (/steal\s*\[m\]oney|select pimp to|select whore|\[(whore|pimp)\s*roster\]/i.test(bottomText)) {
                        return 'menu_steal';
                    }

                    // 4. Flaming Sheinen's Restaurant (Active prompt or screen header)
                    if (/\[FLAMING SHEINEN\'S\]\s*:|Flaming Sheinen\'s Restaurant Options|Eat a Flame Burger|Give Condoms to Whores|Hire Agent|Police Tip/i.test(bottomText || text)) {
                        return 'menu_sheinen';
                    }

                    // 5. Stiff Pete's Wardrobe (Active prompt or screen header)
                    if (/\[STIFF PETE\'S\]\s*:|Stiff Pete\'s Wardrobe Options|Buy New Clothes|Purchase Condoms|Examine Your Wardrobe/i.test(bottomText || text)) {
                        return 'menu_pete';
                    }

                    // 6. Pimp Wars Casino (Active prompt or screen header)
                    if (/\[CASINO\]\s*:|Pimp Wars Casino Options|Dice Black Jack|Slot Machine|Roulette|GUTS/i.test(bottomText || text)) {
                        return 'menu_casino';
                    }

                    // 7. Street Corner (Main Hub)
                    if (/Street Corner Options|\[\d+:\d+[AP]M\]\s*\[[^\]]+\]\s*:|Prices of Whore Houses|Disband Whore|Firebomb Whore House/i.test(bottomText || text)) {
                        return 'street';
                    }

                    return (subMode === 'crime') ? 'menu_steal' : 'street';
                },
                getDynamicRows: function (text, subMode, signature) {
                    const sysStreet = [
                        { label: 'ESC', code: 'Escape', cls: 'btn-esc' },
                        { label: 'ENTER', code: 'Enter', cls: 'btn-enter' },
                        { label: 'SPACE', send: ' ', cls: 'btn-action' },
                        { label: '⌫', code: 'Backspace', cls: 'btn-action' },
                        { label: '⌨', cls: 'btn-utility btn-keyboard', allowFocus: true, title: 'Toggle Keyboard' },
                        { label: '◐', cls: 'btn-utility btn-contrast-key', action: 'contrast', title: 'Toggle High Contrast' },
                        { label: '👊 Crime', action: 'submode:crime', cls: 'btn-submode-toggle', title: 'Switch to Crime Layout' },
                        { type: 'zoom' }
                    ];

                    const sysCrime = [
                        { label: 'ESC', code: 'Escape', cls: 'btn-esc' },
                        { label: 'ENTER', code: 'Enter', cls: 'btn-enter' },
                        { label: '👍 Y', send: 'Y', cls: 'btn-confirm-yes', title: 'Confirm Yes (Y)' },
                        { label: '👎 N', send: 'N', cls: 'btn-confirm-no', title: 'Confirm No (N)' },
                        { label: '⌨', cls: 'btn-utility btn-keyboard', allowFocus: true, title: 'Toggle Keyboard' },
                        { label: '🏙️ Streets', action: 'submode:street', cls: 'btn-submode-toggle', title: 'Switch to Street Layout' },
                        { type: 'zoom' }
                    ];

                    if (signature === 'prompt_yes_no') {
                        return [
                            sysStreet,
                            [
                                { label: '👍 YES (Y)', send: 'Y', cls: 'btn-confirm-yes', title: 'Yes (Y)' },
                                { label: '👎 NO (N)', send: 'N', cls: 'btn-confirm-no', title: 'No (N)' },
                                { label: '⎵ SPACE', send: ' ', cls: 'btn-action', title: 'Spacebar' },
                                { label: '⏎ ENTER', code: 'Enter', cls: 'btn-enter', title: 'Enter' }
                            ],
                            [
                                { label: '1', send: '1' },
                                { label: '2', send: '2' },
                                { label: '3', send: '3' },
                                { label: '4', send: '4' },
                                { label: '5', send: '5' },
                                { label: '6', send: '6' },
                                { label: '7', send: '7' },
                                { label: '8', send: '8' },
                                { label: '9', send: '9' },
                                { label: '0', send: '0' },
                                { label: 'Q', send: 'Q', cls: 'btn-confirm-no', title: 'Quit / Cancel (Q)' }
                            ]
                        ];
                    }

                    if (signature === 'menu_move') {
                        return [
                            sysStreet,
                            [
                                { label: '1st & Main', send: '1', cls: 'btn-action', title: '1st & Main (1)' },
                                { label: '2nd & Main', send: '2', cls: 'btn-action', title: '2nd & Main (2)' },
                                { label: '3rd & Main', send: '3', cls: 'btn-action', title: '3rd & Main (3)' },
                                { label: '4th & Main', send: '4', cls: 'btn-action', title: '4th & Main (4)' },
                                { label: '5th & Main', send: '5', cls: 'btn-action', title: '5th & Main (5)' }
                            ],
                            [
                                { label: '6th & Main', send: '6', cls: 'btn-action', title: '6th & Main (6)' },
                                { label: '7th & Main', send: '7', cls: 'btn-action', title: '7th & Main (7)' },
                                { label: '8th & Main', send: '8', cls: 'btn-action', title: '8th & Main (8)' },
                                { label: '👋 Q (Cancel)', send: 'Q', cls: 'btn-confirm-no', title: 'Cancel Movement (Q)' },
                                { label: 'ENTER', code: 'Enter', cls: 'btn-enter' }
                            ],
                            [
                                { label: '1', send: '1' },
                                { label: '2', send: '2' },
                                { label: '3', send: '3' },
                                { label: '4', send: '4' },
                                { label: '5', send: '5' },
                                { label: '6', send: '6' },
                                { label: '7', send: '7' },
                                { label: '8', send: '8' },
                                { label: '0', send: '0' },
                                { label: '⌫', code: 'Backspace', cls: 'btn-action' }
                            ]
                        ];
                    }

                    if (signature === 'menu_sheinen') {
                        return [
                            sysStreet,
                            [
                                { label: '🍔 E (Burger)', send: 'E', cls: 'btn-lord-inn', title: 'Eat a Flame Burger (E)' },
                                { label: '🧤 G (Condoms)', send: 'G', cls: 'btn-lord-armour', title: 'Give Condoms to Whores (G)' },
                                { label: '🕵️ H (Hire)', send: 'H', cls: 'btn-lord-forest', title: 'Hire Agent (H)' },
                                { label: '🚨 P (Police)', send: 'P', cls: 'btn-lord-slaught', title: 'Police Tip (P)' },
                                { label: '🗣️ S (Speak)', send: 'S', cls: 'btn-lord-weapons', title: 'Speak to Flaming Sheinen (S)' },
                                { label: '💬 T (Women)', send: 'T', cls: 'btn-hotkey', title: 'Talk to Women Here (T)' }
                            ],
                            [
                                { label: '🏙️ R (Streets)', send: 'R', cls: 'btn-action', title: 'Return to Streets (R)' },
                                { label: '🎰 C (Casino)', send: 'C', cls: 'btn-lord-inn', title: 'Pimpwars Casino (C)' },
                                { label: '👔 V (Pete)', send: 'V', cls: 'btn-lord-healer', title: 'Visit Stiff Pete (V)' },
                                { label: '💃 W (Whores)', send: 'W', cls: 'btn-hotkey', title: 'Whore Statistics (W)' },
                                { label: 'ℹ️ I (Info)', send: 'I', cls: 'btn-action', title: 'Information (I)' },
                                { label: '❓ ? (Help)', send: '?', cls: 'btn-hotkey', title: 'Help (?)' },
                                { label: '🚪 Q (Leave)', send: 'Q', cls: 'btn-confirm-no', title: 'Leave Sheinen (Q)' }
                            ],
                            [
                                { label: '1', send: '1' },
                                { label: '2', send: '2' },
                                { label: '3', send: '3' },
                                { label: '4', send: '4' },
                                { label: '5', send: '5' },
                                { label: '6', send: '6' },
                                { label: '7', send: '7' },
                                { label: '8', send: '8' },
                                { label: '9', send: '9' },
                                { label: '0', send: '0' },
                                { label: '👍 Y', send: 'Y', cls: 'btn-confirm-yes' },
                                { label: '👎 N', send: 'N', cls: 'btn-confirm-no' }
                            ]
                        ];
                    }

                    if (signature === 'menu_pete') {
                        return [
                            sysStreet,
                            [
                                { label: '👔 B (Buy Clothes)', send: 'B', cls: 'btn-lord-armour', title: 'Buy New Clothes (B)' },
                                { label: '🧤 P (Condoms)', send: 'P', cls: 'btn-lord-weapons', title: 'Purchase Condoms (P)' },
                                { label: '🪞 E (Examine)', send: 'E', cls: 'btn-hotkey', title: 'Examine Your Wardrobe (E)' },
                                { label: '🗣️ S (Speak)', send: 'S', cls: 'btn-lord-forest', title: 'Speak to Stiff Pete (S)' },
                                { label: '💲 $ (Prices)', send: '$', cls: 'btn-action', title: 'Prices of New Clothes ($)' }
                            ],
                            [
                                { label: '🏙️ R (Streets)', send: 'R', cls: 'btn-action', title: 'Return to Streets (R)' },
                                { label: '🍗 G (Sheinen)', send: 'G', cls: 'btn-lord-inn', title: "Go to Flaming Sheinen's (G)" },
                                { label: '🎰 C (Casino)', send: 'C', cls: 'btn-lord-inn', title: 'Pimpwars Casino (C)' },
                                { label: '💃 W (Whores)', send: 'W', cls: 'btn-hotkey', title: 'Whore Statistics (W)' },
                                { label: 'ℹ️ I (Info)', send: 'I', cls: 'btn-action', title: 'Information (I)' },
                                { label: '❓ ? (Help)', send: '?', cls: 'btn-hotkey', title: 'Help (?)' },
                                { label: '🚪 Q (Leave)', send: 'Q', cls: 'btn-confirm-no', title: 'Leave Stiff Pete (Q)' }
                            ],
                            [
                                { label: '1', send: '1' },
                                { label: '2', send: '2' },
                                { label: '3', send: '3' },
                                { label: '4', send: '4' },
                                { label: '5', send: '5' },
                                { label: '6', send: '6' },
                                { label: '7', send: '7' },
                                { label: '8', send: '8' },
                                { label: '9', send: '9' },
                                { label: '0', send: '0' },
                                { label: '👍 Y', send: 'Y', cls: 'btn-confirm-yes' },
                                { label: '👎 N', send: 'N', cls: 'btn-confirm-no' }
                            ]
                        ];
                    }

                    if (signature === 'menu_casino') {
                        return [
                            sysStreet,
                            [
                                { label: '🃏 B (Blackjack)', send: 'B', cls: 'btn-lord-inn', title: 'Play Blackjack (B)' },
                                { label: '🎲 C (Craps)', send: 'C', cls: 'btn-lord-bank', title: 'Play Craps (C)' },
                                { label: '🎲 D (Dice BJ)', send: 'D', cls: 'btn-lord-inn', title: 'Play Dice Blackjack (D)' },
                                { label: '🎡 E (Roulette)', send: 'E', cls: 'btn-lord-forest', title: 'Play Roulette (E)' },
                                { label: '🎰 S (Slots)', send: 'S', cls: 'btn-action', title: 'Play Slot Machine (S)' },
                                { label: '🔥 U (Guts)', send: 'U', cls: 'btn-lord-slaught', title: 'Play GUTS (U)' }
                            ],
                            [
                                { label: '🏙️ R (Streets)', send: 'R', cls: 'btn-action', title: 'Return to Streets (R)' },
                                { label: '🍗 G (Sheinen)', send: 'G', cls: 'btn-lord-inn', title: "Go to Flaming Sheinen's (G)" },
                                { label: '👔 V (Pete)', send: 'V', cls: 'btn-lord-healer', title: 'Visit Stiff Pete (V)' },
                                { label: '⏰ T (Time)', send: 'T', cls: 'btn-hotkey', title: 'Time Check (T)' },
                                { label: 'H (Hit)', send: 'H', cls: 'btn-action', title: 'Hit (H)' },
                                { label: 'S (Stand)', send: 'S', cls: 'btn-action', title: 'Stand (S)' },
                                { label: 'D (Double)', send: 'D', cls: 'btn-action', title: 'Double (D)' },
                                { label: '🚪 Q (Leave)', send: 'Q', cls: 'btn-confirm-no', title: 'Leave Casino (Q)' }
                            ],
                            [
                                { label: '1', send: '1' },
                                { label: '2', send: '2' },
                                { label: '3', send: '3' },
                                { label: '4', send: '4' },
                                { label: '5', send: '5' },
                                { label: '6', send: '6' },
                                { label: '7', send: '7' },
                                { label: '8', send: '8' },
                                { label: '9', send: '9' },
                                { label: '0', send: '0' },
                                { label: '👍 Y', send: 'Y', cls: 'btn-confirm-yes' },
                                { label: '👎 N', send: 'N', cls: 'btn-confirm-no' }
                            ]
                        ];
                    }

                    if (signature === 'menu_steal' || subMode === 'crime') {
                        return [
                            sysCrime,
                            [
                                { label: '💵 M (Money)', send: 'M', cls: 'btn-lord-bank', title: 'Steal Money (M)' },
                                { label: '💃 W (Whores)', send: 'W', cls: 'btn-hotkey', title: 'Steal Whores (W)' },
                                { label: '🚪 Q (Cancel)', send: 'Q', cls: 'btn-confirm-no', title: 'Cancel / Quit (Q)' }
                            ],
                            [
                                { label: '1', send: '1' },
                                { label: '2', send: '2' },
                                { label: '3', send: '3' },
                                { label: '4', send: '4' },
                                { label: '5', send: '5' },
                                { label: '👍 Y', send: 'Y', cls: 'btn-confirm-yes', title: 'Yes' },
                                { label: '👎 N', send: 'N', cls: 'btn-confirm-no', title: 'No' },
                                { label: '⏎ ENTER', code: 'Enter', cls: 'btn-enter' }
                            ],
                            [
                                { label: '6', send: '6' },
                                { label: '7', send: '7' },
                                { label: '8', send: '8' },
                                { label: '9', send: '9' },
                                { label: '0', send: '0' },
                                { label: '⎵ SPACE', send: ' ', cls: 'btn-action' },
                                { label: '⌫', code: 'Backspace', cls: 'btn-action' }
                            ]
                        ];
                    }

                    // Default Street Corner (Main Hub)
                    return [
                        sysStreet,
                        [
                            { label: '🚶 M (Move)', send: 'M', cls: 'btn-lord-forest', title: 'Move to Another Street (M)' },
                            { label: '🍗 G (Sheinen)', send: 'G', cls: 'btn-lord-weapons', title: "Go to Flaming Sheinen's Restaurant (G)" },
                            { label: '👔 V (Pete)', send: 'V', cls: 'btn-lord-healer', title: "Visit Stiff Pete's Wardrobe (V)" },
                            { label: '🎰 C (Casino)', send: 'C', cls: 'btn-lord-inn', title: 'Pimpwars Casino (C)' },
                            { label: '👊 T (Steal)', send: 'T', cls: 'btn-lord-slaught', title: 'Steal from Another Pimp (T)' },
                            { label: '💣 F (Bomb)', send: 'F', cls: 'btn-action btn-combat-atk', title: 'Firebomb Whore House (F)' }
                        ],
                        [
                            { label: '🏠 S (House)', send: 'S', cls: 'btn-lord-bank', title: 'Set up Whore House (S)' },
                            { label: '📜 Y (My Stats)', send: 'Y', cls: 'btn-lord-stats', title: 'Your Statistics (Y)' },
                            { label: '💃 W (Whores)', send: 'W', cls: 'btn-hotkey', title: 'Whore Statistics (W)' },
                            { label: '🏆 P (Rank)', send: 'P', cls: 'btn-hotkey', title: 'Player Rankings (P)' },
                            { label: '🔍 E (Examine)', send: 'E', cls: 'btn-hotkey', title: 'Examine Another Pimp (E)' },
                            { label: '❌ D (Disband)', send: 'D', cls: 'btn-hotkey', title: 'Disband Whore (D)' },
                            { label: '💲 $ (Prices)', send: '$', cls: 'btn-action', title: 'Prices of Whore Houses ($)' }
                        ],
                        [
                            { label: '1', send: '1' },
                            { label: '2', send: '2' },
                            { label: '3', send: '3' },
                            { label: '4', send: '4' },
                            { label: '5', send: '5' },
                            { label: '6', send: '6' },
                            { label: '7', send: '7' },
                            { label: '8', send: '8' },
                            { label: '9', send: '9' },
                            { label: '0', send: '0' },
                            { label: 'ℹ️ I', send: 'I', cls: 'btn-action', title: 'Information (I)' },
                            { label: '?', send: '?', cls: 'btn-hotkey', title: 'Help (?)' },
                            { label: '👋 Q', send: 'Q', cls: 'btn-confirm-no', title: 'Quit (Q)' }
                        ]
                    ];
                },
                subModes: {
                    street: {
                        id: 'street',
                        name: 'Street Corner',
                        shortName: 'Street',
                        icon: '🏙️',
                        rows: [
                            [
                                { label: 'ESC', code: 'Escape', cls: 'btn-esc' },
                                { label: 'ENTER', code: 'Enter', cls: 'btn-enter' },
                                { label: 'SPACE', send: ' ', cls: 'btn-action' },
                                { label: '⌫', code: 'Backspace', cls: 'btn-action' },
                                { label: '⌨', cls: 'btn-utility btn-keyboard', allowFocus: true, title: 'Toggle Keyboard' },
                                { label: '◐', cls: 'btn-utility btn-contrast-key', action: 'contrast', title: 'Toggle High Contrast' },
                                { label: '⚔ Rumble', action: 'submode:combat', cls: 'btn-submode-toggle', title: 'Switch to Rumble Layout' },
                                { type: 'zoom' }
                            ],
                            [
                                { label: '🚶 M (Move)', send: 'M', cls: 'btn-lord-forest', title: 'Move to Another Street (M)' },
                                { label: '🔫 G (Guns)', send: 'G', cls: 'btn-lord-weapons', title: "Go to Flaming Sheinen's Guns (G)" },
                                { label: '🏥 V (Pete)', send: 'V', cls: 'btn-lord-healer', title: 'Visit Stiff Pete Doctor (V)' },
                                { label: '🎰 C (Casino)', send: 'C', cls: 'btn-lord-inn', title: 'Pimpwars Casino (C)' },
                                { label: '👊 T (Steal)', send: 'T', cls: 'btn-lord-slaught', title: 'Steal from Another Pimp (T)' },
                                { label: '💣 F (Bomb)', send: 'F', cls: 'btn-action btn-combat-atk', title: 'Firebomb Whore House (F)' }
                            ],
                            [
                                { label: '🏠 S (House)', send: 'S', cls: 'btn-lord-bank', title: 'Set up Whore House (S)' },
                                { label: '📜 Y (My Stats)', send: 'Y', cls: 'btn-lord-stats', title: 'Your Statistics (Y)' },
                                { label: '💃 W (Whores)', send: 'W', cls: 'btn-hotkey', title: 'Whore Statistics (W)' },
                                { label: '🏆 P (Rank)', send: 'P', cls: 'btn-hotkey', title: 'Player Rankings (P)' },
                                { label: '🔍 E (Examine)', send: 'E', cls: 'btn-hotkey', title: 'Examine Another Pimp (E)' },
                                { label: '❌ D (Disband)', send: 'D', cls: 'btn-hotkey', title: 'Disband Whore (D)' },
                                { label: '💲 $ (Prices)', send: '$', cls: 'btn-action', title: 'Prices of Whore Houses ($)' },
                                { label: '👋 Q (Quit)', send: 'Q', cls: 'btn-confirm-no', title: 'Quit (Q)' }
                            ],
                            [
                                { label: '1', send: '1' },
                                { label: '2', send: '2' },
                                { label: '3', send: '3' },
                                { label: '4', send: '4' },
                                { label: '5', send: '5' },
                                { label: '6', send: '6' },
                                { label: '7', send: '7' },
                                { label: '8', send: '8' },
                                { label: '9', send: '9' },
                                { label: '0', send: '0' },
                                { label: 'Y', send: 'Y', cls: 'btn-confirm-yes', title: 'Yes (Y)' },
                                { label: 'N', send: 'N', cls: 'btn-confirm-no', title: 'No (N)' },
                                { label: '?', send: '?', cls: 'btn-hotkey', title: 'Help (?)' }
                            ]
                        ]
                    },
                    combat: {
                        id: 'combat',
                        name: 'Rumble & Combat',
                        shortName: 'Rumble',
                        icon: '👊',
                        rows: [
                            [
                                { label: 'ESC', code: 'Escape', cls: 'btn-esc' },
                                { label: 'ENTER', code: 'Enter', cls: 'btn-enter' },
                                { label: '👍 Y', send: 'Y', cls: 'btn-confirm-yes', title: 'Confirm Yes (Y)' },
                                { label: '👎 N', send: 'N', cls: 'btn-confirm-no', title: 'Confirm No (N)' },
                                { label: '⚔️ A (Atk)', send: 'A', cls: 'btn-action btn-combat-atk', title: 'Attack (A)' },
                                { label: '🏃 F (Flee)', send: 'F', cls: 'btn-action btn-combat-flee', title: 'Flee (F)' },
                                { label: '⌨', cls: 'btn-utility btn-keyboard', allowFocus: true, title: 'Toggle Keyboard' },
                                { label: '🏙️ Street', action: 'submode:street', cls: 'btn-submode-toggle', title: 'Switch to Street Layout' }
                            ],
                            [
                                { label: '⚔️ A (Attack)', send: 'A', cls: 'btn-space-combat btn-combat-atk', title: 'Attack (A)' },
                                { label: '🏃 F (Flee)', send: 'F', cls: 'btn-action btn-combat-flee', title: 'Flee (F)' },
                                { label: '🔫 S (Shoot)', send: 'S', cls: 'btn-lord-weapons', title: 'Shoot (S)' },
                                { label: '👍 Y (Yes)', send: 'Y', cls: 'btn-confirm-yes', title: 'Yes (Y)' },
                                { label: '👎 N (No)', send: 'N', cls: 'btn-confirm-no', title: 'No (N)' }
                            ],
                            [
                                { label: '📜 Y (Stats)', send: 'Y', cls: 'btn-lord-stats', title: 'My Stats (Y)' },
                                { label: '🔍 E (Examine)', send: 'E', cls: 'btn-hotkey', title: 'Examine (E)' },
                                { label: '⎵ SPACE', send: ' ', cls: 'btn-action', title: 'Spacebar' },
                                { label: '⏎ ENTER', code: 'Enter', cls: 'btn-enter', title: 'Enter' }
                            ],
                            [
                                { label: '1', send: '1' },
                                { label: '2', send: '2' },
                                { label: '3', send: '3' },
                                { label: '4', send: '4' },
                                { label: '5', send: '5' },
                                { label: '6', send: '6' },
                                { label: '7', send: '7' },
                                { label: '8', send: '8' },
                                { label: '9', send: '9' },
                                { label: '0', send: '0' },
                                { label: '⌫', code: 'Backspace', cls: 'btn-action' }
                            ]
                        ]
                    }
                },
                rows: [
                    [
                        { label: 'ESC', code: 'Escape', cls: 'btn-esc' },
                        { label: 'ENTER', code: 'Enter', cls: 'btn-enter' },
                        { label: 'SPACE', send: ' ', cls: 'btn-action' },
                        { label: '⌫', code: 'Backspace', cls: 'btn-action' },
                        { label: '⌨', cls: 'btn-utility btn-keyboard', allowFocus: true },
                        { label: '◐', cls: 'btn-utility btn-contrast-key', action: 'contrast' },
                        { type: 'zoom' }
                    ],
                    [
                        { label: '🚶 M', send: 'M', cls: 'btn-lord-forest' },
                        { label: '🔫 G', send: 'G', cls: 'btn-lord-weapons' },
                        { label: '🏥 V', send: 'V', cls: 'btn-lord-healer' },
                        { label: '🎰 C', send: 'C', cls: 'btn-lord-inn' },
                        { label: '👊 T', send: 'T', cls: 'btn-lord-slaught' },
                        { label: '💣 F', send: 'F', cls: 'btn-action' }
                    ],
                    [
                        { label: '🏠 S', send: 'S', cls: 'btn-lord-bank' },
                        { label: '📜 Y', send: 'Y', cls: 'btn-lord-stats' },
                        { label: '💃 W', send: 'W', cls: 'btn-hotkey' },
                        { label: '🏆 P', send: 'P', cls: 'btn-hotkey' },
                        { label: '🔍 E', send: 'E', cls: 'btn-hotkey' },
                        { label: '❌ D', send: 'D', cls: 'btn-hotkey' },
                        { label: '💲 $', send: '$', cls: 'btn-action' },
                        { label: '👋 Q', send: 'Q', cls: 'btn-confirm-no' }
                    ],
                    [
                        { label: '1', send: '1' },
                        { label: '2', send: '2' },
                        { label: '3', send: '3' },
                        { label: '4', send: '4' },
                        { label: '5', send: '5' },
                        { label: '6', send: '6' },
                        { label: '7', send: '7' },
                        { label: '8', send: '8' },
                        { label: '9', send: '9' },
                        { label: '0', send: '0' },
                        { label: 'Y', send: 'Y', cls: 'btn-confirm-yes' },
                        { label: 'N', send: 'N', cls: 'btn-confirm-no' }
                    ]
                ]
            },
            default: {
                id: 'default',
                name: 'Standard Gamepad',
                shortName: 'Default',
                desc: 'Classic Arrow D-Pad, Actions, Numbers 0-9',
                matches: [],
                rows: [
                    // Row 1
                    [
                        { label: 'ESC', code: 'Escape', cls: 'btn-esc' },
                        { label: 'ENTER', code: 'Enter', cls: 'btn-enter' },
                        { label: 'SPACE', send: ' ', cls: 'btn-action' },
                        { label: '⌫', code: 'Backspace', cls: 'btn-action' },
                        { label: '⌨', cls: 'btn-utility btn-keyboard', allowFocus: true, title: 'Toggle Keyboard' },
                        { label: '◐', cls: 'btn-utility btn-contrast-key', action: 'contrast', title: 'Toggle High Contrast' },
                        { type: 'zoom' }
                    ],
                    // Row 2
                    [
                        { label: '◄', code: 'ArrowLeft', cls: 'btn-nav' },
                        { label: '▲', code: 'ArrowUp', cls: 'btn-nav' },
                        { label: '▼', code: 'ArrowDown', cls: 'btn-nav' },
                        { label: '►', code: 'ArrowRight', cls: 'btn-nav' },
                        { label: 'Y', send: 'Y', cls: 'btn-action', title: 'Yes' },
                        { label: 'N', send: 'N', cls: 'btn-action', title: 'No' },
                        { label: 'Q', send: 'Q', cls: 'btn-action', title: 'Quit' }
                    ],
                    // Row 3
                    [
                        { label: '1', send: '1' },
                        { label: '2', send: '2' },
                        { label: '3', send: '3' },
                        { label: '4', send: '4' },
                        { label: '5', send: '5' },
                        { label: '6', send: '6' },
                        { label: '7', send: '7' },
                        { label: '8', send: '8' },
                        { label: '9', send: '9' },
                        { label: '0', send: '0' }
                    ]
                ]
            }
        };

        /**
         * Register a custom profile layout
         */
        static registerProfile(id, profile) {
            BinktermMobileTerminalAddon.LAYOUT_PROFILES[id] = Object.assign({ id }, profile);
        }

        constructor(options = {}) {
            this.options = Object.assign({
                doorId: '',
                doorName: '',
                cols: 80,
                rows: 25,
                isNative: false, // false = DOS Doorway scan codes, true = ANSI escape sequences
                onKey: null,     // Callback function(data) to pass raw key bytes to websocket
                defaultScaleMode: 'fit-screen', // 'fit-screen' | 'fit-width' | 'native'
                defaultContrastMode: 'vivid',   // High Contrast Vivid by default on mobile!
                minimizeKeyboardDesktop: false, // Minimize gamepad on desktop by default
                customLayout: null,
                storageKey: 'binkterm_mobile_terminal_config',
                containerId: 'terminal-container'
            }, options);

            this.term = null;
            this.terminalElement = null;
            this.scalerElement = null;
            this.viewportElement = null;
            this.toolbarElement = null;
            this.settingsModal = null;
            this.doorId = (this.options.doorId || '').toLowerCase();
            this.doorName = (this.options.doorName || '').toLowerCase();

            // Detect mobile/touch context early
            this.isMobile = this.detectMobile();

            // Load saved configuration or fall back to defaults
            const savedConfig = this.loadConfig();
            this.scaleMode = savedConfig.scaleMode || this.options.defaultScaleMode;
            
            // Migrate to vivid mode if this is the new version or contrastMode is unset
            if (savedConfig.contrastVersion !== 2) {
                this.contrastMode = 'vivid';
                this.saveConfig({ contrastMode: 'vivid', contrastVersion: 2 });
            } else {
                this.contrastMode = savedConfig.contrastMode || this.options.defaultContrastMode;
            }

            const defaultCollapsed = (!this.isMobile && !!this.options.minimizeKeyboardDesktop);
            if (savedConfig.toolbarCollapsed !== undefined) {
                this.toolbarCollapsed = !!savedConfig.toolbarCollapsed;
            } else {
                this.toolbarCollapsed = defaultCollapsed;
            }
            this.userZoom = parseFloat(savedConfig.userZoom || '1.0');

            // Resolve active layout profile for this game
            this.activeProfileId = this.detectProfileId(this.doorId, this.doorName);
            const activeProfile = BinktermMobileTerminalAddon.LAYOUT_PROFILES[this.activeProfileId];
            this.activeSubMode = (activeProfile && activeProfile.subModes)
                ? (activeProfile.defaultSubMode || Object.keys(activeProfile.subModes)[0])
                : null;
            this.autoSubMode = true; // Auto-detect game state (combat vs movement)
            this._snifferTimer = null;
            this.subModePill = null;
            this._activeMenuSignature = null;
            this._writeParsedListener = null;
            this._renderListener = null;

            this.isMobile = this.detectMobile();
            this._boundRescale = this.rescale.bind(this);
            this._boundVisualViewportHandler = this.handleVisualViewport.bind(this);
            this._boundMessageHandler = this.handleWindowMessage.bind(this);
            this._boundWheelHandler = this.handleWheel.bind(this);
            this._lastComputedScale = 1.0;
        }

        /**
         * Detect touch or mobile screen context
         */
        detectMobile() {
            const hasTouch = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
            const isSmallScreen = window.innerWidth <= 850 || window.innerHeight <= 850;
            const isMobileUA = /iPhone|iPad|iPod|Android|Mobile|Silk/i.test(navigator.userAgent || '');
            return (hasTouch && isSmallScreen) || isMobileUA;
        }

        /**
         * Detect profile ID based on door identifier or stored user preference
         */
        detectProfileId(doorId, doorName) {
            // 1. Check if user manually picked a profile for this door in localStorage
            if (doorId) {
                const savedDoorProfile = this.loadConfig('doorProfile_' + doorId);
                if (savedDoorProfile && BinktermMobileTerminalAddon.LAYOUT_PROFILES[savedDoorProfile]) {
                    return savedDoorProfile;
                }
            }

            // 2. Check if custom layout was provided in constructor options
            if (this.options.customLayout) {
                if (typeof this.options.customLayout === 'string' && BinktermMobileTerminalAddon.LAYOUT_PROFILES[this.options.customLayout]) {
                    return this.options.customLayout;
                }
                if (typeof this.options.customLayout === 'object' && this.options.customLayout.rows) {
                    const customId = 'custom_' + (doorId || 'game');
                    BinktermMobileTerminalAddon.registerProfile(customId, this.options.customLayout);
                    return customId;
                }
            }

            // 3. Fallback: try reading door title from parent frame or document if not supplied
            if (!doorName) {
                try {
                    if (window.parent && window.parent !== window && window.parent.document) {
                        const parentTitleEl = window.parent.document.querySelector('.door-title');
                        if (parentTitleEl) {
                            doorName = parentTitleEl.textContent;
                        } else if (window.parent.document.title) {
                            doorName = window.parent.document.title;
                        }
                    }
                } catch (_) {}

                if (!doorName) {
                    const headerEl = document.querySelector('.door-header') || document.querySelector('h1, h2, h3, h4, h5, h6');
                    doorName = (headerEl ? headerEl.textContent : '') + ' ' + (document.title || '');
                }
            }

            // 4. Match doorId or doorName against registered profile keywords
            const checkStr = (doorId + ' ' + (doorName || '')).toLowerCase();
            for (const profileKey in BinktermMobileTerminalAddon.LAYOUT_PROFILES) {
                const profile = BinktermMobileTerminalAddon.LAYOUT_PROFILES[profileKey];
                if (profile.matches && profile.matches.some(pattern => checkStr.includes(pattern))) {
                    return profile.id;
                }
            }

            return 'default';
        }

        /**
         * Set door info dynamically when session launches
         */
        setDoorInfo(doorId, doorName, customLayout) {
            this.doorId = (doorId || this.doorId).toLowerCase();
            this.doorName = (doorName || this.doorName).toLowerCase();
            if (customLayout) {
                this.options.customLayout = customLayout;
            }

            const targetProfile = this.detectProfileId(this.doorId, this.doorName);
            if (targetProfile !== this.activeProfileId) {
                this.setProfile(targetProfile, false);
            }
        }

        /**
         * Switch active gamepad profile
         */
        setProfile(profileId, saveUserChoice = true) {
            if (!BinktermMobileTerminalAddon.LAYOUT_PROFILES[profileId]) {
                profileId = 'default';
            }
            this.activeProfileId = profileId;
            this._activeMenuSignature = null;
            const profile = BinktermMobileTerminalAddon.LAYOUT_PROFILES[profileId];
            if (profile && profile.subModes) {
                this.activeSubMode = profile.defaultSubMode || Object.keys(profile.subModes)[0];
                this.autoSubMode = true;
            } else {
                this.activeSubMode = null;
            }

            if (saveUserChoice && this.doorId) {
                const update = {};
                update['doorProfile_' + this.doorId] = profileId;
                this.saveConfig(update);
            }

            this.renderToolbar();
            this.rescale();
            console.log('[MobileTerminalAddon] Switched to layout profile:', profileId);
        }

        /**
         * Switch active submode (e.g. 'combat' vs 'movement' in OOII)
         */
        setSubMode(subModeId, userInitiated = false) {
            const profile = BinktermMobileTerminalAddon.LAYOUT_PROFILES[this.activeProfileId];
            if (!profile || !profile.subModes || !profile.subModes[subModeId]) return;

            if (this.activeSubMode === subModeId && (!userInitiated || !this.autoSubMode)) {
                return;
            }

            this.activeSubMode = subModeId;
            this._activeMenuSignature = null;
            if (userInitiated) {
                // User manually clicked submode toggle -> Lock into this submode
                this.autoSubMode = false;
            }

            this.renderToolbar();
            this.rescale();
            console.log(`[MobileTerminalAddon] Submode set to '${subModeId}' (auto=${this.autoSubMode})`);
        }

        /**
         * Toggle submode auto-detection or cycle submodes
         */
        toggleSubModeAuto() {
            const profile = BinktermMobileTerminalAddon.LAYOUT_PROFILES[this.activeProfileId];
            if (!profile || !profile.subModes) return;

            if (!this.autoSubMode) {
                this.autoSubMode = true;
                this.sniffTerminalState();
                this.renderToolbar();
            } else {
                const subModeKeys = Object.keys(profile.subModes);
                const currentIdx = subModeKeys.indexOf(this.activeSubMode);
                const nextKey = subModeKeys[(currentIdx + 1) % subModeKeys.length];
                this.setSubMode(nextKey, true);
            }
        }

        /**
         * Extract visible text lines from active terminal buffer
         */
        getScreenText() {
            if (!this.term || !this.term.buffer || !this.term.buffer.active) return '';
            try {
                const buffer = this.term.buffer.active;
                const lines = [];
                const rows = this.term.rows || 25;
                const baseY = (typeof buffer.baseY === 'number') ? buffer.baseY : (buffer.viewportY || 0);
                for (let y = 0; y < rows; y++) {
                    const line = buffer.getLine(baseY + y) || buffer.getLine(buffer.viewportY + y) || buffer.getLine(y);
                    if (line) {
                        const str = line.translateToString(true);
                        lines.push(str);
                    }
                }
                return lines.join('\n');
            } catch (_) {
                return '';
            }
        }

        /**
         * Debounce terminal screen buffer sniffing
         */
        scheduleScreenSniffer() {
            if (!this.autoSubMode) return;
            if (this._snifferTimer) return;
            this._snifferTimer = setTimeout(() => {
                this._snifferTimer = null;
                this.sniffTerminalState();
            }, 120);
        }

        /**
         * Inspect visible terminal buffer and auto-switch game submodes or dynamic menus
         */
        sniffTerminalState() {
            if (!this.autoSubMode || !this.term) return;
            const profile = BinktermMobileTerminalAddon.LAYOUT_PROFILES[this.activeProfileId];
            if (!profile) return;

            const screenText = this.getScreenText();
            if (!screenText || screenText.length < 5) return;

            let stateChanged = false;

            // 1. Detect game submode shifts (e.g. Town vs Combat in LORD or OOII)
            if (profile.subModes && typeof profile.detectSubMode === 'function') {
                const targetSubMode = profile.detectSubMode(screenText);
                if (targetSubMode && targetSubMode !== this.activeSubMode && profile.subModes[targetSubMode]) {
                    console.log(`[MobileTerminalAddon] Detected game state shift: ${this.activeSubMode} -> ${targetSubMode}`);
                    this.activeSubMode = targetSubMode;
                    this._activeMenuSignature = null;
                    stateChanged = true;
                }
            }

            // 2. Detect dynamic in-game menu signature shifts (e.g. Town Square vs Bank vs Inn vs Forest)
            if (typeof profile.getDynamicMenuSignature === 'function') {
                const targetSignature = profile.getDynamicMenuSignature(screenText, this.activeSubMode);
                if (targetSignature !== this._activeMenuSignature) {
                    this._activeMenuSignature = targetSignature;
                    stateChanged = true;
                }
            }

            if (stateChanged) {
                this.renderToolbar();
                this.rescale();
            }
        }

        /**
         * xterm.js Addon activate lifecycle
         */
        activate(term) {
            this.term = term;
            this.terminalElement = term.element;

            console.log('[MobileTerminalAddon] Activating mobile terminal addon. Profile:', this.activeProfileId, 'IsMobile:', this.isMobile);

            // Set up DOM hierarchy:
            // container -> viewportElement -> scalerElement -> terminalElement
            this.setupDOM();

            // Build mobile virtual keys toolbar & controller
            this.setupToolbar();

            // Hook header contrast button if present
            this.hookHeaderContrastButton();

            // Apply active contrast mode & theme
            this.applyContrast();

            // Attach resize and visualViewport listeners
            window.addEventListener('resize', this._boundRescale);
            window.addEventListener('orientationchange', () => {
                setTimeout(this._boundRescale, 200);
            });
            window.addEventListener('message', this._boundMessageHandler);

            if (window.visualViewport) {
                window.visualViewport.addEventListener('resize', this._boundVisualViewportHandler);
                window.visualViewport.addEventListener('scroll', this._boundVisualViewportHandler);
            }

            // Click/tap on scaler or viewport focuses the terminal
            this.viewportElement.addEventListener('click', (e) => {
                if (e.target.closest('.binkterm-mobile-toolbar') || e.target.closest('.binkterm-mobile-modal')) {
                    return;
                }
                this.focus();
            });

            // Intercept mouse wheel events on viewport to prevent xterm from synthesizing
            // spurious cursor/mouse escape sequences (which echo as periods '.' in DOS doors).
            this.viewportElement.addEventListener('wheel', this._boundWheelHandler, { capture: true, passive: false });

            // Hook terminal buffer writes to dynamically detect in-game state changes (e.g. combat vs movement)
            if (typeof term.onWriteParsed === 'function') {
                this._writeParsedListener = term.onWriteParsed(() => this.scheduleScreenSniffer());
            } else if (typeof term.onRender === 'function') {
                this._renderListener = term.onRender(() => this.scheduleScreenSniffer());
            }

            // Initial calculation & initial sniffs
            this.rescale();
            setTimeout(this._boundRescale, 150);
            setTimeout(this._boundRescale, 500);
            setTimeout(() => this.sniffTerminalState(), 600);
            setTimeout(() => this.sniffTerminalState(), 1500);
        }

        /**
         * xterm.js Addon dispose lifecycle
         */
        dispose() {
            window.removeEventListener('resize', this._boundRescale);
            window.removeEventListener('message', this._boundMessageHandler);
            if (window.visualViewport) {
                window.visualViewport.removeEventListener('resize', this._boundVisualViewportHandler);
                window.visualViewport.removeEventListener('scroll', this._boundVisualViewportHandler);
            }
            if (this.viewportElement) {
                this.viewportElement.removeEventListener('wheel', this._boundWheelHandler, { capture: true, passive: false });
            }
            if (this._snifferTimer) {
                clearTimeout(this._snifferTimer);
                this._snifferTimer = null;
            }
            if (this._writeParsedListener && typeof this._writeParsedListener.dispose === 'function') {
                this._writeParsedListener.dispose();
                this._writeParsedListener = null;
            }
            if (this._renderListener && typeof this._renderListener.dispose === 'function') {
                this._renderListener.dispose();
                this._renderListener = null;
            }
            if (this.toolbarElement && this.toolbarElement.parentElement) {
                this.toolbarElement.remove();
            }
            if (this.settingsModal && this.settingsModal.parentElement) {
                this.settingsModal.remove();
            }
        }

        /**
         * Wrap the terminal element in viewport and scaler containers
         */
        setupDOM() {
            const container = document.getElementById(this.options.containerId) || this.terminalElement.parentElement;
            container.style.display = 'flex';
            container.style.flexDirection = 'column';
            container.style.alignItems = 'stretch';
            container.style.justifyContent = 'flex-start';
            container.style.overflow = 'hidden';

            this.viewportElement = document.createElement('div');
            this.viewportElement.className = 'binkterm-mobile-viewport mode-' + this.scaleMode;

            this.scalerElement = document.createElement('div');
            this.scalerElement.className = 'binkterm-mobile-scaler';

            // Insert viewport in place of terminal element, then append scaler & terminal
            container.insertBefore(this.viewportElement, this.terminalElement);
            this.scalerElement.appendChild(this.terminalElement);
            this.viewportElement.appendChild(this.scalerElement);
        }

        /**
         * Hook contrast toggle button in .terminal-controls if present
         */
        hookHeaderContrastButton() {
            const contrastBtn = document.getElementById('contrastBtn');
            if (contrastBtn) {
                contrastBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    this.toggleContrast();
                });
            }
            this.updateContrastButtons();
        }

        /**
         * Initialize toolbar container and render active profile layout
         */
        setupToolbar() {
            this.toolbarElement = document.createElement('div');
            this.toolbarElement.className = 'binkterm-mobile-toolbar';

            // Tab bar container holding Collapse button, Layout pill, and Submode pill
            const tabWrapper = document.createElement('div');
            tabWrapper.className = 'binkterm-toolbar-tab-wrapper';

            // Layout Picker Pill
            this.layoutPill = document.createElement('div');
            this.layoutPill.className = 'binkterm-layout-pill';
            this.layoutPill.title = 'Change Gamepad Layout';
            this.layoutPill.addEventListener('click', (e) => {
                e.stopPropagation();
                this.openLayoutModal();
            });
            tabWrapper.appendChild(this.layoutPill);

            // Submode Dynamic Pill (Combat / Movement indicator & switcher)
            this.subModePill = document.createElement('div');
            this.subModePill.className = 'binkterm-submode-pill';
            this.subModePill.title = 'Current Mode. Tap to toggle or switch Auto/Manual';
            this.subModePill.addEventListener('click', (e) => {
                e.stopPropagation();
                this.toggleSubModeAuto();
            });
            tabWrapper.appendChild(this.subModePill);

            // Collapse/Expand Tab
            const tab = document.createElement('div');
            tab.className = 'binkterm-toolbar-tab';
            tab.innerHTML = `<span class="tab-icon">${this.toolbarCollapsed ? '▲' : '▼'}</span> <span class="tab-label">${this.toolbarCollapsed ? 'Keys' : 'Hide'}</span>`;
            tab.addEventListener('click', (e) => {
                e.stopPropagation();
                this.toggleCollapseToolbar();
            });
            tabWrapper.appendChild(tab);

            this.toolbarElement.appendChild(tabWrapper);

            if (this.toolbarCollapsed) {
                this.toolbarElement.classList.add('is-collapsed');
            }

            // Render buttons for current active profile
            this.renderToolbar();

            // Append toolbar inside container after viewportElement
            const container = this.viewportElement.parentElement;
            container.appendChild(this.toolbarElement);
        }

        /**
         * Build the gamepad rows according to the active profile and active submode
         */
        renderToolbar() {
            if (!this.toolbarElement) return;

            // Remove existing rows (keep tab wrapper)
            const oldRows = this.toolbarElement.querySelectorAll('.binkterm-toolbar-row');
            oldRows.forEach(r => r.remove());

            const profile = BinktermMobileTerminalAddon.LAYOUT_PROFILES[this.activeProfileId] ||
                            BinktermMobileTerminalAddon.LAYOUT_PROFILES.default;

            // Update layout pill text
            if (this.layoutPill) {
                this.layoutPill.innerHTML = `<span>🎮</span> <span>${profile.shortName || profile.name}</span> <span style="font-size:8px;">▾</span>`;
            }

            // Update submode pill
            if (this.subModePill) {
                if (profile.subModes) {
                    const currentSub = profile.subModes[this.activeSubMode] ||
                                       profile.subModes[profile.defaultSubMode] ||
                                       Object.values(profile.subModes)[0];
                    const isCombat = this.activeSubMode === 'combat';
                    this.subModePill.style.display = 'inline-flex';
                    this.subModePill.className = 'binkterm-submode-pill' + (isCombat ? ' mode-combat' : '');

                    let pillLabel = currentSub.shortName || currentSub.name;
                    let pillIcon = currentSub.icon || '🎮';
                    if (this.activeProfileId === 'lord' && this._activeMenuSignature) {
                        const sigMap = {
                            'menu_forest': { label: 'Forest', icon: '🌲' },
                            'menu_town': { label: 'Town', icon: '🏰' },
                            'menu_bank': { label: 'Bank', icon: '🏦' },
                            'menu_armour': { label: 'Armour', icon: '🛡️' },
                            'menu_weapons': { label: 'Weapons', icon: '🗡️' },
                            'menu_healer': { label: 'Healer', icon: '🌿' },
                            'menu_training': { label: 'Training', icon: '🏋️' },
                            'menu_inn': { label: 'Inn', icon: '🍺' },
                            'combat': { label: 'Combat', icon: '⚔️' },
                            'prompt_yes_no': { label: 'Yes/No', icon: '❓' },
                            'prompt_news': { label: 'News', icon: '📰' }
                        };
                        if (sigMap[this._activeMenuSignature]) {
                            pillLabel = sigMap[this._activeMenuSignature].label;
                            pillIcon = sigMap[this._activeMenuSignature].icon;
                        }
                    } else if (this.activeProfileId === 'ooii' && this._activeMenuSignature) {
                        const sigMap = {
                            'movement': { label: 'Wasteland', icon: '🧭' },
                            'combat': { label: 'Combat', icon: '⚔️' },
                            'inventory': { label: 'Inventory', icon: '🎒' },
                            'base': { label: 'Base', icon: '🏰' },
                            'prompt_yes_no': { label: 'Yes/No', icon: '❓' }
                        };
                        if (sigMap[this._activeMenuSignature]) {
                            pillLabel = sigMap[this._activeMenuSignature].label;
                            pillIcon = sigMap[this._activeMenuSignature].icon;
                        }
                    } else if (this.activeProfileId === 'pimpwars' && this._activeMenuSignature) {
                        const sigMap = {
                            'street': { label: 'Streets', icon: '🏙️' },
                            'menu_move': { label: 'Moving', icon: '🚶' },
                            'menu_sheinen': { label: 'Sheinen', icon: '🍗' },
                            'menu_pete': { label: 'Stiff Pete', icon: '👔' },
                            'menu_casino': { label: 'Casino', icon: '🎰' },
                            'menu_steal': { label: 'Crime', icon: '👊' },
                            'prompt_yes_no': { label: 'Yes/No', icon: '❓' }
                        };
                        if (sigMap[this._activeMenuSignature]) {
                            pillLabel = sigMap[this._activeMenuSignature].label;
                            pillIcon = sigMap[this._activeMenuSignature].icon;
                        }
                    }

                    this.subModePill.innerHTML = `<span>${pillIcon}</span> <span>${pillLabel}</span> <span class="badge-mode">${this.autoSubMode ? 'Auto' : 'Lock'}</span>`;
                } else {
                    this.subModePill.style.display = 'none';
                }
            }

            // Resolve rows from submode if available
            let rowsToRender = profile.rows;
            if (profile.subModes) {
                const currentSub = profile.subModes[this.activeSubMode] ||
                                   profile.subModes[profile.defaultSubMode] ||
                                   Object.values(profile.subModes)[0];
                if (currentSub && currentSub.rows) {
                    rowsToRender = currentSub.rows;
                }
            }

            // Dynamically build or adjust rows based on live terminal buffer if profile supports it
            if (typeof profile.getDynamicRows === 'function') {
                const dynRows = profile.getDynamicRows(this.getScreenText(), this.activeSubMode, this._activeMenuSignature);
                if (dynRows && Array.isArray(dynRows) && dynRows.length > 0) {
                    rowsToRender = dynRows;
                }
            }

            // Build each row defined in the profile
            rowsToRender.forEach((rowDefs, rowIdx) => {
                const rowEl = document.createElement('div');
                let rowCls = 'binkterm-toolbar-row';

                // Check for giant single-action bar (e.g. Action Combat Spacebar)
                const isGiantRow = rowDefs.length === 1 && rowDefs[0].cls && rowDefs[0].cls.includes('btn-space-combat-giant');

                if (isGiantRow) {
                    rowCls += ' binkterm-row-giant-action';
                } else if (rowIdx === 0) {
                    rowCls += ' binkterm-row-system';
                } else if (rowIdx === rowsToRender.length - 1) {
                    const hasHotkeys = rowDefs.some(d => d.cls && d.cls.includes('btn-hotkey'));
                    rowCls += hasHotkeys ? ' binkterm-toolbar-hotkeys binkterm-row-hotkeys' : ' binkterm-row-numbers';
                } else if (rowIdx === 1) {
                    rowCls += ' binkterm-row-gameplay';
                } else if (rowIdx === 2 && rowsToRender.length > 3) {
                    rowCls += ' binkterm-row-secondary';
                } else {
                    rowCls += ' binkterm-row-gameplay';
                }
                rowEl.className = rowCls;

                rowDefs.forEach(def => {
                    if (def.type === 'zoom') {
                        // Zoom in / out button group
                        const zoomGroup = document.createElement('div');
                        zoomGroup.className = 'binkterm-zoom-group';
                        const zoomMinus = document.createElement('button');
                        zoomMinus.type = 'button';
                        zoomMinus.textContent = '−';
                        zoomMinus.title = 'Zoom Out';
                        zoomMinus.addEventListener('pointerdown', (e) => { e.preventDefault(); this.zoomOut(); });
                        const zoomLabel = document.createElement('span');
                        zoomLabel.className = 'binkterm-zoom-label';
                        zoomLabel.textContent = Math.round(this.userZoom * 100) + '%';
                        const zoomPlus = document.createElement('button');
                        zoomPlus.type = 'button';
                        zoomPlus.textContent = '+';
                        zoomPlus.title = 'Zoom In';
                        zoomPlus.addEventListener('pointerdown', (e) => { e.preventDefault(); this.zoomIn(); });
                        zoomGroup.appendChild(zoomMinus);
                        zoomGroup.appendChild(zoomLabel);
                        zoomGroup.appendChild(zoomPlus);
                        rowEl.appendChild(zoomGroup);
                        return;
                    }

                    if (def.type === 'sep') {
                        const sep = document.createElement('span');
                        sep.className = 'binkterm-row-sep';
                        rowEl.appendChild(sep);
                        return;
                    }

                    // Button creation
                    let onClick;
                    if (def.action === 'contrast') {
                        onClick = () => this.toggleContrast();
                    } else if (def.action && def.action.startsWith('submode:')) {
                        const targetSub = def.action.split(':')[1];
                        onClick = () => this.setSubMode(targetSub, true);
                    } else if (def.cls && def.cls.includes('btn-keyboard')) {
                        onClick = () => this.toggleVirtualKeyboard();
                    } else if (def.code) {
                        onClick = () => this.handleVirtualKey(def.code);
                    } else if (def.send !== undefined) {
                        onClick = () => this.sendKey(def.send);
                    } else {
                        onClick = () => this.sendKey(def.label);
                    }

                    const btn = this.createButton(
                        def.label,
                        def.cls,
                        onClick,
                        def.title || def.label,
                        Boolean(def.allowFocus)
                    );
                    rowEl.appendChild(btn);
                });

                this.toolbarElement.appendChild(rowEl);
            });

            this.updateContrastButtons();
        }

        /**
         * Helper to create a touch-optimized button
         */
        createButton(label, extraClass, onClick, title, allowFocus = false) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'binkterm-key-btn ' + (extraClass || '');
            btn.textContent = label;
            if (title) btn.title = title;

            const handler = (e) => {
                if (!allowFocus) {
                    e.preventDefault();
                }
                e.stopPropagation();
                btn.classList.add('is-active');
                setTimeout(() => btn.classList.remove('is-active'), 120);
                onClick();
            };

            btn.addEventListener('pointerdown', handler);
            return btn;
        }

        /**
         * Open Layout Selection Modal
         */
        openLayoutModal() {
            if (this.settingsModal) {
                this.settingsModal.remove();
            }

            const modal = document.createElement('div');
            modal.className = 'binkterm-mobile-modal';
            modal.innerHTML = `
                <h6><span>🎮 Game Controller Layout</span> <button type="button" class="modal-close-btn">&times;</button></h6>
                <div class="binkterm-layout-options-list"></div>
            `;

            const list = modal.querySelector('.binkterm-layout-options-list');
            for (const profileKey in BinktermMobileTerminalAddon.LAYOUT_PROFILES) {
                const profile = BinktermMobileTerminalAddon.LAYOUT_PROFILES[profileKey];
                const isSelected = profile.id === this.activeProfileId;
                const opt = document.createElement('div');
                opt.className = 'binkterm-profile-option' + (isSelected ? ' is-selected' : '');
                opt.innerHTML = `
                    <div class="binkterm-profile-title">
                        <span>${profile.name}</span>
                        ${isSelected ? '<i class="fas fa-check"></i>' : ''}
                    </div>
                    <div class="binkterm-profile-desc">${profile.desc}</div>
                `;
                opt.addEventListener('click', () => {
                    this.setProfile(profile.id, true);
                    modal.remove();
                    this.settingsModal = null;
                });
                list.appendChild(opt);
            }

            modal.querySelector('.modal-close-btn').addEventListener('click', () => {
                modal.remove();
                this.settingsModal = null;
            });

            this.settingsModal = modal;
            this.viewportElement.parentElement.appendChild(modal);
        }

        /**
         * Zoom controls
         */
        zoomIn() {
            this.userZoom = Math.min(2.5, +(this.userZoom + 0.15).toFixed(2));
            this.saveConfig({ userZoom: this.userZoom });
            this.updateZoomLabels();
            this.rescale();
        }

        zoomOut() {
            this.userZoom = Math.max(0.5, +(this.userZoom - 0.15).toFixed(2));
            this.saveConfig({ userZoom: this.userZoom });
            this.updateZoomLabels();
            this.rescale();
        }

        updateZoomLabels() {
            const labels = document.querySelectorAll('.binkterm-zoom-label');
            labels.forEach(l => l.textContent = Math.round(this.userZoom * 100) + '%');
        }

        /**
         * Cycle Contrast Modes:
         * Standard -> Vivid (high contrast boosted) -> Amber -> Standard
         */
        toggleContrast() {
            if (this.contrastMode === 'standard') {
                this.setContrastMode('vivid');
            } else if (this.contrastMode === 'vivid') {
                this.setContrastMode('amber');
            } else {
                this.setContrastMode('standard');
            }
        }

        /**
         * Set contrast mode explicitly
         */
        setContrastMode(mode) {
            if (!BinktermMobileTerminalAddon.THEMES[mode]) {
                mode = 'standard';
            }
            this.contrastMode = mode;
            this.saveConfig({ contrastMode: mode, contrastVersion: 2 });
            this.applyContrast();
            this.updateContrastButtons();
            console.log('[MobileTerminalAddon] Contrast mode set to:', mode);
        }

        /**
         * Apply theme & contrast settings to xterm.js instance
         */
        applyContrast() {
            if (!this.term) return;

            const theme = Object.assign({}, BinktermMobileTerminalAddon.THEMES[this.contrastMode] || BinktermMobileTerminalAddon.THEMES.vivid);
            this.term.options.theme = theme;

            if (this.contrastMode === 'vivid') {
                this.term.options.minimumContrastRatio = 7.0; // WCAG AAA contrast ratio
                this.term.options.fontWeight = 'bold';
                this.term.options.fontWeightBold = '900';
            } else if (this.contrastMode === 'amber') {
                this.term.options.minimumContrastRatio = 6.0;
                this.term.options.fontWeight = 'bold';
            } else {
                this.term.options.minimumContrastRatio = 1.0;
                this.term.options.fontWeight = 'normal';
            }

            // Force xterm to rerender full screen buffer with new colors
            if (this.term._core && this.term._core._renderService) {
                this.term._core._renderService.clear();
            }
            this.term.refresh(0, Math.max(0, this.term.rows - 1));
        }

        /**
         * Update visual labels/styles on any contrast buttons in the UI
         */
        updateContrastButtons() {
            const labelMap = {
                'standard': 'Contrast: Standard',
                'vivid': 'Contrast: Vivid',
                'amber': 'Contrast: Amber'
            };

            const headerBtn = document.getElementById('contrastBtn');
            if (headerBtn) {
                headerBtn.className = 'btn-contrast mode-' + this.contrastMode;
                headerBtn.innerHTML = `<i class="fas fa-adjust"></i> <span>${labelMap[this.contrastMode]}</span>`;
            }

            const toolbarBtn = this.toolbarElement ? this.toolbarElement.querySelector('.btn-contrast-key') : null;
            if (toolbarBtn) {
                toolbarBtn.classList.toggle('btn-active-toggle', this.contrastMode !== 'standard');
            }
        }

        /**
         * Handle messages from parent frame (e.g. dosdoor_play.twig)
         */
        handleWindowMessage(event) {
            if (!event || !event.data) return;
            if (event.data.type === 'toggle-contrast') {
                this.toggleContrast();
            } else if (event.data.type === 'set-contrast' && event.data.mode) {
                this.setContrastMode(event.data.mode);
            } else if (event.data.type === 'set-profile' && event.data.profile) {
                this.setProfile(event.data.profile);
            }
        }

        /**
         * Send a single string or character directly to the backend
         */
        sendKey(str) {
            if (typeof this.options.onKey === 'function') {
                this.options.onKey(str);
            }
            if (this.autoSubMode) {
                setTimeout(() => this.sniffTerminalState(), 180);
                setTimeout(() => this.sniffTerminalState(), 500);
            }
        }

        /**
         * Handle virtual keyboard keys (Enter, Esc, Backspace, Arrows)
         */
        handleVirtualKey(keyName) {
            if (this.options.isNative) {
                // ANSI escape sequences for native doors
                const ansiMap = {
                    'ArrowUp': '\x1b[A',
                    'ArrowDown': '\x1b[B',
                    'ArrowRight': '\x1b[C',
                    'ArrowLeft': '\x1b[D',
                    'Enter': '\r',
                    'Escape': '\x1b',
                    'Backspace': '\x08',
                    'Tab': '\t'
                };
                if (ansiMap[keyName]) {
                    this.sendKey(ansiMap[keyName]);
                }
                return;
            }

            // DOS Doorway Protocol scan codes (\x00 + IBM PC scan code)
            const doorwayMap = {
                'ArrowUp': 0x48,
                'ArrowDown': 0x50,
                'ArrowLeft': 0x4B,
                'ArrowRight': 0x4D,
                'Home': 0x47,
                'End': 0x4F,
                'PageUp': 0x49,
                'PageDown': 0x51
            };

            if (doorwayMap[keyName]) {
                this.sendKey('\x00' + String.fromCharCode(doorwayMap[keyName]));
            } else if (keyName === 'Enter') {
                this.sendKey('\r');
            } else if (keyName === 'Escape') {
                this.sendKey('\x1b');
            } else if (keyName === 'Backspace') {
                this.sendKey('\x08');
            }
        }

        /**
         * Focus terminal input textarea (summons soft keyboard)
         */
        focus() {
            if (this.term) {
                this.term.focus();
                if (this.term.textarea) {
                    this.term.textarea.focus();
                }
            }
        }

        /**
         * Blur terminal input textarea (hides soft keyboard)
         */
        blur() {
            if (this.term && this.term.textarea) {
                this.term.textarea.blur();
            }
        }

        /**
         * Toggle virtual soft keyboard
         */
        toggleVirtualKeyboard() {
            if (this.term && this.term.textarea) {
                if (document.activeElement === this.term.textarea) {
                    this.term.textarea.blur();
                } else {
                    this.term.focus();
                    this.term.textarea.focus();
                }
            }
        }

        /**
         * Toggle collapse/expand on virtual key toolbar
         */
        toggleCollapseToolbar() {
            this.toolbarCollapsed = !this.toolbarCollapsed;
            this.toolbarElement.classList.toggle('is-collapsed', this.toolbarCollapsed);
            const tabIcon = this.toolbarElement.querySelector('.tab-icon');
            const tabLabel = this.toolbarElement.querySelector('.tab-label');
            if (tabIcon) {
                tabIcon.textContent = this.toolbarCollapsed ? '▲' : '▼';
            }
            if (tabLabel) {
                tabLabel.textContent = this.toolbarCollapsed ? 'Keys' : 'Hide';
            }
            this.saveConfig({ toolbarCollapsed: this.toolbarCollapsed });
            setTimeout(this._boundRescale, 150);
        }

        /**
         * Cycle scale modes (Fit Screen -> Fit Width -> 1:1)
         */
        toggleScaleMode() {
            if (this.scaleMode === 'fit-screen') {
                this.setScaleMode('fit-width');
            } else if (this.scaleMode === 'fit-width') {
                this.setScaleMode('native');
            } else {
                this.setScaleMode('fit-screen');
            }
        }

        /**
         * Set scale mode explicitly
         */
        setScaleMode(mode) {
            this.scaleMode = mode;
            this.viewportElement.className = 'binkterm-mobile-viewport mode-' + mode;
            this.saveConfig({ scaleMode: mode });
            this.rescale();
        }

        /**
         * Handle visual viewport changes (triggered when soft keyboard shows/hides)
         */
        handleVisualViewport() {
            if (window.scrollY !== 0) {
                window.scrollTo(0, 0);
            }
            this.rescale();
        }

        /**
         * Handle mouse wheel events on the terminal viewport.
         * Prevents xterm.js from synthesizing unwanted arrow keys or mouse tracking reports
         * (which type '.' periods into DOS doors) while enabling smooth container scrolling or Ctrl-zoom.
         */
        handleWheel(e) {
            e.preventDefault();
            e.stopImmediatePropagation();

            // Ctrl + Wheel: Zoom in / out
            if (e.ctrlKey) {
                if (e.deltaY < 0) {
                    this.zoomIn();
                } else if (e.deltaY > 0) {
                    this.zoomOut();
                }
                return;
            }

            // If zoomed in or in fit-width mode causing viewport overflow, scroll the viewport container directly
            if (this.viewportElement && this.viewportElement.scrollHeight > this.viewportElement.clientHeight) {
                this.viewportElement.scrollTop += e.deltaY;
            }
        }

        /**
         * Core auto-scaling calculation:
         * Computes the scale factor to fit 80 columns and 25 rows within the available viewport.
         */
        rescale() {
            if (!this.term || !this.terminalElement || !this.scalerElement || !this.viewportElement) {
                return;
            }

            const core = this.term._core;
            if (!core || !core._renderService || !core._renderService.dimensions) {
                return;
            }

            const dims = core._renderService.dimensions.css;
            if (!dims || !dims.cell || !dims.cell.width || !dims.cell.height) {
                return;
            }

            const unscaledWidth = Math.ceil(dims.cell.width * this.options.cols);
            const unscaledHeight = Math.ceil(dims.cell.height * this.options.rows);

            // Fixed unscaled dimensions for the terminal element
            this.terminalElement.style.width = unscaledWidth + 'px';
            this.terminalElement.style.height = unscaledHeight + 'px';

            const availWidth = this.viewportElement.clientWidth || window.innerWidth;
            let availHeight = this.viewportElement.clientHeight || (window.innerHeight - 30);

            // When the soft keyboard is up, visualViewport shrinks significantly
            if (window.visualViewport && window.visualViewport.height < window.innerHeight * 0.85) {
                const vvHeight = window.visualViewport.height;
                const rect = this.viewportElement.getBoundingClientRect();
                const toolbarHeight = (this.toolbarElement && !this.toolbarCollapsed)
                    ? this.toolbarElement.offsetHeight
                    : 22;

                const visibleHeight = Math.max(120, (vvHeight - rect.top) - toolbarHeight - 4);
                if (visibleHeight < availHeight) {
                    availHeight = visibleHeight;
                }
            }

            let scale = 1.0;

            if (this.scaleMode === 'fit-screen') {
                // Fit width while respecting height bounds
                const scaleW = availWidth / unscaledWidth;
                const scaleH = availHeight / unscaledHeight;
                scale = Math.min(scaleW, scaleH) * this.userZoom;
            } else if (this.scaleMode === 'fit-width') {
                // Scale to match viewport width 100%
                scale = (availWidth / unscaledWidth) * this.userZoom;
            } else {
                // Native 1:1 scale
                scale = 1.0 * this.userZoom;
            }

            // Bound scale factor between 0.25 and 3.0
            scale = Math.max(0.25, Math.min(scale, 3.0));
            this._lastComputedScale = scale;

            // Scaler wrapper dimensions match the visual scaled bounds
            const scaledWidth = Math.round(unscaledWidth * scale);
            const scaledHeight = Math.round(unscaledHeight * scale);

            this.scalerElement.style.width = scaledWidth + 'px';
            this.scalerElement.style.height = scaledHeight + 'px';

            // Apply CSS transform to the terminal
            this.terminalElement.style.transform = `scale(${scale})`;
            this.terminalElement.style.transformOrigin = 'top left';

            // Center horizontally in viewport
            this.scalerElement.style.marginLeft = 'auto';
            this.scalerElement.style.marginRight = 'auto';
        }

        /**
         * Configuration persistence helpers
         */
        loadConfig(key) {
            try {
                const data = JSON.parse(localStorage.getItem(this.options.storageKey) || '{}');
                return key ? data[key] : data;
            } catch (_) {
                return {};
            }
        }

        saveConfig(updates) {
            try {
                const current = this.loadConfig();
                const next = Object.assign({}, current, updates);
                localStorage.setItem(this.options.storageKey, JSON.stringify(next));
            } catch (_) {}
        }
    }

    if (typeof window !== 'undefined') {
        window.BinktermMobileTerminalAddon = BinktermMobileTerminalAddon;
        window.MobileTerminalAddon = BinktermMobileTerminalAddon;
    }
    if (typeof self !== 'undefined') {
        self.BinktermMobileTerminalAddon = BinktermMobileTerminalAddon;
        self.MobileTerminalAddon = BinktermMobileTerminalAddon;
    }
})();
