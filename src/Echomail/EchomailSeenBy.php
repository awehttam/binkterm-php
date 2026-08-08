<?php

namespace BinktermPHP\Echomail;

/**
 * FTS-4009 SEEN-BY / PATH parsing and manipulation for the hub fanout engine.
 * Reads/writes the same bottom_kludges convention already used by
 * BinktermPHP\BinkdProcessor for inbound storage and outbound synthesis.
 *
 * SEEN-BY lines carry no zone (FTS-4009); the whole set is scoped to a
 * single implicit zone, matching how BinkdProcessor already writes them.
 * Point addresses never appear in SEEN-BY or PATH - only isPointAddress()
 * matters to callers branching on that; the remaining helpers here are
 * node-hop bookkeeping only.
 */
class EchomailSeenBy
{
    /**
     * Parse an FTN address into normalized parts.
     *
     * @return array{zone:int,net:int,node:int,point:int,node_point:string}
     */
    public static function parseFtnAddressParts(string $address): array
    {
        $address = trim($address);
        if ($address === '') {
            return ['zone' => 0, 'net' => 0, 'node' => 0, 'point' => 0, 'node_point' => '0'];
        }

        $zoneParts = explode(':', $address, 2);
        $zone = isset($zoneParts[1]) ? (int)trim($zoneParts[0]) : 0;
        $netNode = isset($zoneParts[1]) ? trim($zoneParts[1]) : trim($zoneParts[0]);

        $netNodeParts = explode('/', $netNode, 2);
        $net = (int)trim($netNodeParts[0]);
        $nodePoint = isset($netNodeParts[1]) ? trim($netNodeParts[1]) : '0';

        $nodePointParts = explode('.', $nodePoint, 2);
        $node = (int)trim($nodePointParts[0]);
        $point = isset($nodePointParts[1]) ? (int)trim($nodePointParts[1]) : 0;

        return [
            'zone' => $zone,
            'net' => $net,
            'node' => $node,
            'point' => $point,
            'node_point' => $point > 0 ? $node . '.' . $point : (string)$node,
        ];
    }

    /**
     * True if $address has a point suffix (net/node.point form).
     */
    public static function isPointAddress(string $address): bool
    {
        return self::parseFtnAddressParts($address)['point'] > 0;
    }

    /**
     * Parse SEEN-BY lines out of a bottom_kludges blob into net => [node, ...].
     *
     * @return array<int,int[]> net => list of node numbers
     */
    public static function parseSeenBy(?string $bottomKludges): array
    {
        $seenBy = [];
        if (empty($bottomKludges)) {
            return $seenBy;
        }

        foreach (self::splitLines($bottomKludges) as $line) {
            $line = ltrim($line, "\x01");
            if (stripos($line, 'SEEN-BY:') !== 0) {
                continue;
            }
            $rest = trim(substr($line, strlen('SEEN-BY:')));
            foreach (preg_split('/\s+/', $rest, -1, PREG_SPLIT_NO_EMPTY) as $entry) {
                $parts = explode('/', $entry, 2);
                if (count($parts) !== 2) {
                    continue;
                }
                $net = (int)$parts[0];
                $node = (int)$parts[1];
                if (!isset($seenBy[$net])) {
                    $seenBy[$net] = [];
                }
                if (!in_array($node, $seenBy[$net], true)) {
                    $seenBy[$net][] = $node;
                }
            }
        }

        return $seenBy;
    }

    /**
     * True if $address's net/node (point ignored - points never appear in
     * SEEN-BY) is already present.
     */
    public static function seenByContains(array $seenBy, string $address): bool
    {
        $parts = self::parseFtnAddressParts($address);
        return isset($seenBy[$parts['net']]) && in_array($parts['node'], $seenBy[$parts['net']], true);
    }

    /**
     * Return a copy of $seenBy with $address's net/node added.
     */
    public static function addToSeenBy(array $seenBy, string $address): array
    {
        $parts = self::parseFtnAddressParts($address);
        if (!isset($seenBy[$parts['net']])) {
            $seenBy[$parts['net']] = [];
        }
        if (!in_array($parts['node'], $seenBy[$parts['net']], true)) {
            $seenBy[$parts['net']][] = $parts['node'];
        }
        return $seenBy;
    }

    /**
     * Format a net=>[node,...] map back into SEEN-BY: lines, one line per
     * net, nodes and nets sorted ascending.
     */
    public static function formatSeenBy(array $seenBy): string
    {
        if (empty($seenBy)) {
            return '';
        }
        ksort($seenBy);
        $lines = [];
        foreach ($seenBy as $net => $nodes) {
            sort($nodes);
            $entries = array_map(fn($node) => "{$net}/{$node}", $nodes);
            $lines[] = 'SEEN-BY: ' . implode(' ', $entries);
        }
        return implode("\r", $lines);
    }

    /**
     * Parse PATH lines out of a bottom_kludges blob into an ordered,
     * deduplicated list of "net/node[.point]" hop strings.
     *
     * @return string[]
     */
    public static function parsePath(?string $bottomKludges): array
    {
        $path = [];
        if (empty($bottomKludges)) {
            return $path;
        }

        foreach (self::splitLines($bottomKludges) as $line) {
            $line = ltrim($line, "\x01");
            if (stripos($line, 'PATH:') !== 0) {
                continue;
            }
            $rest = trim(substr($line, strlen('PATH:')));
            foreach (preg_split('/\s+/', $rest, -1, PREG_SPLIT_NO_EMPTY) as $entry) {
                if (!in_array($entry, $path, true)) {
                    $path[] = $entry;
                }
            }
        }

        return $path;
    }

    /**
     * True if $address (net/node[.point]) is already present in the PATH.
     */
    public static function pathContains(array $path, string $address): bool
    {
        return in_array(self::normalizePathEntry($address), $path, true);
    }

    /**
     * Return a copy of $path with $address appended, unless already present.
     */
    public static function addToPath(array $path, string $address): array
    {
        $entry = self::normalizePathEntry($address);
        if (!in_array($entry, $path, true)) {
            $path[] = $entry;
        }
        return $path;
    }

    /**
     * Loop guard: true if $address already occurs more than once in the raw
     * (pre-dedup) parsed PATH - i.e. the message has genuinely looped back
     * through us before, not merely that we're about to add ourselves once.
     */
    public static function isLoop(array $rawPath, string $address): bool
    {
        $entry = self::normalizePathEntry($address);
        $count = 0;
        foreach ($rawPath as $hop) {
            if ($hop === $entry) {
                $count++;
            }
        }
        return $count > 1;
    }

    public static function formatPath(array $path): string
    {
        if (empty($path)) {
            return '';
        }
        return "\x01PATH: " . implode(' ', $path);
    }

    private static function normalizePathEntry(string $address): string
    {
        $parts = self::parseFtnAddressParts($address);
        return $parts['net'] . '/' . $parts['node_point'];
    }

    private static function splitLines(string $blob): array
    {
        return preg_split('/\r\n|\r|\n/', $blob, -1, PREG_SPLIT_NO_EMPTY);
    }
}
