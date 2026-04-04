<?php

namespace BinktermPHP\TelnetServer;

/**
 * Abstract base class for a single interactive field inside an {@see AnsiForm}.
 *
 * Concrete subclasses implement the specific rendering and input behaviour for
 * different field types (select, toggle, text input, action button).
 */
abstract class AnsiFormField
{
    protected string $label;
    protected string $key;
    protected bool   $enabled = true;

    public function __construct(string $key, string $label)
    {
        $this->key   = $key;
        $this->label = $label;
    }

    // ── Accessors ────────────────────────────────────────────────────────────

    public function getKey(): string   { return $this->key; }
    public function getLabel(): string { return $this->label; }
    public function isEnabled(): bool  { return $this->enabled; }

    /** Disable or re-enable the field (disabled fields are shown dim and cannot be changed). */
    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;
        return $this;
    }

    // ── Value management ─────────────────────────────────────────────────────

    /** Return the current value (used when building the save payload). */
    abstract public function getValue(): mixed;

    /** Populate the field from a loaded settings value. */
    abstract public function setValue(mixed $value): void;

    // ── Rendering ────────────────────────────────────────────────────────────

    /**
     * Return the value portion of this field as a ready-to-print string.
     *
     * @param bool $active   True when this field has focus.
     * @param bool $ansiColor True when ANSI colour escape codes are allowed.
     * @param bool $isUtf8   True when the terminal supports UTF-8 (enables fancy chars like ◄ ►).
     */
    abstract public function renderValue(bool $active, bool $ansiColor, bool $isUtf8): string;

    // ── Input handling ───────────────────────────────────────────────────────

    /**
     * Handle a LEFT arrow key press.
     * Select/toggle fields cycle to the previous option.
     * @return bool True if the value changed.
     */
    public function handleLeft(): bool { return false; }

    /**
     * Handle a RIGHT arrow key or SPACE bar.
     * Select/toggle fields cycle to the next option.
     * @return bool True if the value changed.
     */
    public function handleRight(): bool { return false; }

    /**
     * Handle ENTER on this field.
     * Text and action fields perform I/O here (e.g. open editor, run action).
     *
     * @return string|null  null = stay in form; 'quit' = exit the settings screen entirely.
     */
    public function handleEnter($conn, array &$state, string $session, BbsSession $server): ?string
    {
        return null;
    }

    // ── Field type hints ─────────────────────────────────────────────────────

    /**
     * True if ENTER activates this field (text and action fields).
     * False means LEFT/RIGHT change the value directly (select and toggle).
     */
    public function isEnterActivated(): bool { return false; }

    /**
     * True for action-only buttons that carry no persistent value.
     * Their getValue() returns null and they are excluded from save payloads.
     */
    public function isActionOnly(): bool { return false; }
}
