<?php

namespace BinktermPHP\TelnetServer;

/**
 * Bounded, per-session, non-persistent recall of previously submitted line
 * input, stored in the session `$state` array under `line_history`.
 *
 * Scope rules (enforced by callers, reinforced here):
 *   - opt-in only: a line reader gets history only when it passes a non-empty
 *     `history_key`;
 *   - never for a sensitive prompt (passwords, TOTP, registration secrets) —
 *     those readers pass no key;
 *   - bounded ({@see MAX_ENTRIES}), newest last, de-duplicated against the most
 *     recent entry, blanks ignored;
 *   - lives and dies with the session `$state` — nothing is written to disk or
 *     shared between sessions.
 */
final class TerminalLineHistory
{
    public const MAX_ENTRIES = 30;

    /**
     * Record a submitted value for a history key.
     */
    public static function push(array &$state, string $key, string $value): void
    {
        $value = trim($value);
        if ($key === '' || $value === '') {
            return;
        }

        $all = $state['line_history'] ?? [];
        $bucket = $all[$key] ?? [];

        if ($bucket !== [] && end($bucket) === $value) {
            return;
        }

        $bucket[] = $value;
        if (count($bucket) > self::MAX_ENTRIES) {
            $bucket = array_slice($bucket, -self::MAX_ENTRIES);
        }

        $all[$key] = array_values($bucket);
        $state['line_history'] = $all;
    }

    /**
     * @return string[] oldest first
     */
    public static function entries(array $state, string $key): array
    {
        if ($key === '') {
            return [];
        }

        return array_values($state['line_history'][$key] ?? []);
    }

    /**
     * A cursor for walking history with Up/Down. `index` is 1-based from the
     * newest entry; 0 means "the line the user was editing" (not stored here).
     *
     * @return array{0:int,1:?string} [newIndex, valueOrNull]
     */
    public static function step(array $state, string $key, int $index, int $direction): array
    {
        $entries = self::entries($state, $key);
        $count   = count($entries);
        if ($count === 0) {
            return [0, null];
        }

        $index = max(0, min($count, $index + $direction));
        if ($index === 0) {
            return [0, null];
        }

        return [$index, $entries[$count - $index]];
    }
}
