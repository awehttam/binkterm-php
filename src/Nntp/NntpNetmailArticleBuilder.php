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

use BinktermPHP\Binkp\Config\BinkpConfig;
use PDO;

/**
 * Translates a stored `netmail` row into an RFC 3977 article for the virtual
 * netmail newsgroup (docs/proposals/NNTPNetmail.md — "Article translation").
 *
 * Shares the header-encoding / date / body / From-synthesis primitives with
 * {@see NntpArticleBuilder} (they are public), but the netmail shape is
 * different: a real routable `To:` header, both FTN addresses surfaced for
 * round-tripping, and none of the echomail area semantics (`X-FTN-AREA`,
 * `SEEN-BY`, `PATH`, `Origin`).
 */
final class NntpNetmailArticleBuilder
{
    private const REFERENCES_MAX_DEPTH = 20;

    private PDO $db;
    private string $group;
    private string $host;
    private bool $convertQuotesToRfc;
    private NntpArticleBuilder $primitives;
    private ?BinkpConfig $binkp;

    public function __construct(
        PDO $db,
        string $group,
        ?string $host = null,
        bool $convertQuotesToRfc = false,
        ?BinkpConfig $binkp = null
    ) {
        $this->db = $db;
        $this->group = $group;
        $this->host = $host ?? NntpMessageId::hostname();
        $this->convertQuotesToRfc = $convertQuotesToRfc;
        $this->primitives = new NntpArticleBuilder($db, $this->host, $convertQuotesToRfc);
        try {
            $this->binkp = $binkp ?? BinkpConfig::getInstance();
        } catch (\Throwable $e) {
            $this->binkp = null;
        }
    }

    /**
     * @param array<string,mixed> $nm        Row from `netmail` (all columns).
     * @param int                 $number    Article number in the netmail group.
     * @param bool                $outgoing  True when this row is mail the viewer sent
     *                                       (adds `X-BinktermPHP-Folder: sent`).
     * @param array<int,string>|null $parentIds  Prefetched parent netmail id => Message-ID
     *                                            for the batch OVER/HDR path.
     *
     * @return array{headers:string[],body:string,message_id:string}
     */
    public function build(array $nm, int $number, bool $outgoing = false, ?array $parentIds = null): array
    {
        $messageId = $this->messageIdFor($nm);
        $fromAddr = trim((string)($nm['from_address'] ?? ''));
        $toAddr = trim((string)($nm['to_address'] ?? ''));

        $headers = [];
        $headers[] = 'Path: ' . $this->host . '!not-for-mail';
        $headers[] = 'From: ' . $this->primitives->fromHeader(
            (string)($nm['from_name'] ?? ''),
            $fromAddr,
            $this->domainFor($fromAddr)
        );
        $headers[] = 'To: ' . $this->primitives->fromHeader(
            (string)($nm['to_name'] ?? ''),
            $toAddr,
            $this->domainFor($toAddr)
        );
        $headers[] = 'Newsgroups: ' . $this->group;

        $subject = trim((string)($nm['subject'] ?? ''));
        $headers[] = 'Subject: ' . $this->primitives->encodeHeader($subject === '' ? '(none)' : $subject);

        $headers[] = 'Date: ' . $this->primitives->rfcDate($nm['date_written'] ?? null, $nm['date_received'] ?? null);
        $headers[] = 'Message-ID: ' . $messageId;

        $references = $this->referencesChain($nm, $parentIds);
        if ($references !== '') {
            $headers[] = 'References: ' . $references;
        }

        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8; format=flowed';
        $headers[] = 'Content-Transfer-Encoding: 8bit';

        if (!empty($nm['date_received'])) {
            $headers[] = 'X-Date-Received: ' . $this->primitives->rfcDate($nm['date_received'], null);
        }

        // ── netmail-specific passthrough ────────────────────────────────────
        if ($fromAddr !== '') {
            $headers[] = 'X-FTN-From-Address: ' . $fromAddr;
        }
        if ($toAddr !== '') {
            $headers[] = 'X-FTN-To-Address: ' . $toAddr;
        }

        $rawMsgid = trim((string)($nm['message_id'] ?? ''));
        if ($rawMsgid !== '') {
            $headers[] = 'X-FTN-MSGID: ' . $rawMsgid;
        }

        $kludges = (string)($nm['kludge_lines'] ?? '');
        foreach (['REPLY' => 'X-FTN-REPLY', 'PID' => 'X-FTN-PID', 'CHRS' => 'X-FTN-CHRS', 'TZUTC' => 'X-FTN-TZUTC'] as $kludge => $header) {
            $value = $this->primitives->kludgeValue($kludges, $kludge);
            if ($value !== null) {
                $headers[] = $header . ': ' . $value;
            }
        }

        if ($outgoing) {
            $headers[] = 'X-BinktermPHP-Folder: sent';
        }

        if ($number > 0) {
            $headers[] = 'Xref: ' . $this->host . ' ' . $this->group . ':' . $number;
        }

        $body = $this->primitives->normalizeBody((string)($nm['message_text'] ?? ''));
        if ($this->convertQuotesToRfc) {
            $body = NntpQuoteStyle::toRfc($body);
        }

        return ['headers' => $this->unfold($headers), 'body' => $body, 'message_id' => $messageId];
    }

    /**
     * Tab-delimited OVER/XOVER record (LIST OVERVIEW.FMT order).
     *
     * @param array<string,mixed>    $nm
     * @param array<int,string>|null $parentIds
     */
    public function overviewLine(array $nm, int $number, bool $outgoing = false, ?array $parentIds = null): string
    {
        $built = $this->build($nm, $number, $outgoing, $parentIds);
        [$bytes, $lines] = $this->primitives->wireMetrics($built['headers'], $built['body']);

        $subject = trim((string)($nm['subject'] ?? ''));
        $fields = [
            (string)$number,
            $this->overClean($this->primitives->encodeHeader($subject === '' ? '(none)' : $subject)),
            $this->overClean($this->primitives->fromHeader(
                (string)($nm['from_name'] ?? ''),
                (string)($nm['from_address'] ?? ''),
                $this->domainFor((string)($nm['from_address'] ?? ''))
            )),
            $this->primitives->rfcDate($nm['date_written'] ?? null, $nm['date_received'] ?? null),
            $built['message_id'],
            $this->headerValue($built['headers'], 'References'),
            (string)$bytes,
            (string)$lines,
        ];

        return implode("\t", $fields);
    }

    public function messageIdFor(array $nm): string
    {
        $rawMsgid = trim((string)($nm['message_id'] ?? ''));
        if ($rawMsgid !== '') {
            return NntpMessageId::build($rawMsgid, $this->group, $this->host);
        }

        return NntpMessageId::buildSynthetic(
            (string)($nm['kludge_lines'] ?? ''),
            (string)($nm['message_text'] ?? ''),
            (string)($nm['from_address'] ?? ''),
            $this->group,
            $this->host
        );
    }

    // ── internals ──────────────────────────────────────────────────────────

    private function domainFor(string $address): string
    {
        if ($address === '' || $this->binkp === null) {
            return '';
        }
        try {
            $domain = $this->binkp->getDomainByAddress($address);
        } catch (\Throwable $e) {
            return '';
        }

        return is_string($domain) ? $domain : '';
    }

    /**
     * `References:` — immediate parent from a prefetched map, else the full
     * `reply_to_id` ancestor chain walked against `netmail`.
     *
     * @param array<int,string>|null $parentIds
     */
    private function referencesChain(array $nm, ?array $parentIds = null): string
    {
        $parentId = $nm['reply_to_id'] ?? null;
        if ($parentId === null || $parentId === '') {
            return '';
        }

        if ($parentIds !== null) {
            return $parentIds[(int)$parentId] ?? '';
        }

        $stmt = $this->db->prepare(
            'SELECT id, reply_to_id, message_id, kludge_lines, message_text, from_address
             FROM netmail WHERE id = ?'
        );

        $chain = [];
        $seen = [(int)($nm['id'] ?? 0) => true];
        $current = (int)$parentId;

        for ($depth = 0; $depth < self::REFERENCES_MAX_DEPTH && $current > 0 && !isset($seen[$current]); $depth++) {
            $seen[$current] = true;
            $stmt->execute([$current]);
            $parent = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$parent) {
                break;
            }
            $chain[] = $this->messageIdFor($parent);
            $current = (int)($parent['reply_to_id'] ?? 0);
        }

        return implode(' ', array_reverse($chain));
    }

    /**
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

    private function overClean(string $value): string
    {
        return trim(preg_replace('/[\t\r\n]+/', ' ', $value) ?? '');
    }
}
