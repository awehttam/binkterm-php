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
                desc: 'N/S/E/W D-Pad, Large Combat Space, * Back',
                matches: ['ooii', 'oo2', 'overkill', 'test-ooii', 'bbslink-ooii', 'operation overkill'],
                rows: [
                    // Row 1: Actions & System
                    [
                        { label: 'ESC', code: 'Escape', cls: 'btn-esc' },
                        { label: 'ENTER', code: 'Enter', cls: 'btn-enter' },
                        { label: '* Back', send: '*', cls: 'btn-action btn-back-key', title: 'Go Back (*)' },
                        { label: '⌫', code: 'Backspace', cls: 'btn-action' },
                        { label: '⌨', cls: 'btn-utility btn-keyboard', allowFocus: true, title: 'Toggle Keyboard' },
                        { label: '◐', cls: 'btn-utility btn-contrast-key', action: 'contrast', title: 'Toggle High Contrast' },
                        { type: 'zoom' }
                    ],
                    // Row 2: N/S/E/W D-Pad & Large Action Combat Space Bar
                    [
                        { label: '◄ W', send: 'W', cls: 'btn-nav', title: 'West (W)' },
                        { label: '▲ N', send: 'N', cls: 'btn-nav', title: 'North (N)' },
                        { label: '▼ S', send: 'S', cls: 'btn-nav', title: 'South (S)' },
                        { label: '► E', send: 'E', cls: 'btn-nav', title: 'East (E)' },
                        { label: '⚔ SPACE', send: ' ', cls: 'btn-space-combat', title: 'Combat Action (Space)' },
                        { label: 'A', send: 'A', cls: 'btn-action', title: 'Attack (A)' },
                        { label: 'R', send: 'R', cls: 'btn-action', title: 'Rest (R)' }
                    ],
                    // Row 3: Numbers & OOII Hotkeys
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
                        { label: 'I', send: 'I', cls: 'btn-hotkey', title: 'Inventory (I)' },
                        { label: 'U', send: 'U', cls: 'btn-hotkey', title: 'Use (U)' },
                        { label: 'L', send: 'L', cls: 'btn-hotkey', title: 'Look (L)' },
                        { label: 'G', send: 'G', cls: 'btn-hotkey', title: 'Get (G)' },
                        { label: 'D', send: 'D', cls: 'btn-hotkey', title: 'Drop (D)' },
                        { label: 'S', send: 'S', cls: 'btn-hotkey', title: 'Status (S)' },
                        { label: 'F', send: 'F', cls: 'btn-hotkey', title: 'Flee (F)' },
                        { label: 'Y', send: 'Y', cls: 'btn-action', title: 'Yes (Y)' },
                        { label: 'N', send: 'N', cls: 'btn-action', title: 'No (N)' },
                        { label: 'Q', send: 'Q', cls: 'btn-action', title: 'Quit (Q)' }
                    ]
                ]
            },
            lord: {
                id: 'lord',
                name: 'Legend of the Red Dragon',
                shortName: 'LORD',
                desc: 'Arrows D-Pad, Numbers, Town & Forest hotkeys',
                matches: ['lord', 'lord2', 'bbslink-lord', 'bbslink-lord2', 'red dragon'],
                rows: [
                    // Row 1: Actions, Controls, Zoom & Contrast
                    [
                        { label: 'ESC', code: 'Escape', cls: 'btn-esc' },
                        { label: 'ENTER', code: 'Enter', cls: 'btn-enter' },
                        { label: 'SPACE', send: ' ', cls: 'btn-action' },
                        { label: '⌫', code: 'Backspace', cls: 'btn-action' },
                        { label: '⌨', cls: 'btn-utility btn-keyboard', allowFocus: true, title: 'Toggle Keyboard' },
                        { label: '◐', cls: 'btn-utility btn-contrast-key', action: 'contrast', title: 'Toggle High Contrast' },
                        { type: 'zoom' }
                    ],
                    // Row 2: Standard Arrows D-Pad & Common Game Keys
                    [
                        { label: '◄', code: 'ArrowLeft', cls: 'btn-nav' },
                        { label: '▲', code: 'ArrowUp', cls: 'btn-nav' },
                        { label: '▼', code: 'ArrowDown', cls: 'btn-nav' },
                        { label: '►', code: 'ArrowRight', cls: 'btn-nav' },
                        { label: 'Y', send: 'Y', cls: 'btn-action', title: 'Yes' },
                        { label: 'N', send: 'N', cls: 'btn-action', title: 'No' },
                        { label: 'Q', send: 'Q', cls: 'btn-action', title: 'Quit' }
                    ],
                    // Row 3: Numbers 1-0 and Common RPG Hotkeys
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
                        { label: 'F', send: 'F', cls: 'btn-hotkey', title: 'Forest / Fight' },
                        { label: 'S', send: 'S', cls: 'btn-hotkey', title: 'Slaughter / Stats' },
                        { label: 'K', send: 'K', cls: 'btn-hotkey', title: 'King Arthur' },
                        { label: 'A', send: 'A', cls: 'btn-hotkey', title: 'Abduls Armour' },
                        { label: 'H', send: 'H', cls: 'btn-hotkey', title: 'Healers Hut' },
                        { label: 'R', send: 'R', cls: 'btn-hotkey', title: 'Run' },
                        { label: 'B', send: 'B', cls: 'btn-hotkey', title: 'Bank' },
                        { label: 'I', send: 'I', cls: 'btn-hotkey', title: 'Inn' },
                        { label: 'T', send: 'T', cls: 'btn-hotkey', title: 'Training' },
                        { label: 'C', send: 'C', cls: 'btn-hotkey', title: 'Conjugality' },
                        { label: 'D', send: 'D', cls: 'btn-hotkey', title: 'Daily News' },
                        { label: 'M', send: 'M', cls: 'btn-hotkey', title: 'Mail / Announcement' },
                        { label: 'V', send: 'V', cls: 'btn-hotkey', title: 'View Stats' }
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

            this.toolbarCollapsed = false; // Always show gamepad controller by default!
            this.userZoom = parseFloat(savedConfig.userZoom || '1.0');

            // Resolve active layout profile for this game
            this.activeProfileId = this.detectProfileId(this.doorId, this.doorName);

            this.isMobile = this.detectMobile();
            this._boundRescale = this.rescale.bind(this);
            this._boundVisualViewportHandler = this.handleVisualViewport.bind(this);
            this._boundMessageHandler = this.handleWindowMessage.bind(this);
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

            // Initial calculation
            this.rescale();
            setTimeout(this._boundRescale, 150);
            setTimeout(this._boundRescale, 500);
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

            // Tab bar container holding Collapse button and Layout pill
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

            // Collapse/Expand Tab
            const tab = document.createElement('div');
            tab.className = 'binkterm-toolbar-tab';
            tab.innerHTML = `<span class="tab-icon">▼</span> <span class="tab-label">Hide</span>`;
            tab.addEventListener('click', (e) => {
                e.stopPropagation();
                this.toggleCollapseToolbar();
            });
            tabWrapper.appendChild(tab);

            this.toolbarElement.appendChild(tabWrapper);

            // Render buttons for current active profile
            this.renderToolbar();

            // Append toolbar inside container after viewportElement
            const container = this.viewportElement.parentElement;
            container.appendChild(this.toolbarElement);
        }

        /**
         * Build the gamepad rows according to the active profile
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

            // Build each row defined in the profile
            profile.rows.forEach((rowDefs, rowIdx) => {
                const rowEl = document.createElement('div');
                let rowCls = 'binkterm-toolbar-row';
                if (rowIdx === 0) {
                    rowCls += ' binkterm-row-system';
                } else if (rowIdx === 1) {
                    rowCls += ' binkterm-row-gameplay';
                } else if (rowIdx === 2) {
                    const hasHotkeys = rowDefs.some(d => d.cls && d.cls.includes('btn-hotkey'));
                    rowCls += hasHotkeys ? ' binkterm-toolbar-hotkeys binkterm-row-hotkeys' : ' binkterm-row-numbers';
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
