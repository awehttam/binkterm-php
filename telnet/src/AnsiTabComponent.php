<?php

namespace BinktermPHP\TelnetServer;

/**
 * A full-screen tabbed settings UI component for ANSI/telnet terminals.
 *
 * Renders a tab bar across the top, delegates form content to the active
 * {@see AnsiForm}, and owns the input loop that handles navigation, tab
 * switching, and save/discard.
 *
 * Key bindings:
 *  ↑ / ↓       — move between fields
 *  ← / →       — change current field's value (select / toggle)
 *  SPACE        — same as → for select / toggle
 *  ENTER        — activate current field (open editor or run action)
 *  TAB          — switch to next tab
 *  SHIFT+TAB    — switch to previous tab
 *  [ / ]        — switch to previous / next tab
 *  S            — save all changes
 *  Q            — discard all changes and exit
 *
 * Return values from {@see show()}:
 *  'save'       — user pressed S; caller should persist all field values
 *  'discard'    — user pressed Q or connection lost gracefully
 *  'disconnect' — connection dropped mid-session
 */
class AnsiTabComponent
{
    /** @var array<array{label: string, form: AnsiForm}> */
    private array     $tabs        = [];
    private int       $activeTab   = 0;
    private string    $title;
    private BbsSession $server;

    public function __construct(string $title, BbsSession $server)
    {
        $this->title  = $title;
        $this->server = $server;
    }

    // ── Builder API ──────────────────────────────────────────────────────────

    /**
     * Append a tab.
     *
     * @param string   $label Human-readable tab name.
     * @param AnsiForm $form  The form providing this tab's fields.
     */
    public function addTab(string $label, AnsiForm $form): static
    {
        $this->tabs[] = ['label' => $label, 'form' => $form];
        return $this;
    }

    // ── Main loop ────────────────────────────────────────────────────────────

    /**
     * Run the tabbed settings UI until the user requests save, discards, or disconnects.
     *
     * @return string  'save' | 'discard' | 'disconnect'
     */
    public function show($conn, array &$state, string $session): string
    {
        if (empty($this->tabs)) { return 'discard'; }

        while (true) {
            $this->renderScreen($conn, $state);

            $key = $this->server->readKeyWithIdleCheck($conn, $state);
            if ($key === null) { return 'disconnect'; }

            $form = $this->tabs[$this->activeTab]['form'];

            switch ($key) {
                case 'UP':
                    $form->prevField();
                    break;

                case 'DOWN':
                    $form->nextField();
                    break;

                case 'LEFT':
                    $form->handleLeft();
                    break;

                case 'RIGHT':
                case 'CHAR: ':   // SPACE
                    $form->handleRight();
                    break;

                case 'ENTER':
                    $signal = $form->activateCurrent($conn, $state, $session, $this->server);
                    if ($signal === 'quit') { return 'discard'; }
                    break;

                case 'SHIFT_TAB':
                    $this->prevTab();
                    break;

                case 'TAB':
                case 'CHAR:]':
                    $this->nextTab();
                    break;

                case 'CHAR:[':
                    $this->prevTab();
                    break;

                case 'CHAR:s':
                case 'CHAR:S':
                    return 'save';

                case 'CHAR:q':
                case 'CHAR:Q':
                    return 'discard';

                default:
                    // Ignore unrecognised keys
                    break;
            }
        }
    }

    // ── Tab navigation ───────────────────────────────────────────────────────

    private function nextTab(): void
    {
        $this->activeTab = ($this->activeTab + 1) % count($this->tabs);
    }

    private function prevTab(): void
    {
        $this->activeTab = ($this->activeTab - 1 + count($this->tabs)) % count($this->tabs);
    }

    // ── Screen rendering ─────────────────────────────────────────────────────

    /**
     * Full redraw: title bar → tab bar → separator → form content → hint bar.
     */
    private function renderScreen($conn, array $state): void
    {
        $this->server->safeWrite($conn, "\033[2J\033[H");

        $cols      = $state['cols']                ?? 80;
        $ansiColor = ($state['terminal_ansi_color'] ?? 'yes') !== 'no';
        $isUtf8    = ($state['terminal_charset']    ?? 'utf8') === 'utf8';
        $locale    = $state['locale']               ?? 'en';

        // ── Title bar ────────────────────────────────────────────────────────
        $titleText = $this->server->t(
            'ui.terminalserver.settings.tab_title',
            $this->title,
            [],
            $locale
        );
        $this->server->writeLine($conn,
            $ansiColor
                ? TelnetUtils::colorize(' ' . $titleText, TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD)
                : ' ' . $titleText
        );

        // ── Double separator ─────────────────────────────────────────────────
        $this->server->writeLine($conn,
            $ansiColor
                ? TelnetUtils::colorize($this->hline($cols, $isUtf8, true), TelnetUtils::ANSI_CYAN)
                : $this->hline($cols, $isUtf8, true)
        );

        // ── Tab bar ──────────────────────────────────────────────────────────
        $this->server->writeLine($conn, $this->buildTabBar($state));

        // ── Thin separator ───────────────────────────────────────────────────
        $this->server->writeLine($conn,
            $ansiColor
                ? TelnetUtils::colorize($this->hline($cols, $isUtf8, false), TelnetUtils::ANSI_DIM)
                : $this->hline($cols, $isUtf8, false)
        );

        // ── Form content ─────────────────────────────────────────────────────
        $form  = $this->tabs[$this->activeTab]['form'];
        $lines = $form->getLines($state, $cols);
        foreach ($lines as $line) {
            $this->server->writeLine($conn, $line);
        }

        // ── Blank padding so the hint bar stays near the bottom ──────────────
        $contentRows  = count($lines) + 4; // title + 2 separators + tab bar
        $termRows     = $state['rows'] ?? 24;
        $hintReserved = 3; // blank + separator + hint
        $paddingNeeded = max(0, $termRows - $contentRows - $hintReserved);
        for ($i = 0; $i < $paddingNeeded; $i++) {
            $this->server->writeLine($conn, '');
        }

        // ── Bottom separator ─────────────────────────────────────────────────
        $this->server->writeLine($conn,
            $ansiColor
                ? TelnetUtils::colorize($this->hline($cols, $isUtf8, false), TelnetUtils::ANSI_DIM)
                : $this->hline($cols, $isUtf8, false)
        );

        // ── Hint bar ─────────────────────────────────────────────────────────
        $hint = $this->buildHint($state);
        $this->server->safeWrite($conn, $hint);
    }

    /**
     * Build the tab bar line with the active tab highlighted.
     */
    private function buildTabBar(array $state): string
    {
        $ansiColor = ($state['terminal_ansi_color'] ?? 'yes') !== 'no';
        $locale    = $state['locale']               ?? 'en';

        $parts = [];
        foreach ($this->tabs as $idx => $tab) {
            // Labels are already translated by the caller (SettingsHandler).
            $label = $tab['label'];

            if ($idx === $this->activeTab) {
                $text = "[ {$label} ]";
                $parts[] = $ansiColor
                    ? TelnetUtils::colorize($text, TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD)
                    : $text;
            } else {
                $parts[] = $ansiColor
                    ? TelnetUtils::colorize("  {$label}", TelnetUtils::ANSI_DIM)
                    : "  {$label}";
            }
        }

        return ' ' . implode('  ', $parts);
    }

    /**
     * Build the single-line hint bar at the bottom of the screen.
     */
    private function buildHint(array $state): string
    {
        $ansiColor = ($state['terminal_ansi_color'] ?? 'yes') !== 'no';
        $isUtf8    = ($state['terminal_charset']    ?? 'utf8') === 'utf8';
        $locale    = $state['locale']               ?? 'en';

        if ($isUtf8) {
            $hintKey  = 'ui.terminalserver.settings.hint_navigate';
            $fallback = '  ↑↓ Move   ◄► Change   Tab/[ ] Tabs   S) Save   Q) Quit';
        } else {
            $hintKey  = 'ui.terminalserver.settings.hint_navigate_ascii';
            $fallback = '  Up/Dn Move   Left/Rt Change   Tab/[/] Tabs   S) Save   Q) Quit';
        }

        $hint = $this->server->t($hintKey, $fallback, [], $locale);

        return $ansiColor
            ? TelnetUtils::colorize($hint, TelnetUtils::ANSI_YELLOW)
            : $hint;
    }

    /**
     * Build a horizontal rule of the given width.
     *
     * @param bool $bold Use double-line characters (═) instead of single (─).
     */
    private function hline(int $cols, bool $isUtf8, bool $bold): string
    {
        if ($isUtf8) {
            return str_repeat($bold ? '═' : '─', $cols);
        }
        return str_repeat($bold ? '=' : '-', $cols);
    }
}
