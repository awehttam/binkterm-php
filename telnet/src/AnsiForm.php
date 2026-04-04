<?php

namespace BinktermPHP\TelnetServer;

/**
 * A collection of {@see AnsiFormField} instances rendered as a navigable form
 * inside one tab of an {@see AnsiTabComponent}.
 *
 * The form owns layout logic (label alignment, cursor rendering) but performs
 * no I/O itself — the tab component drives the input loop and calls
 * {@see handleKey()} and {@see activateCurrent()} as appropriate.
 */
class AnsiForm
{
    /** @var AnsiFormField[] */
    private array   $fields      = [];
    private int     $activeIndex = 0;

    /** Optional section-header line displayed above the fields. */
    private ?string $sectionHeader = null;

    // ── Builder API ──────────────────────────────────────────────────────────

    public function addField(AnsiFormField $field): static
    {
        $this->fields[] = $field;
        return $this;
    }

    public function setSectionHeader(?string $header): static
    {
        $this->sectionHeader = $header;
        return $this;
    }

    // ── Navigation ───────────────────────────────────────────────────────────

    public function prevField(): void
    {
        if (empty($this->fields)) { return; }
        $count = count($this->fields);
        for ($i = 1; $i <= $count; $i++) {
            $idx = ($this->activeIndex - $i + $count) % $count;
            $this->activeIndex = $idx;
            return;
        }
    }

    public function nextField(): void
    {
        if (empty($this->fields)) { return; }
        $count = count($this->fields);
        for ($i = 1; $i <= $count; $i++) {
            $idx = ($this->activeIndex + $i) % $count;
            $this->activeIndex = $idx;
            return;
        }
    }

    public function currentField(): ?AnsiFormField
    {
        return $this->fields[$this->activeIndex] ?? null;
    }

    // ── Key dispatch helpers ─────────────────────────────────────────────────

    /**
     * Forward a LEFT key to the active field.
     * @return bool True if the value changed.
     */
    public function handleLeft(): bool
    {
        return $this->currentField()?->handleLeft() ?? false;
    }

    /**
     * Forward a RIGHT key to the active field.
     * @return bool True if the value changed.
     */
    public function handleRight(): bool
    {
        return $this->currentField()?->handleRight() ?? false;
    }

    /**
     * Activate the current field (ENTER key).
     * Returns 'quit' if the field wants to exit the settings screen.
     */
    public function activateCurrent($conn, array &$state, string $session, BbsSession $server): ?string
    {
        $field = $this->currentField();
        if ($field === null || !$field->isEnterActivated()) { return null; }
        return $field->handleEnter($conn, $state, $session, $server);
    }

    // ── Value access ─────────────────────────────────────────────────────────

    /**
     * Return all non-action field values keyed by their settings key.
     * @return array<string, mixed>
     */
    public function getValues(): array
    {
        $values = [];
        foreach ($this->fields as $field) {
            if (!$field->isActionOnly()) {
                $values[$field->getKey()] = $field->getValue();
            }
        }
        return $values;
    }

    /**
     * Populate fields from a loaded settings array.
     * @param array<string, mixed> $values
     */
    public function setValues(array $values): void
    {
        foreach ($this->fields as $field) {
            if (!$field->isActionOnly() && array_key_exists($field->getKey(), $values)) {
                $field->setValue($values[$field->getKey()]);
            }
        }
    }

    // ── Rendering ────────────────────────────────────────────────────────────

    /**
     * Render all fields to an array of terminal lines (no trailing \r\n).
     *
     * @param array $state Terminal state (used for charset/color flags).
     * @param int   $cols  Terminal width in columns.
     * @return string[]
     */
    public function getLines(array $state, int $cols): array
    {
        $ansiColor = ($state['terminal_ansi_color'] ?? 'yes') !== 'no';
        $isUtf8    = ($state['terminal_charset']    ?? 'utf8') === 'utf8';

        $lines = [];

        if ($this->sectionHeader !== null) {
            $lines[] = '';
            $lines[] = $ansiColor
                ? TelnetUtils::colorize('  ' . $this->sectionHeader, TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD)
                : '  ' . $this->sectionHeader;
        }

        $lines[] = '';

        if (empty($this->fields)) {
            $lines[] = $ansiColor
                ? TelnetUtils::colorize('  (No settings available)', TelnetUtils::ANSI_DIM)
                : '  (No settings available)';
            return $lines;
        }

        $labelWidth = $this->computeLabelWidth();

        foreach ($this->fields as $idx => $field) {
            $active = ($idx === $this->activeIndex);
            $lines[] = $this->renderFieldLine($field, $active, $labelWidth, $cols, $ansiColor, $isUtf8);
        }

        return $lines;
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Compute the width to which all labels should be padded for alignment.
     */
    private function computeLabelWidth(): int
    {
        $max = 0;
        foreach ($this->fields as $field) {
            if (!$field->isActionOnly()) {
                $max = max($max, mb_strlen($field->getLabel()));
            }
        }
        return max($max, 20); // minimum 20 so the form looks tidy on short-label tabs
    }

    /**
     * Render a single field to a terminal line string.
     *
     * Layout for normal fields:
     *   "  [cursor] [label..........] [gap] [value_str]"
     *    2    2      labelWidth         2
     *
     * Layout for action-only fields:
     *   "  [cursor] [ Label                            ]"
     */
    private function renderFieldLine(
        AnsiFormField $field,
        bool          $active,
        int           $labelWidth,
        int           $cols,
        bool          $ansiColor,
        bool          $isUtf8
    ): string {
        // ── Action-button layout ─────────────────────────────────────────────
        if ($field->isActionOnly()) {
            $buttonWidth = max(40, $cols - 10);
            $inner       = str_pad($field->getLabel(), $buttonWidth - 4);

            if ($active) {
                $cursor = $isUtf8 ? '► ' : '> ';
                if ($ansiColor) {
                    $cursor = TelnetUtils::colorize($cursor, TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD);
                    $button = TelnetUtils::colorize("[ {$inner} ]", TelnetUtils::ANSI_YELLOW . TelnetUtils::ANSI_BOLD);
                } else {
                    $button = "[ {$inner} ]";
                }
                return '  ' . $cursor . $button;
            }

            $button = "[ {$inner} ]";
            if ($ansiColor && !$field->isEnabled()) {
                $button = TelnetUtils::colorize($button, TelnetUtils::ANSI_DIM);
            }
            return '  ' . '  ' . $button;
        }

        // ── Normal field layout ──────────────────────────────────────────────
        if ($active) {
            $cursor = $isUtf8 ? '► ' : '> ';
            if ($ansiColor) {
                $cursor = TelnetUtils::colorize($cursor, TelnetUtils::ANSI_CYAN . TelnetUtils::ANSI_BOLD);
                $label  = TelnetUtils::colorize(str_pad($field->getLabel(), $labelWidth), TelnetUtils::ANSI_BOLD);
            } else {
                $label = str_pad($field->getLabel(), $labelWidth);
            }
        } else {
            $cursor = '  ';
            $label  = $ansiColor
                ? TelnetUtils::colorize(str_pad($field->getLabel(), $labelWidth), TelnetUtils::ANSI_DIM)
                : str_pad($field->getLabel(), $labelWidth);
        }

        $valueStr = $field->renderValue($active, $ansiColor, $isUtf8);

        return '  ' . $cursor . $label . '  ' . $valueStr;
    }
}
