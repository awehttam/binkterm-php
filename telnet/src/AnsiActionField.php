<?php

namespace BinktermPHP\TelnetServer;

/**
 * A form field rendered as a push-button that triggers a callback on ENTER.
 *
 * Action fields carry no persistent value; they are excluded from save payloads.
 * The callback receives the same arguments as {@see AnsiFormField::handleEnter()}
 * and follows the same return convention (null = stay, 'quit' = exit settings).
 *
 * Example:
 * ```php
 * new AnsiActionField('change_pw', 'Change Password', function($conn, &$state, $session, $server) {
 *     // ... perform I/O ...
 *     return null;
 * });
 * ```
 */
class AnsiActionField extends AnsiFormField
{
    /** @var callable(mixed, array, string, BbsSession): ?string */
    private $callback;

    /**
     * @param string   $key      Unique identifier (not included in save payloads).
     * @param string   $label    Button label shown on screen.
     * @param callable $callback Invoked on ENTER; receives ($conn, &$state, $session, $server).
     */
    public function __construct(string $key, string $label, callable $callback)
    {
        parent::__construct($key, $label);
        $this->callback = $callback;
    }

    // ── Type hints ───────────────────────────────────────────────────────────

    public function isEnterActivated(): bool { return true; }
    public function isActionOnly(): bool     { return true; }

    // ── Value management (no-ops) ─────────────────────────────────────────────

    public function getValue(): mixed       { return null; }
    public function setValue(mixed $v): void {}

    // ── Input handling ───────────────────────────────────────────────────────

    public function handleEnter($conn, array &$state, string $session, BbsSession $server): ?string
    {
        if (!$this->enabled) { return null; }
        return ($this->callback)($conn, $state, $session, $server);
    }

    // ── Rendering ────────────────────────────────────────────────────────────

    /**
     * Action fields render as a bracketed button spanning the value column.
     * The label is used directly; the calling {@see AnsiForm} skips the
     * label/value split layout and renders this as a full-width line.
     */
    public function renderValue(bool $active, bool $ansiColor, bool $isUtf8): string
    {
        // Rendered by AnsiForm::renderFieldLine() using the action-button path.
        return '';
    }
}
