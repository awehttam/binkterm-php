<?php

namespace BinktermPHP\TelnetServer;

/**
 * A boolean on/off toggle field.
 *
 * LEFT / RIGHT arrows and SPACE all flip the value.
 * Shown as [ ON ] or [ OFF ] with colour when ANSI is enabled.
 */
class AnsiToggleField extends AnsiFormField
{
    private bool   $on       = false;
    private string $labelOn  = 'ON';
    private string $labelOff = 'OFF';

    /**
     * @param string $key      Settings key.
     * @param string $label    Human-readable field label.
     * @param bool   $default  Initial state.
     * @param string $labelOn  Text shown when the toggle is on.
     * @param string $labelOff Text shown when the toggle is off.
     */
    public function __construct(
        string $key,
        string $label,
        bool   $default  = false,
        string $labelOn  = 'ON',
        string $labelOff = 'OFF'
    ) {
        parent::__construct($key, $label);
        $this->on       = $default;
        $this->labelOn  = $labelOn;
        $this->labelOff = $labelOff;
    }

    // ── Value management ─────────────────────────────────────────────────────

    public function getValue(): mixed { return $this->on; }

    public function setValue(mixed $value): void
    {
        if (is_bool($value)) {
            $this->on = $value;
        } elseif (is_string($value)) {
            $this->on = in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        } else {
            $this->on = (bool)$value;
        }
    }

    // ── Input handling ───────────────────────────────────────────────────────

    public function handleLeft(): bool
    {
        if (!$this->enabled) { return false; }
        $this->on = !$this->on;
        return true;
    }

    public function handleRight(): bool { return $this->handleLeft(); }

    // ── Rendering ────────────────────────────────────────────────────────────

    public function renderValue(bool $active, bool $ansiColor, bool $isUtf8): string
    {
        if ($this->on) {
            $padded = str_pad($this->labelOn,  max(strlen($this->labelOn), strlen($this->labelOff)));
            $text   = "[ {$padded} ]";
            if (!$this->enabled) {
                return $ansiColor ? TelnetUtils::colorize($text, TelnetUtils::ANSI_DIM) : $text;
            }
            return $ansiColor
                ? TelnetUtils::colorize($text, TelnetUtils::ANSI_GREEN . ($active ? TelnetUtils::ANSI_BOLD : ''))
                : $text;
        } else {
            $padded = str_pad($this->labelOff, max(strlen($this->labelOn), strlen($this->labelOff)));
            $text   = "[ {$padded} ]";
            if (!$this->enabled) {
                return $ansiColor ? TelnetUtils::colorize($text, TelnetUtils::ANSI_DIM) : $text;
            }
            return $ansiColor
                ? TelnetUtils::colorize($text, TelnetUtils::ANSI_DIM . ($active ? TelnetUtils::ANSI_BOLD : ''))
                : $text;
        }
    }
}
