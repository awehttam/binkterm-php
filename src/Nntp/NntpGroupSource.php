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

/**
 * Abstraction over "where does a newsgroup's articles come from".
 *
 * {@see NntpSession} is a pure RFC 3977 command state machine; it never touches
 * `echomail` / `netmail` directly. Each selectable group is represented by an
 * `NntpGroupSource` that owns its own article numbering, article translation,
 * Message-ID resolution and NEWNEWS listing. This keeps the echomail assumptions
 * ({@see EchomailGroupSource}: shared area, `X-FTN-AREA`, `SEEN-BY`, `PATH`) out of
 * the netmail source ({@see NetmailGroupSource}: per-user, real `To:` addressing).
 *
 * One instance is created per selected group per connection and is cheap to build.
 */
interface NntpGroupSource
{
    /** Full newsgroup name, e.g. `FidoNet.GENERAL` or `netmail`. */
    public function groupName(): string;

    /** LIST NEWSGROUPS description text (falls back to the group name if blank). */
    public function description(): string;

    /** LIST ACTIVE posting flag: true emits `y`, false emits `n`. */
    public function isPostable(): bool;

    /** Group creation time as a UNIX timestamp (LIST ACTIVE.TIMES / NEWGROUPS), or null. */
    public function createdAtUnix(): ?int;

    /**
     * Lazily assign NNTP article numbers to any not-yet-numbered messages in this
     * group. Idempotent and safe to call on every request.
     */
    public function ensureNumbered(): void;

    /**
     * GROUP/LISTGROUP bounds.
     *
     * @return array{low:int,high:int,count:int}
     */
    public function bounds(): array;

    /**
     * (article number => opaque message row id) pairs within [$lo, $hi], ascending
     * by article number. $hi null means "to the end"; $limit caps the row count.
     *
     * @return array<int,int>
     */
    public function range(int $lo, ?int $hi = null, ?int $limit = null): array;

    /**
     * Full article for one number.
     *
     * @return array{headers:string[],body:string,message_id:string}|null
     */
    public function article(int $number): ?array;

    /** Tab-delimited OVER/XOVER record for one number, or null. */
    public function overview(int $number): ?string;

    /** Constructed RFC Message-ID for one number (STAT / LAST / NEXT / NEWNEWS), or null. */
    public function messageIdForNumber(int $number): ?string;

    /**
     * Batch OVER for a set of numbers (one parent-Message-ID prefetch for the batch).
     *
     * @param array<int,int> $numberToId  article number => row id
     * @return array<int,string>          article number => OVER record
     */
    public function overviewBatch(array $numberToId): array;

    /**
     * Batch article build for HDR.
     *
     * @param array<int,int> $numberToId  article number => row id
     * @return array<int,array{headers:string[],body:string,message_id:string}>
     */
    public function articleBatch(array $numberToId): array;

    /**
     * Resolve an RFC Message-ID (`<...>`) to an article number in this group, or null.
     */
    public function resolveMessageId(string $rfcMessageId): ?int;

    /**
     * RFC Message-IDs of articles received at or after $sinceUnix (NEWNEWS).
     *
     * @return list<string>
     */
    public function newMessageIdsSince(int $sinceUnix): array;
}
