<?php

/*
 * Copyright Matthew Asham and BinktermPHP Contributors
 *
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the
 * following conditions are met:
 *
 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
 * Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE
 *
 */

namespace BinktermPHP\Nntp;

use BinktermPHP\Config;

/**
 * Constructs and parses the NNTP `Message-ID` header used by the BinktermPHP NNTP
 * server (and, later, the companion NNTP client transport).
 *
 * See docs/proposals/NNTPServer.md — "Message-ID format". Both halves of the FTN
 * `MSGID` kludge (origin address + serial) are folded in so the constructed ID is
 * as unique as the FTN identity it represents:
 *
 *     <{SERIAL}.{ORIGIN_ADDR}.{AREANAME_LC}@{bbs.hostname}>
 *
 * where ORIGIN_ADDR is the FTN address rendered token-safe as `z{zone}n{net}f{node}p{point}`
 * (point 0 included literally). Messages with no `MSGID` kludge get a synthetic ID
 * whose local part is `{content-hash}.{ORIGIN_ADDR}.{AREANAME_LC}` — the hash is over
 * the raw stored wire content (`kludge_lines` + `message_text`), never any translated
 * form, so the ID stays stable if article translation logic changes.
 *
 * This class holds no state; every method is static.
 */
final class NntpMessageId
{
    /** Length of the hex content hash used for synthetic IDs. */
    private const SYNTHETIC_HASH_LEN = 20;

    /** @var string|null cached hostname component */
    private static ?string $host = null;

    private function __construct()
    {
    }

    /**
     * Hostname component of every constructed Message-ID, derived from SITE_URL.
     */
    public static function hostname(): string
    {
        if (self::$host !== null) {
            return self::$host;
        }

        $host = 'localhost';
        try {
            $parsed = parse_url(Config::getSiteUrl());
            if (!empty($parsed['host'])) {
                $host = strtolower($parsed['host']);
            }
        } catch (\Throwable $e) {
            // fall back to localhost
        }

        return self::$host = $host;
    }

    /**
     * Reset the cached hostname (test hook).
     */
    public static function resetHostCache(): void
    {
        self::$host = null;
    }

    /**
     * Build the Message-ID for a message that carries a real FTN `MSGID` kludge.
     *
     * @param string      $rawMsgid  The stored `echomail.message_id` value, i.e. the
     *                                text after `^AMSGID:` — `"<address>[@domain] <serial>"`.
     * @param string      $groupName The translated newsgroup name for the article's area.
     * @param string|null $host      Override the hostname component (defaults to hostname()).
     */
    public static function build(string $rawMsgid, string $groupName, ?string $host = null): string
    {
        $host = $host ?? self::hostname();
        $parts = self::parseRawMsgid($rawMsgid);

        if ($parts === null) {
            // Unparseable MSGID: treat the whole thing as an opaque serial.
            $serial = self::sanitizeToken($rawMsgid);
            if ($serial === '') {
                $serial = 'x' . substr(sha1($rawMsgid), 0, 12);
            }
            $addrToken = 'x0';
        } else {
            $serial = self::sanitizeToken($parts['serial']);
            $addrToken = self::encodeAddressToken($parts['address']);
        }

        return sprintf('<%s.%s.%s@%s>', $serial, $addrToken, self::groupToken($groupName), $host);
    }

    /**
     * Build a synthetic Message-ID for a message with no `MSGID` kludge.
     *
     * @param string      $kludgeLines   Raw stored `echomail.kludge_lines`.
     * @param string      $messageText   Raw stored `echomail.message_text`.
     * @param string      $originAddress FTN address of the originating system (from
     *                                   the packet origin / PATH when no kludge exists).
     * @param string      $groupName     The translated newsgroup name.
     * @param string|null $host          Override the hostname component.
     */
    public static function buildSynthetic(
        string $kludgeLines,
        string $messageText,
        string $originAddress,
        string $groupName,
        ?string $host = null
    ): string {
        $host = $host ?? self::hostname();
        $hash = substr(hash('sha256', $kludgeLines . "\n" . $messageText), 0, self::SYNTHETIC_HASH_LEN);

        return sprintf(
            '<%s.%s.%s@%s>',
            $hash,
            self::encodeAddressToken($originAddress),
            self::groupToken($groupName),
            $host
        );
    }

    /**
     * Parse a Message-ID this class constructed back into its parts.
     *
     * @return array{serial:string,address_token:string,group:string,host:string}|null
     */
    public static function parse(string $messageId): ?array
    {
        $messageId = trim($messageId);
        if (strlen($messageId) < 5 || $messageId[0] !== '<' || substr($messageId, -1) !== '>') {
            return null;
        }
        $inner = substr($messageId, 1, -1);

        $at = strrpos($inner, '@');
        if ($at === false) {
            return null;
        }
        $local = substr($inner, 0, $at);
        $host = substr($inner, $at + 1);

        // Local part is {serial}.{address_token}.{group}; only the group may contain dots.
        $segs = explode('.', $local, 3);
        if (count($segs) !== 3) {
            return null;
        }

        return [
            'serial' => $segs[0],
            'address_token' => $segs[1],
            'group' => $segs[2],
            'host' => $host,
        ];
    }

    /**
     * Split a raw FTN `MSGID` value into its address and serial halves.
     *
     * Handles: `"1:123/456 SERIAL"`, `"1:123/456.7 SERIAL"`,
     * `"1:123/456@domain SERIAL"`, `"opaque@1:123/456 SERIAL"`.
     *
     * @return array{address:string,serial:string}|null
     */
    public static function parseRawMsgid(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // The serial is the final whitespace-delimited token.
        if (!preg_match('/^(.*\S)\s+(\S+)$/', $raw, $m)) {
            return null;
        }
        $addrPart = trim($m[1]);
        $serial = $m[2];

        // "address@domain" or "opaque@address"
        if (preg_match('#^(\d+:\d+/\d+(?:\.\d+)?)(?:@\S+)?$#', $addrPart, $am)) {
            $address = $am[1];
        } elseif (preg_match('#(\d+:\d+/\d+(?:\.\d+)?)\s*$#', $addrPart, $am)) {
            $address = $am[1];
        } else {
            return null;
        }

        return ['address' => $address, 'serial' => $serial];
    }

    /**
     * Encode an FTN address as a token-safe Message-ID component:
     * `1:123/456.7` -> `z1n123f456p7`  (point 0 included literally).
     * An unparseable address becomes `x` + a short hash.
     */
    public static function encodeAddressToken(string $address): string
    {
        $address = trim($address);
        if (preg_match('#^(\d+):(\d+)/(\d+)(?:\.(\d+))?$#', $address, $m)) {
            return sprintf('z%dn%df%dp%d', (int)$m[1], (int)$m[2], (int)$m[3], (int)($m[4] ?? 0));
        }

        return 'x' . substr(sha1($address), 0, 12);
    }

    /**
     * Decode an `z{z}n{n}f{f}p{p}` token back to an FTN address, or null.
     */
    public static function decodeAddressToken(string $token): ?string
    {
        if (preg_match('/^z(\d+)n(\d+)f(\d+)p(\d+)$/', trim($token), $m)) {
            $addr = sprintf('%d:%d/%d', (int)$m[1], (int)$m[2], (int)$m[3]);
            if ((int)$m[4] !== 0) {
                $addr .= '.' . (int)$m[4];
            }
            return $addr;
        }

        return null;
    }

    /**
     * Lowercased, token-safe form of a newsgroup name for the Message-ID local part.
     */
    private static function groupToken(string $groupName): string
    {
        $g = strtolower(trim($groupName));
        $g = preg_replace('/[^a-z0-9._-]+/', '', $g);

        return $g === '' ? 'group' : $g;
    }

    /**
     * Strip characters that are illegal in a Message-ID local-part token.
     */
    private static function sanitizeToken(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_-]+/', '', trim($value)) ?? '';
    }
}
