<?php

namespace BinktermPHP\TelnetServer;

/**
 * A text-input form field.
 *
 * Single-line fields show an inline prompt on ENTER.
 * Multi-line fields open the session's full-screen editor on ENTER.
 * The form is re-rendered cleanly after editing because the tab component
 * performs a full-screen redraw on every loop iteration.
 */
class AnsiTextField extends AnsiFormField
{
    private string $value       = '';
    private int    $maxLines;
    private int    $maxChars;
    private string $placeholder;
    /** @var array<string, string> */
    private array  $editorContext;

    /**
     * @param string $key         Settings key.
     * @param string $label       Human-readable label.
     * @param string $default     Current value loaded from settings.
     * @param int    $maxLines    1 = single-line inline edit; >1 = full-screen editor.
     * @param int    $maxChars    Hard character limit (0 = unlimited).
     * @param string $placeholder Hint shown when the field is empty.
     * @param array<string, string> $editorContext Optional labels for multiline editor UI.
     */
    public function __construct(
        string $key,
        string $label,
        string $default     = '',
        int    $maxLines    = 1,
        int    $maxChars    = 255,
        string $placeholder = 'Press ENTER to edit',
        array  $editorContext = []
    ) {
        parent::__construct($key, $label);
        $this->value       = $default;
        $this->maxLines    = $maxLines;
        $this->maxChars    = $maxChars;
        $this->placeholder = $placeholder;
        $this->editorContext = $editorContext;
    }

    // ── Value management ─────────────────────────────────────────────────────

    public function getValue(): mixed { return $this->value; }

    public function setValue(mixed $value): void { $this->value = (string)$value; }

    // ── Type hints ───────────────────────────────────────────────────────────

    public function isEnterActivated(): bool { return true; }

    // ── Input handling ───────────────────────────────────────────────────────

    /**
     * Open the appropriate editor and update the field value on save.
     */
    public function handleEnter($conn, array &$state, string $session, BbsSession $server): ?string
    {
        if (!$this->enabled) { return null; }

        if ($this->maxLines > 1) {
            // Full-screen vi-style editor
            $result = $server->readMultiline($conn, $state, $state['cols'] ?? 80, $this->value, $this->editorContext);
        } else {
            // Inline single-line prompt — clear screen for a clean edit context
            $server->safeWrite($conn, "\033[2J\033[H");
            $server->writeLine($conn, TelnetUtils::colorize(
                "Edit: {$this->label}",
                TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD
            ));
            $server->writeLine($conn, '');
            if ($this->value !== '') {
                $server->writeLine($conn,
                    TelnetUtils::colorize('  Current: ', TelnetUtils::ANSI_DIM) . $this->value
                );
                $server->writeLine($conn, '');
            }

            $result = $server->prompt(
                $conn,
                $state,
                TelnetUtils::colorize('  New value (blank = keep current): ', TelnetUtils::ANSI_CYAN),
                true
            );

            if ($result === null) { return 'quit'; }
            if (trim($result) === '') { return null; } // keep current
            $result = trim($result);
        }

        if ($result !== null && $result !== '') {
            if ($this->maxChars > 0) {
                $result = mb_substr($result, 0, $this->maxChars);
            }
            $this->value = $result;
        }

        return null;
    }

    // ── Rendering ────────────────────────────────────────────────────────────

    public function renderValue(bool $active, bool $ansiColor, bool $isUtf8): string
    {
        // Collapse newlines to a single preview line
        $preview = str_replace(["\r\n", "\r", "\n"], ' / ', $this->value);
        $preview = mb_substr($preview, 0, 38);

        if (!$this->enabled) {
            $text = $preview !== '' ? $preview : '(empty)';
            return $ansiColor ? TelnetUtils::colorize($text, TelnetUtils::ANSI_DIM) : $text;
        }

        if ($active) {
            $display = $preview !== '' ? $preview : $this->placeholder;
            $text    = "[{$display}]";
            return $ansiColor
                ? TelnetUtils::colorize($text, TelnetUtils::ANSI_YELLOW . TelnetUtils::ANSI_BOLD)
                : $text;
        }

        if ($preview !== '') { return $preview; }
        $hint = "({$this->placeholder})";
        return $ansiColor ? TelnetUtils::colorize($hint, TelnetUtils::ANSI_DIM) : $hint;
    }
}
