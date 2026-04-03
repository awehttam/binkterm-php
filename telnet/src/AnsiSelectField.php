<?php

namespace BinktermPHP\TelnetServer;

/**
 * A form field rendered as a cycling option picker.
 *
 * LEFT / RIGHT arrows cycle through the available options in place.
 * The current selection is shown between angle brackets when focused.
 */
class AnsiSelectField extends AnsiFormField
{
    /** @var array<string, string>  option value => display label */
    private array $options = [];
    private int   $currentIndex = 0;

    /**
     * @param string                $key      Settings key (used in the save payload).
     * @param string                $label    Human-readable field label.
     * @param array<string, string> $options  Ordered map of value => display label.
     * @param string                $default  Initial value (defaults to first option if empty).
     */
    public function __construct(string $key, string $label, array $options, string $default = '')
    {
        parent::__construct($key, $label);
        foreach ($options as $optionValue => $optionLabel) {
            $this->options[(string)$optionValue] = $optionLabel;
        }
        $this->setValue($default !== '' ? $default : (string)array_key_first($options));
    }

    // ── Value management ─────────────────────────────────────────────────────

    public function getValue(): mixed
    {
        $keys = array_keys($this->options);
        return (string)($keys[$this->currentIndex] ?? '');
    }

    public function setValue(mixed $value): void
    {
        $keys = array_map('strval', array_keys($this->options));
        $idx  = array_search((string)$value, $keys, true);
        $this->currentIndex = ($idx !== false) ? (int)$idx : 0;
    }

    // ── Input handling ───────────────────────────────────────────────────────

    public function handleLeft(): bool
    {
        if (!$this->enabled || count($this->options) < 2) { return false; }
        $this->currentIndex = ($this->currentIndex - 1 + count($this->options)) % count($this->options);
        return true;
    }

    public function handleRight(): bool
    {
        if (!$this->enabled || count($this->options) < 2) { return false; }
        $this->currentIndex = ($this->currentIndex + 1) % count($this->options);
        return true;
    }

    // ── Rendering ────────────────────────────────────────────────────────────

    public function renderValue(bool $active, bool $ansiColor, bool $isUtf8): string
    {
        $keys    = array_keys($this->options);
        $display = $this->options[$keys[$this->currentIndex] ?? ''] ?? '';

        if (!$this->enabled) {
            $text = "  {$display}";
            return $ansiColor
                ? TelnetUtils::colorize($text, TelnetUtils::ANSI_DIM)
                : $text;
        }

        if ($active) {
            if ($isUtf8) {
                $inner = "◄ {$display} ►";
            } else {
                $inner = "< {$display} >";
            }
            return $ansiColor
                ? TelnetUtils::colorize($inner, TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD)
                : $inner;
        }

        return "[{$display}]";
    }
}
