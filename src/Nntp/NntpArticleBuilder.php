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

use PDO;

/**
 * Translates a stored `echomail` row into an RFC 3977 article (header block + body)
 * and the overview line used by OVER/XOVER.
 *
 * Bodies are served verbatim from `echomail.message_text` (always UTF-8 in the DB).
 * The raw FTN kludges are surfaced as `X-FTN-*` headers for round-trip fidelity;
 * `Content-Type` always declares UTF-8. See docs/proposals/NNTPServer.md.
 *
 * The companion NNTP client transport is expected to reuse this class (and
 * {@see NntpMessageId}) rather than re-implement header translation.
 */
class NntpArticleBuilder
{
    private const REFERENCES_MAX_DEPTH = 20;

    private PDO $db;
    private string $host;
    private bool $convertQuotesToRfc;

    public function __construct(PDO $db, ?string $host = null, bool $convertQuotesToRfc = false)
    {
        $this->db = $db;
        $this->host = $host ?? NntpMessageId::hostname();
        $this->convertQuotesToRfc = $convertQuotesToRfc;
    }

    /**
     * Build the article for an echomail row.
     *
     * @param array<string,mixed> $em     Row from `echomail` (all columns).
     * @param array<string,mixed> $area   Row from `echoareas` (tag, domain, ...).
     * @param string              $group  Translated newsgroup name for the area.
     * @param int                 $number Article number in that group.
     * @param array<int,string>|null $parentIds  Optional prefetched map of
     *   parent echomail id => constructed Message-ID. When given, `References:`
     *   is the single immediate parent (no per-ancestor DB walk) — used for the
     *   OVER/HDR range paths. When null, the full ancestor chain is walked.
     *
     * @return array{headers:string[],body:string,message_id:string}
     *   headers: "Name: value" lines (no CRLF); body: \n-delimited, not dot-stuffed.
     */
    public function build(array $em, array $area, string $group, int $number, ?array $parentIds = null): array
    {
        $messageId = $this->messageIdFor($em, $group);
        $headers = [];

        $headers[] = 'Path: ' . $this->host . '!not-for-mail';
        $headers[] = 'From: ' . $this->fromHeader((string)($em['from_name'] ?? ''), (string)($em['from_address'] ?? ''), (string)($area['domain'] ?? ''));
        $headers[] = 'Newsgroups: ' . $group;

        $subject = trim((string)($em['subject'] ?? ''));
        $headers[] = 'Subject: ' . $this->encodeHeader($subject === '' ? '(none)' : $subject);

        $headers[] = 'Date: ' . $this->rfcDate($em['date_written'] ?? null, $em['date_received'] ?? null);
        $headers[] = 'Message-ID: ' . $messageId;

        $references = $this->referencesChain($em, $group, $parentIds);
        if ($references !== '') {
            $headers[] = 'References: ' . $references;
        }

        $toName = trim((string)($em['to_name'] ?? ''));
        if ($toName !== '') {
            $headers[] = 'X-Comment-To: ' . $this->encodeHeader($toName);
        }

        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8; format=flowed';
        $headers[] = 'Content-Transfer-Encoding: 8bit';

        if (!empty($em['date_received'])) {
            $headers[] = 'X-Date-Received: ' . $this->rfcDate($em['date_received'], null);
        }

        // ── X-FTN-* passthrough headers ──────────────────────────────────────
        $headers[] = 'X-FTN-AREA: ' . strtoupper((string)($area['tag'] ?? ''));

        $rawMsgid = trim((string)($em['message_id'] ?? ''));
        if ($rawMsgid !== '') {
            $headers[] = 'X-FTN-MSGID: ' . $rawMsgid;
        }

        $kludges = (string)($em['kludge_lines'] ?? '');
        foreach ([
            'REPLY' => 'X-FTN-REPLY',
            'PID' => 'X-FTN-PID',
            'TID' => 'X-FTN-TID',
            'CHRS' => 'X-FTN-CHRS',
            'TZUTC' => 'X-FTN-TZUTC',
        ] as $kludge => $header) {
            $value = $this->kludgeValue($kludges, $kludge);
            if ($value !== null) {
                $headers[] = $header . ': ' . $value;
            }
        }

        $bottom = (string)($em['bottom_kludges'] ?? '');
        foreach ($this->multiLineKludge($bottom, 'SEEN-BY') as $line) {
            $headers[] = 'X-FTN-SEEN-BY: ' . $line;
        }
        foreach ($this->multiLineKludge($bottom, 'PATH') as $line) {
            $headers[] = 'X-FTN-PATH: ' . $line;
        }

        $origin = trim((string)($em['origin_line'] ?? ''));
        if ($origin !== '') {
            $origin = preg_replace('/^\*?\s*Origin:\s*/i', '', $origin) ?? $origin;
            $headers[] = 'X-FTN-Origin: ' . $this->encodeHeader(trim($origin));
        }

        if ($number > 0) {
            // Cross-posted copies are independent echomail rows with their own
            // numbers and Message-IDs, so Xref only ever names this one group.
            $headers[] = 'Xref: ' . $this->host . ' ' . $group . ':' . $number;
        }

        $body = $this->normalizeBody((string)($em['message_text'] ?? ''));
        if ($this->convertQuotesToRfc) {
            $body = NntpQuoteStyle::toRfc($body);
        }

        return ['headers' => $this->unfold($headers), 'body' => $body, 'message_id' => $messageId];
    }

    /**
     * Split any header whose value was folded by {@see encodeHeader()} (a long
     * RFC 2047 value becomes `word CRLF SP word`) into separate physical lines, so
     * the wire writer emits a properly folded header block. Continuation lines
     * already carry their leading whitespace.
     *
     * @param string[] $headers
     * @return string[]
     */
    private function unfold(array $headers): array
    {
        $out = [];
        foreach ($headers as $header) {
            if (strpos($header, "\n") === false) {
                $out[] = $header;
                continue;
            }
            foreach (preg_split('/\r\n|\n/', $header) as $line) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * The tab-delimited overview record for OVER/XOVER: number, then the fields in
     * `LIST OVERVIEW.FMT` order (Subject, From, Date, Message-ID, References, :bytes,
     * :lines).
     */
    public function overviewLine(array $em, array $area, string $group, int $number, ?array $parentIds = null): string
    {
        $built = $this->build($em, $area, $group, $number, $parentIds);
        [$bytes, $lines] = $this->wireMetrics($built['headers'], $built['body']);

        $subject = trim((string)($em['subject'] ?? ''));
        $fields = [
            (string)$number,
            $this->overClean($this->encodeHeader($subject === '' ? '(none)' : $subject)),
            $this->overClean($this->fromHeader((string)($em['from_name'] ?? ''), (string)($em['from_address'] ?? ''), (string)($area['domain'] ?? ''))),
            $this->rfcDate($em['date_written'] ?? null, $em['date_received'] ?? null),
            $built['message_id'],
            $this->headerValue($built['headers'], 'References'),
            (string)$bytes,
            (string)$lines,
        ];

        return implode("\t", $fields);
    }

    /**
     * Byte and line counts for the fully-rendered wire article (headers + blank +
     * body, CRLF line endings), used for OVER `:bytes` / `:lines`.
     *
     * @param string[] $headers
     * @return array{0:int,1:int}
     */
    public function wireMetrics(array $headers, string $body): array
    {
        $headerBlock = implode("\r\n", $headers) . "\r\n";
        $bodyLines = $body === '' ? [] : explode("\n", $body);
        $bodyBlock = $body === '' ? '' : implode("\r\n", $bodyLines) . "\r\n";

        return [strlen($headerBlock) + 2 + strlen($bodyBlock), count($bodyLines)];
    }

    // ── Message-ID ──────────────────────────────────────────────────────────

    public function messageIdFor(array $em, string $group): string
    {
        $rawMsgid = trim((string)($em['message_id'] ?? ''));
        if ($rawMsgid !== '') {
            return NntpMessageId::build($rawMsgid, $group, $this->host);
        }

        return NntpMessageId::buildSynthetic(
            (string)($em['kludge_lines'] ?? ''),
            (string)($em['message_text'] ?? ''),
            (string)($em['from_address'] ?? ''),
            $group,
            $this->host
        );
    }

    // ── References ─────────────────────────────────────────────────────────

    /**
     * First matching "Name: value" header value from a built header list, or ''.
     *
     * @param string[] $headerLines
     */
    private function headerValue(array $headerLines, string $name): string
    {
        $prefix = strtolower($name) . ':';
        foreach ($headerLines as $line) {
            if (stripos($line, $prefix) === 0) {
                return trim(substr($line, strlen($prefix)));
            }
        }

        return '';
    }

    /**
     * `References:` value. With a prefetched `$parentIds` map (parent echomail id
     * => Message-ID) only the immediate parent is emitted and no DB query is made;
     * otherwise the full ancestor chain is walked via `reply_to_id`.
     *
     * @param array<int,string>|null $parentIds
     */
    private function referencesChain(array $em, string $group, ?array $parentIds = null): string
    {
        $parentId = $em['reply_to_id'] ?? null;
        if ($parentId === null || $parentId === '') {
            return '';
        }

        if ($parentIds !== null) {
            return $parentIds[(int)$parentId] ?? '';
        }

        $stmt = $this->db->prepare(
            'SELECT id, reply_to_id, message_id, kludge_lines, message_text, from_address
             FROM echomail WHERE id = ?'
        );

        $chain = [];
        $seen = [(int)($em['id'] ?? 0) => true];
        $current = (int)$parentId;

        for ($depth = 0; $depth < self::REFERENCES_MAX_DEPTH && $current > 0 && !isset($seen[$current]); $depth++) {
            $seen[$current] = true;
            $stmt->execute([$current]);
            $parent = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$parent) {
                break;
            }

            $raw = trim((string)($parent['message_id'] ?? ''));
            $chain[] = $raw !== ''
                ? NntpMessageId::build($raw, $group, $this->host)
                : NntpMessageId::buildSynthetic(
                    (string)($parent['kludge_lines'] ?? ''),
                    (string)($parent['message_text'] ?? ''),
                    (string)($parent['from_address'] ?? ''),
                    $group,
                    $this->host
                );

            $current = (int)($parent['reply_to_id'] ?? 0);
        }

        return implode(' ', array_reverse($chain));
    }

    // ── From: synthesis ────────────────────────────────────────────────────

    /**
     * `"Display Name" (z:n/f.p) <handle@fN.nN.zN.domain>` — non-routable but
     * unambiguous and round-trippable (docs/proposals/NNTPServer.md — "From address").
     * `$domain` is the echoarea's FTN domain (e.g. `lovlynet`); falls back to
     * `fidonet` when empty.
     */
    public function fromHeader(string $name, string $address, string $domain = ''): string
    {
        $name = trim($name) === '' ? 'Unknown' : trim($name);
        $address = trim($address);

        $tld = strtolower(preg_replace('/[^A-Za-z0-9-]+/', '', $domain) ?? '');
        if ($tld === '') {
            $tld = 'fidonet';
        }

        $handle = strtolower(preg_replace('/[^A-Za-z0-9._-]+/', '.', $name) ?? '');
        $handle = trim($handle, '.');
        if ($handle === '') {
            $handle = 'user';
        }

        if (preg_match('#^(\d+):(\d+)/(\d+)(?:\.(\d+))?$#', $address, $m)) {
            $zone = (int)$m[1];
            $net = (int)$m[2];
            $node = (int)$m[3];
            $point = (int)($m[4] ?? 0);
            $tuple = sprintf('%d:%d/%d.%d', $zone, $net, $node, $point);
            $domain = ($point > 0 ? "p{$point}." : '') . "f{$node}.n{$net}.z{$zone}.{$tld}";
            return sprintf('%s (%s) <%s@%s>', $this->quoteDisplayName($name), $tuple, $handle, $domain);
        }

        // Address unparseable — still emit a syntactically valid From.
        return sprintf('%s <%s@%s>', $this->quoteDisplayName($name), $handle, 'unknown.' . $tld);
    }

    private function quoteDisplayName(string $name): string
    {
        $encoded = $this->encodeHeader($name);
        if ($encoded !== $name) {
            // Already an encoded-word — must not be quoted.
            return $encoded;
        }
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $name) . '"';
    }

    // ── Header/value helpers ───────────────────────────────────────────────

    /**
     * RFC 2047 encoded-word(s) for a header value containing non-ASCII or control
     * bytes; returned unchanged when it is plain ASCII.
     *
     * Delegates to {@see mb_encode_mimeheader()} so that a value too long for a
     * single 75-character encoded-word (RFC 2047 §2) is split into multiple
     * encoded-words folded with CRLF + space, always on UTF-8 character
     * boundaries. A naive byte-chunked splitter would cut a multi-byte character
     * (e.g. Cyrillic, where each letter is two bytes) across two encoded-words and
     * corrupt it — which is exactly the "borked subject" failure this avoids.
     */
    public function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $value;
        }

        $previous = mb_internal_encoding();
        mb_internal_encoding('UTF-8');
        try {
            return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
        } finally {
            if (is_string($previous)) {
                mb_internal_encoding($previous);
            }
        }
    }

    /**
     * Extract the value of a top-of-message kludge (`\x01NAME: value`) or null.
     */
    public function kludgeValue(string $kludgeLines, string $name): ?string
    {
        if ($kludgeLines === '') {
            return null;
        }
        $pattern = '/^\x01?' . preg_quote($name, '/') . ':[ \t]*(.+?)\s*$/mi';
        if (preg_match($pattern, $kludgeLines, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * All values of a repeated bottom kludge (SEEN-BY / PATH), with or without SOH.
     *
     * @return string[]
     */
    public function multiLineKludge(string $bottomKludges, string $name): array
    {
        if ($bottomKludges === '') {
            return [];
        }
        $out = [];
        $pattern = '/^\x01?' . preg_quote($name, '/') . ':[ \t]*(.+?)\s*$/mi';
        if (preg_match_all($pattern, $bottomKludges, $matches)) {
            foreach ($matches[1] as $value) {
                $value = trim($value);
                if ($value !== '') {
                    $out[] = $value;
                }
            }
        }

        return $out;
    }

    /**
     * RFC 5322 Date from a UTC timestamp string, falling back to $fallback then now.
     */
    public function rfcDate($value, $fallback): string
    {
        $ts = $this->toTimestamp($value) ?? $this->toTimestamp($fallback) ?? time();

        return gmdate('D, d M Y H:i:s', $ts) . ' +0000';
    }

    private function toTimestamp($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        $ts = strtotime((string)$value . ' UTC');
        if ($ts === false) {
            $ts = strtotime((string)$value);
        }

        return $ts === false ? null : $ts;
    }

    /**
     * Body normalization: strip a leading UTF-8 BOM, normalize line endings to \n,
     * drop a trailing newline (the wire writer re-adds CRLF per line). Kludge lines
     * are already stored separately, so the body is clean content.
     */
    public function normalizeBody(string $text): string
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return rtrim($text, "\n");
    }

    /**
     * Collapse tabs/newlines for a single OVER field (fields are tab-delimited and
     * one record per line).
     */
    private function overClean(string $value): string
    {
        return trim(preg_replace('/[\t\r\n]+/', ' ', $value) ?? '');
    }
}
