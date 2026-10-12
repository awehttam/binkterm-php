<?php

declare(strict_types=1);

namespace BinktermPHP;

use RuntimeException;

/** PostgreSQL lexical statement boundaries; preserves SQL and comments verbatim. */
final class PostgresSqlSplitter
{
    /**
     * Split outside literals, identifiers and comments. This is not SQL validation.
     * The fixed string mode must match the supplied SQL.
     * @return list<string>
     */
    public static function split(string $sql, bool $standardConformingStrings = true): array
    {
        return iterator_to_array(self::statements($sql, $standardConformingStrings), false);
    }

    /**
     * Yield statements for execution inside the caller's migration transaction.
     * A callback reads the connection's string mode before each statement, so a
     * preceding SET (or set_config) is respected. Late lexical errors require rollback.
     * @return \Generator<int, string>
     */
    public static function statements(string $sql, bool|callable $standardConformingStrings = true): \Generator
    {
        $start = 0;
        $length = strlen($sql);
        $hasCode = false;
        $continuedEscape = null;
        $newline = false;
        for ($i = 0; $i < $length; $i++) {
            $c = $sql[$i];
            if (ctype_space($c)) {
                $newline = $newline || $c === "\n" || $c === "\r";
                continue;
            }
            if (substr($sql, $i, 2) === '--') {
                while ($i < $length && $sql[$i] !== "\n" && $sql[$i] !== "\r") {
                    $i++;
                }
                $newline = $newline || $i < $length;
                continue;
            }
            if (substr($sql, $i, 2) === '/*') {
                $open = $i;
                $depth = 1;
                $i += 2;
                while ($i < $length && $depth > 0) {
                    $pair = substr($sql, $i, 2);
                    if ($pair === '/*' || $pair === '*/') {
                        $depth += $pair === '/*' ? 1 : -1;
                        $i += 2;
                    } else {
                        $newline = $newline || $sql[$i] === "\n" || $sql[$i] === "\r";
                        $i++;
                    }
                }
                if ($depth !== 0) {
                    self::unclosed($sql, $open, 'block comment');
                }
                $i--;
                continue;
            }
            if ($c === ';') {
                if ($hasCode) {
                    yield trim(substr($sql, $start, $i - $start));
                }
                $start = $i + 1;
                $hasCode = false;
                $continuedEscape = null;
                $newline = false;
                continue;
            }
            if (!$hasCode) {
                $stringMode = is_callable($standardConformingStrings)
                    ? $standardConformingStrings() : $standardConformingStrings;
            }
            $hasCode = true;
            if ($c === "'" || $c === '"') {
                $open = $i;
                $explicitEscape = $i > 0 && ($sql[$i - 1] === 'E' || $sql[$i - 1] === 'e')
                    && ($i < 2 || !self::identifierByte($sql[$i - 2]));
                $escape = $c === "'" && (!$stringMode || $explicitEscape
                    || ($continuedEscape === true && $newline));
                $closed = false;
                while (++$i < $length) {
                    if ($escape && $sql[$i] === '\\') {
                        $i++;
                    } elseif ($sql[$i] === $c) {
                        if (($sql[$i + 1] ?? '') === $c) {
                            $i++;
                        } else {
                            $closed = true;
                            break;
                        }
                    }
                }
                if (!$closed) {
                    self::unclosed($sql, $open, $c === "'" ? 'string' : 'quoted identifier');
                }
                $continuedEscape = $c === "'" ? $escape : null;
                $newline = false;
                continue;
            }
            $continuedEscape = null;
            $newline = false;
            if ($c === '$' && ($i === 0 || !self::identifierByte($sql[$i - 1]))
                && preg_match('/\G\$(?:[A-Za-z_\x80-\xff][A-Za-z_0-9\x80-\xff]*)?\$/', $sql, $match, 0, $i)) {
                $delimiter = $match[0];
                $end = strpos($sql, $delimiter, $i + strlen($delimiter));
                if ($end === false) {
                    self::unclosed($sql, $i, 'dollar quote ' . $delimiter);
                }
                $i = $end + strlen($delimiter) - 1;
            }
        }
        if ($hasCode) {
            yield trim(substr($sql, $start));
        }
    }

    private static function identifierByte(string $byte): bool
    {
        return ctype_alnum($byte) || $byte === '_' || $byte === '$' || ord($byte) >= 128;
    }

    private static function unclosed(string $sql, int $offset, string $construct): never
    {
        $line = substr_count(substr($sql, 0, $offset), "\n") + 1;
        throw new RuntimeException("Unclosed PostgreSQL {$construct} at line {$line}, byte {$offset}");
    }
}
