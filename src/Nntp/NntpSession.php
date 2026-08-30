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

use BinktermPHP\Binkp\Logger;
use BinktermPHP\Config;
use BinktermPHP\EchoareaSubscriptionManager;
use PDO;

/**
 * Per-connection NNTP command state machine (RFC 3977, read-only subset + STARTTLS).
 *
 * I/O is injected: {@see $readLine} returns one command line without its CRLF (or
 * null on EOF), {@see $writeRaw} sends raw bytes. This keeps the class free of any
 * socket/TLS concern so it runs identically in a forked child or the Windows
 * single-connection fallback, and is unit-testable with string buffers.
 */
class NntpSession
{
    /** @var callable():?string */
    private $readLine;
    /** @var callable(string):void */
    private $writeRaw;
    /** @var callable():bool  performs the TLS upgrade on the underlying socket */
    private $startTls;

    private PDO $db;
    private NntpConfig $config;
    private Logger $logger;
    private string $remoteIp;
    private bool $tlsActive;
    private bool $isPlaintextPort;

    private NntpNewsgroups $groups;
    private NntpArticleNumbers $numbers;
    private NntpArticleBuilder $builder;
    private NntpAuth $auth;

    private bool $authenticated = false;
    private ?int $userId = null;
    private ?string $pendingAuthUser = null;
    /** @var array<int,array<string,mixed>>  subscribed echoarea id => row */
    private array $subscribed = [];

    /** Currently selected group, or null. */
    private ?NntpGroupSource $source = null;
    private ?int $pointer = null;

    /** Memoized per-connection netmail group source (built on first use). */
    private ?NetmailGroupSource $netmailSource = null;

    private bool $quit = false;

    /**
     * @param array{
     *   db:PDO, config:NntpConfig, logger:Logger, remote_ip:string,
     *   tls_active:bool, plaintext_port:bool,
     *   read_line:callable, write_raw:callable, start_tls:callable
     * } $ctx
     */
    public function __construct(array $ctx)
    {
        $this->db = $ctx['db'];
        $this->config = $ctx['config'];
        $this->logger = $ctx['logger'];
        $this->remoteIp = $ctx['remote_ip'];
        $this->tlsActive = (bool)$ctx['tls_active'];
        $this->isPlaintextPort = (bool)$ctx['plaintext_port'];
        $this->readLine = $ctx['read_line'];
        $this->writeRaw = $ctx['write_raw'];
        $this->startTls = $ctx['start_tls'];

        $this->groups = new NntpNewsgroups($this->db, $this->config);
        $this->numbers = new NntpArticleNumbers($this->db);
        $this->builder = new NntpArticleBuilder($this->db, null, $this->config->shouldConvertOutboundQuotes());
        $this->auth = new NntpAuth();
    }

    /**
     * Serve the connection until QUIT or the client hangs up.
     */
    public function run(): void
    {
        if (!$this->config->isEnabled()) {
            // RFC 3977 400: service not available, server closes the connection.
            $this->send('400 NNTP service is disabled on this system');
            $this->auth->close();
            return;
        }

        $this->send(
            $this->config->isPostingAllowed()
                ? '200 ' . $this->serverName() . ' BinktermPHP NNTP service ready (posting ok)'
                : '201 ' . $this->serverName() . ' BinktermPHP NNTP service ready (no posting)'
        );

        while (!$this->quit) {
            $line = ($this->readLine)();
            if ($line === null) {
                break;
            }
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }

            try {
                $this->dispatch($line);
            } catch (\Throwable $e) {
                $this->logger->error('[nntp] command failed: ' . $e->getMessage(), [
                    'ip' => $this->remoteIp,
                    'cmd' => $line,
                ]);
                $this->send('403 Internal error handling that command');
            }
        }

        $this->auth->close();
    }

    // ── Dispatch ───────────────────────────────────────────────────────────

    private function dispatch(string $line): void
    {
        $parts = preg_split('/\s+/', trim($line));
        $command = strtoupper(array_shift($parts) ?? '');
        $args = $parts;

        switch ($command) {
            case 'QUIT':
                $this->send('205 Closing connection');
                $this->quit = true;
                return;
            case 'CAPABILITIES':
                $this->cmdCapabilities();
                return;
            case 'MODE':
                $this->send(
                    strtoupper($args[0] ?? '') === 'READER'
                        ? ($this->postingReady() ? '200 Reader mode, posting permitted' : '201 Reader mode, posting prohibited')
                        : '501 Unknown MODE'
                );
                return;
            case 'DATE':
                $this->send('111 ' . gmdate('YmdHis'));
                return;
            case 'HELP':
                $this->sendMulti('100 Help text follows', ['  Commands: CAPABILITIES AUTHINFO LIST GROUP LISTGROUP ARTICLE HEAD BODY STAT OVER HDR NEWGROUPS NEWNEWS DATE QUIT']);
                return;
            case 'STARTTLS':
                $this->cmdStartTls();
                return;
            case 'AUTHINFO':
                $this->cmdAuthInfo($args);
                return;
            case 'LIST':
                $this->cmdList($args);
                return;
            case 'NEWGROUPS':
                $this->cmdNewGroups($args);
                return;
            case 'NEWNEWS':
                $this->cmdNewNews($args);
                return;
            case 'GROUP':
                $this->cmdGroup($args);
                return;
            case 'LISTGROUP':
                $this->cmdListGroup($args);
                return;
            case 'ARTICLE':
            case 'HEAD':
            case 'BODY':
            case 'STAT':
                $this->cmdArticle($command, $args);
                return;
            case 'OVER':
            case 'XOVER':
                $this->cmdOver($args);
                return;
            case 'HDR':
            case 'XHDR':
                $this->cmdHdr($args);
                return;
            case 'LAST':
            case 'NEXT':
                $this->cmdLastNext($command);
                return;
            case 'POST':
                $this->cmdPost();
                return;
            case 'IHAVE':
                $this->send('500 IHAVE not supported');
                return;
            default:
                $this->send('500 Unknown command');
        }
    }

    // ── CAPABILITIES ───────────────────────────────────────────────────────

    private function cmdCapabilities(): void
    {
        $caps = ['VERSION 2', 'READER', 'HDR', 'OVER', 'LIST ACTIVE ACTIVE.TIMES NEWSGROUPS OVERVIEW.FMT HEADERS'];
        if (!$this->tlsActive && $this->isPlaintextPort) {
            $caps[] = 'STARTTLS';
        }
        if (!$this->authenticated) {
            $caps[] = 'AUTHINFO USER';
        }
        if ($this->postingReady()) {
            $caps[] = 'POST';
        }
        $this->sendMulti('101 Capability list:', $caps);
    }

    // ── STARTTLS ───────────────────────────────────────────────────────────

    private function cmdStartTls(): void
    {
        if ($this->tlsActive) {
            $this->send('502 TLS is already active');
            return;
        }
        if (!$this->isPlaintextPort) {
            $this->send('580 STARTTLS not available on this port');
            return;
        }
        $this->send('382 Continue with TLS negotiation');
        if (!($this->startTls)()) {
            $this->logger->warning('[nntp] STARTTLS handshake failed', ['ip' => $this->remoteIp]);
            $this->quit = true;
            return;
        }
        $this->tlsActive = true;
        // RFC 4642: discard any state established before the TLS handshake.
        $this->authenticated = false;
        $this->userId = null;
        $this->pendingAuthUser = null;
        $this->subscribed = [];
        $this->source = null;
        $this->pointer = null;
        $this->netmailSource = null;
    }

    // ── AUTHINFO ───────────────────────────────────────────────────────────

    private function cmdAuthInfo(array $args): void
    {
        $sub = strtoupper($args[0] ?? '');

        if ($sub === 'USER') {
            if ($this->authenticated) {
                $this->send('502 Already authenticated');
                return;
            }
            $this->pendingAuthUser = $args[1] ?? '';
            $this->send('381 Enter passphrase');
            return;
        }

        if ($sub === 'PASS') {
            if ($this->authenticated) {
                $this->send('502 Already authenticated');
                return;
            }
            if ($this->pendingAuthUser === null || $this->pendingAuthUser === '') {
                $this->send('482 AUTHINFO USER required first');
                return;
            }
            if (!$this->tlsActive && $this->isPlaintextPort && !$this->config->isPlaintextAuthAllowed()) {
                $this->send('483 Secure connection required (issue STARTTLS first)');
                return;
            }

            $password = $args[1] ?? '';
            $user = $this->auth->login($this->pendingAuthUser, $password, $this->remoteIp);
            if ($user === null) {
                $this->logger->warning('[nntp] auth failed', [
                    'ip' => $this->remoteIp,
                    'user' => $this->pendingAuthUser,
                ]);
                $this->pendingAuthUser = null;
                $this->send('481 Authentication failed');
                return;
            }

            $this->authenticated = true;
            $this->userId = (int)$user['id'];
            $this->loadSubscriptions();

            $channel = $this->tlsActive ? 'TLS' : 'unencrypted AUTHINFO';
            $this->logger->info("[nntp] authenticated {$user['username']} via {$channel}", [
                'ip' => $this->remoteIp,
            ]);
            $this->auth->touch('NNTP: reading news');
            $this->send('281 Authentication accepted');
            return;
        }

        $this->send('501 Unknown AUTHINFO command');
    }

    private function loadSubscriptions(): void
    {
        $mgr = new EchoareaSubscriptionManager();
        $this->subscribed = [];
        foreach ($mgr->getUserSubscribedEchoareas($this->userId) as $row) {
            $this->subscribed[(int)$row['id']] = $row;
        }
    }

    // ── LIST ───────────────────────────────────────────────────────────────

    private function cmdList(array $args): void
    {
        $keyword = strtoupper($args[0] ?? 'ACTIVE');

        if ($keyword === 'OVERVIEW.FMT') {
            $this->sendMulti('215 Order of fields in overview database.', [
                'Subject:', 'From:', 'Date:', 'Message-ID:', 'References:', ':bytes', ':lines',
            ]);
            return;
        }

        if (!$this->requireAuth()) {
            return;
        }

        $wildmat = $args[1] ?? null;

        if ($keyword === 'ACTIVE') {
            $lines = [];
            foreach ($this->visibleGroups() as $g) {
                if ($wildmat !== null && !self::wildmat($wildmat, $g['group'])) {
                    continue;
                }
                $lines[] = sprintf('%s %d %d %s', $g['group'], $g['high'], $g['low'], $g['source']->isPostable() ? 'y' : 'n');
            }
            $this->sendMulti('215 list of newsgroups follows', $lines);
            return;
        }

        if ($keyword === 'NEWSGROUPS') {
            $lines = [];
            foreach ($this->visibleGroups() as $g) {
                if ($wildmat !== null && !self::wildmat($wildmat, $g['group'])) {
                    continue;
                }
                $lines[] = $g['group'] . "\t" . $g['source']->description();
            }
            $this->sendMulti('215 list of newsgroups follows', $lines);
            return;
        }

        if ($keyword === 'ACTIVE.TIMES') {
            $host = $this->serverName();
            $lines = [];
            foreach ($this->visibleGroups() as $g) {
                if ($wildmat !== null && !self::wildmat($wildmat, $g['group'])) {
                    continue;
                }
                $created = $g['source']->createdAtUnix();
                $lines[] = sprintf('%s %d nntp@%s', $g['group'], max(0, $created ?? 0), $host);
            }
            $this->sendMulti('215 information follows', $lines);
            return;
        }

        if ($keyword === 'HEADERS') {
            // ":" means every header is retrievable via HDR.
            $this->sendMulti('215 metadata items supported:', [':']);
            return;
        }

        $this->send('501 Unknown LIST keyword');
    }

    /**
     * Subscribed, listable groups with their current bounds and backing source.
     *
     * @return array<int,array{group:string,low:int,high:int,count:int,source:NntpGroupSource}>
     */
    private function visibleGroups(): array
    {
        $out = [];
        $netmailName = $this->netmailGroupName();
        foreach ($this->subscribed as $row) {
            $group = $this->groups->groupNameForArea($row);
            if ($group === null) {
                continue;
            }
            // A misconfigured echoarea whose translated name collides with the
            // netmail group is skipped here — the per-user netmail group wins.
            if ($netmailName !== null && strcasecmp($group, $netmailName) === 0) {
                continue;
            }
            $source = $this->sourceForArea($row);
            $source->ensureNumbered();
            $bounds = $source->bounds();
            $out[] = [
                'group' => $group,
                'low' => $bounds['low'],
                'high' => $bounds['high'],
                'count' => $bounds['count'],
                'source' => $source,
            ];
        }

        if ($netmailName !== null) {
            $source = $this->netmailSource();
            $source->ensureNumbered();
            $bounds = $source->bounds();
            $out[] = [
                'group' => $netmailName,
                'low' => $bounds['low'],
                'high' => $bounds['high'],
                'count' => $bounds['count'],
                'source' => $source,
            ];
        }

        usort($out, static fn ($a, $b) => strcmp($a['group'], $b['group']));

        return $out;
    }

    /**
     * Build the echomail group source for a subscribed echoarea row.
     *
     * @param array<string,mixed> $row  echoareas row
     */
    private function sourceForArea(array $row): EchomailGroupSource
    {
        if (!isset($row['nntp_group'])) {
            $row['nntp_group'] = $this->groups->groupNameForArea($row);
        }

        return new EchomailGroupSource($this->db, $row, $this->numbers, $this->builder);
    }

    /**
     * Configured netmail newsgroup name for this authenticated connection, or
     * null when the feature is disabled or the connection is unauthenticated
     * (the group is per-user).
     */
    private function netmailGroupName(): ?string
    {
        if (!$this->authenticated || $this->userId === null || !$this->config->isNetmailGroupExposed()) {
            return null;
        }

        return $this->config->getNetmailGroupName();
    }

    private function netmailSource(): NetmailGroupSource
    {
        if ($this->netmailSource === null) {
            $handler = new \BinktermPHP\MessageHandler();
            $numbers = new NntpNetmailArticleNumbers(
                $this->db,
                $handler,
                $this->config->shouldIncludeSentNetmail() ? 'either' : 'recipient'
            );
            $builder = new NntpNetmailArticleBuilder(
                $this->db,
                $this->config->getNetmailGroupName(),
                null,
                $this->config->shouldConvertOutboundQuotes()
            );
            $this->netmailSource = new NetmailGroupSource(
                $this->db,
                (int)$this->userId,
                $this->config->getNetmailGroupName(),
                'Your private netmail',
                $this->config->isNetmailSendAllowed(),
                $handler,
                $numbers,
                $builder
            );
        }

        return $this->netmailSource;
    }

    // ── NEWGROUPS / NEWNEWS ────────────────────────────────────────────────

    private function cmdNewGroups(array $args): void
    {
        if (!$this->requireAuth()) {
            return;
        }
        $since = self::parseSince($args);
        if ($since === null) {
            $this->send('501 Syntax: NEWGROUPS yyyymmdd hhmmss [GMT]');
            return;
        }

        $lines = [];
        foreach ($this->visibleGroups() as $g) {
            $created = $g['source']->createdAtUnix();
            if ($created !== null && $created >= $since) {
                $lines[] = sprintf('%s %d %d %s', $g['group'], $g['high'], $g['low'], $g['source']->isPostable() ? 'y' : 'n');
            }
        }
        $this->sendMulti('231 list of new newsgroups follows', $lines);
    }

    private function cmdNewNews(array $args): void
    {
        if (!$this->requireAuth()) {
            return;
        }
        $wildmat = array_shift($args) ?? '*';
        $since = self::parseSince($args);
        if ($since === null) {
            $this->send('501 Syntax: NEWNEWS wildmat yyyymmdd hhmmss [GMT]');
            return;
        }

        $lines = [];
        foreach ($this->visibleGroups() as $g) {
            if (!self::wildmat($wildmat, $g['group'])) {
                continue;
            }
            foreach ($g['source']->newMessageIdsSince($since) as $mid) {
                $lines[] = $mid;
            }
        }
        $this->sendMulti('230 list of new articles follows', array_values(array_unique($lines)));
    }

    // ── GROUP / LISTGROUP ──────────────────────────────────────────────────

    private function cmdGroup(array $args): void
    {
        if (!$this->requireAuth()) {
            return;
        }
        $name = $args[0] ?? '';
        $source = $this->selectGroup($name);
        if ($source === null) {
            return;
        }

        $bounds = $source->bounds();
        $this->pointer = $bounds['count'] > 0 ? $bounds['low'] : null;
        $this->send(sprintf(
            '211 %d %d %d %s',
            $bounds['count'],
            $bounds['low'],
            $bounds['high'],
            $source->groupName()
        ));
    }

    private function cmdListGroup(array $args): void
    {
        if (!$this->requireAuth()) {
            return;
        }

        $name = $args[0] ?? null;
        if ($name === null && $this->source === null) {
            $this->send('412 No newsgroup selected');
            return;
        }

        $source = $name !== null ? $this->selectGroup($name) : $this->source;
        if ($source === null) {
            return;
        }

        $bounds = $source->bounds();
        $lo = $bounds['low'];
        $hi = $bounds['high'];
        if (isset($args[1]) && preg_match('/^(\d+)(-(\d*))?$/', $args[1], $m)) {
            $lo = max($lo, (int)$m[1]);
            if (isset($m[2])) {
                $hi = $m[3] === '' ? $hi : (int)$m[3];
            } else {
                $hi = $lo;
            }
        }

        $nums = array_keys($source->range($lo, $hi));
        $this->pointer = $bounds['count'] > 0 ? $bounds['low'] : null;
        $this->sendMulti(
            sprintf('211 %d %d %d %s list follows', $bounds['count'], $bounds['low'], $bounds['high'], $source->groupName()),
            array_map('strval', $nums)
        );
    }

    /**
     * Resolve + authorize a newsgroup name, emitting the error response on failure.
     * Sets it as the current group on success.
     */
    private function selectGroup(string $name): ?NntpGroupSource
    {
        $netmailName = $this->netmailGroupName();
        if ($netmailName !== null && strcasecmp($name, $netmailName) === 0) {
            $source = $this->netmailSource();
            $source->ensureNumbered();
            $this->source = $source;

            return $source;
        }

        $row = $this->groups->resolveGroup($name);
        if ($row === null || !isset($this->subscribed[(int)$row['id']])) {
            $this->send('411 No such newsgroup (or not subscribed)');
            return null;
        }
        $source = $this->sourceForArea($row);
        $source->ensureNumbered();
        $this->source = $source;

        return $source;
    }

    // ── ARTICLE / HEAD / BODY / STAT ───────────────────────────────────────

    private function cmdArticle(string $command, array $args): void
    {
        if (!$this->requireAuth()) {
            return;
        }

        $selector = $args[0] ?? null;
        $number = 0;
        $built = null;

        if ($selector !== null && str_starts_with($selector, '<')) {
            $resolved = $this->resolveByMessageId($selector);
            if ($resolved === null) {
                $this->send('430 No article with that message-id');
                return;
            }
            [$source, $number] = $resolved;
            $built = $source->article($number);
        } else {
            if ($this->source === null) {
                $this->send('412 No newsgroup selected');
                return;
            }
            $number = $selector !== null ? (int)$selector : ($this->pointer ?? 0);
            if ($number <= 0) {
                $this->send('420 No current article selected');
                return;
            }
            $built = $this->source->article($number);
            if ($built === null) {
                $this->send('423 No article with that number');
                return;
            }
            if ($selector !== null) {
                $this->pointer = $number;
            }
        }

        if ($built === null) {
            $this->send('423 No article with that number');
            return;
        }

        $headerLines = $built['headers'];
        $bodyLines = $built['body'] === '' ? [] : explode("\n", $built['body']);

        switch ($command) {
            case 'STAT':
                $this->send(sprintf('223 %d %s', $number, $built['message_id']));
                return;
            case 'HEAD':
                $this->sendMulti(sprintf('221 %d %s', $number, $built['message_id']), $headerLines);
                return;
            case 'BODY':
                $this->sendMulti(sprintf('222 %d %s', $number, $built['message_id']), $bodyLines);
                return;
            case 'ARTICLE':
            default:
                $payload = array_merge($headerLines, [''], $bodyLines);
                $this->sendMulti(sprintf('220 %d %s', $number, $built['message_id']), $payload);
        }
    }

    // ── POST ──────────────────────────────────────────────────────────────

    /** Hard cap on a POSTed article, in bytes. */
    private const MAX_POST_BYTES = 4 * 1024 * 1024;

    private function cmdPost(): void
    {
        if (!$this->config->isEnabled() || !$this->config->isPostingAllowed()) {
            $this->send('440 Posting not permitted');
            return;
        }
        if (!$this->authenticated) {
            $this->send('480 Authentication required');
            return;
        }
        if (!$this->tlsActive && $this->isPlaintextPort && !$this->config->isPlaintextAuthAllowed()) {
            $this->send('483 Secure connection required (issue STARTTLS first)');
            return;
        }

        $this->send('340 Send article; end with <CR-LF>.<CR-LF>');

        $raw = $this->readDotTerminated();
        if ($raw === null) {
            // client hung up or the article was too large
            $this->send('441 Article rejected (too large or connection lost)');
            return;
        }

        $post = new NntpPost($this->db, $this->config, $this->logger, $this->groups, (int)$this->userId, $this->subscribed);
        $result = $post->submit($raw);
        $this->auth->touch('NNTP: posting');
        $this->send($result['code'] . ' ' . $result['text']);
    }

    /**
     * Read a dot-terminated block from the client, un-terminating (but not
     * un-dot-stuffing — the parser does that) each line. Returns null on EOF or
     * when the article exceeds MAX_POST_BYTES.
     */
    private function readDotTerminated(): ?string
    {
        $buf = '';
        while (true) {
            $line = ($this->readLine)();
            if ($line === null) {
                return null;
            }
            $line = rtrim($line, "\r\n");
            if ($line === '.') {
                return $buf;
            }
            $buf .= $line . "\r\n";
            if (strlen($buf) > self::MAX_POST_BYTES) {
                // Keep draining until the terminator so the stream stays in sync,
                // then signal failure.
                while (($drain = ($this->readLine)()) !== null && rtrim($drain, "\r\n") !== '.') {
                    // discard
                }
                return null;
            }
        }
    }

    // ── OVER / XOVER ───────────────────────────────────────────────────────

    private function cmdOver(array $args): void
    {
        if (!$this->requireAuth()) {
            return;
        }

        $selector = $args[0] ?? null;

        if ($selector !== null && str_starts_with($selector, '<')) {
            $resolved = $this->resolveByMessageId($selector);
            if ($resolved === null) {
                $this->send('430 No article with that message-id');
                return;
            }
            [$source, $number] = $resolved;
            $line = $source->overview($number);
            $this->sendMulti('224 Overview information follows', $line === null ? [] : [$line]);
            return;
        }

        if ($this->source === null) {
            $this->send('412 No newsgroup selected');
            return;
        }

        $bounds = $this->source->bounds();
        $lo = $bounds['low'];
        $hi = $bounds['high'];

        if ($selector === null) {
            $lo = $hi = $this->pointer ?? 0;
            if ($lo <= 0) {
                $this->send('420 No current article selected');
                return;
            }
        } elseif (preg_match('/^(\d+)(-(\d*))?$/', $selector, $m)) {
            $lo = max($lo, (int)$m[1]);
            $hi = isset($m[2]) ? ($m[3] === '' ? $hi : (int)$m[3]) : (int)$m[1];
        } else {
            $this->send('501 Bad range');
            return;
        }

        $rangeMap = $this->source->range($lo, $hi, 5000);
        $lines = array_values($this->source->overviewBatch($rangeMap));
        $this->sendMulti('224 Overview information follows', $lines);
    }

    // ── HDR / XHDR ─────────────────────────────────────────────────────────

    private function cmdHdr(array $args): void
    {
        if (!$this->requireAuth()) {
            return;
        }
        $field = strtolower($args[0] ?? '');
        if ($field === '') {
            $this->send('501 Syntax: HDR field [range]');
            return;
        }
        if ($this->source === null) {
            $this->send('412 No newsgroup selected');
            return;
        }

        $bounds = $this->source->bounds();
        $lo = $bounds['low'];
        $hi = $bounds['high'];
        $selector = $args[1] ?? null;
        if ($selector !== null && preg_match('/^(\d+)(-(\d*))?$/', $selector, $m)) {
            $lo = max($lo, (int)$m[1]);
            $hi = isset($m[2]) ? ($m[3] === '' ? $hi : (int)$m[3]) : (int)$m[1];
        } elseif ($selector === null) {
            $lo = $hi = $this->pointer ?? $lo;
        }

        $rangeMap = $this->source->range($lo, $hi, 5000);

        $lines = [];
        foreach ($this->source->articleBatch($rangeMap) as $number => $built) {
            $value = $this->headerFromLines($built['headers'], $field);
            if ($value !== null) {
                $lines[] = $number . ' ' . $value;
            }
        }
        $this->sendMulti('225 Headers follow', $lines);
    }

    private function headerFromLines(array $headerLines, string $field): ?string
    {
        $value = null;
        foreach ($headerLines as $line) {
            if ($value !== null) {
                // Folded continuation line (leading WSP) — unfold onto one line.
                if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                    $value .= ' ' . trim($line);
                    continue;
                }
                break;
            }
            $pos = strpos($line, ':');
            if ($pos !== false && strtolower(substr($line, 0, $pos)) === $field) {
                $value = trim(substr($line, $pos + 1));
            }
        }

        return $value;
    }

    // ── LAST / NEXT ────────────────────────────────────────────────────────

    private function cmdLastNext(string $command): void
    {
        if (!$this->requireAuth()) {
            return;
        }
        if ($this->source === null) {
            $this->send('412 No newsgroup selected');
            return;
        }
        if ($this->pointer === null) {
            $this->send('420 No current article selected');
            return;
        }

        $bounds = $this->source->bounds();
        $nums = array_keys($this->source->range($bounds['low'], $bounds['high']));
        $idx = array_search($this->pointer, $nums, true);
        if ($idx === false) {
            $this->send('420 No current article');
            return;
        }

        $target = $command === 'NEXT' ? ($nums[$idx + 1] ?? null) : ($nums[$idx - 1] ?? null);
        if ($target === null) {
            $this->send($command === 'NEXT' ? '421 No next article' : '422 No previous article');
            return;
        }

        $this->pointer = $target;
        $mid = $this->source->messageIdForNumber($target) ?? '<unknown>';
        $this->send(sprintf('223 %d %s', $target, $mid));
    }

    // ── Shared helpers ─────────────────────────────────────────────────────

    /**
     * Resolve an `<...>` Message-ID to the group source that owns it and the
     * article number within that group. Only groups the user is subscribed to
     * (and, for netmail, entitled to) are considered.
     *
     * @return array{0:NntpGroupSource,1:int}|null
     */
    private function resolveByMessageId(string $messageId): ?array
    {
        $parsed = NntpMessageId::parse($messageId);
        if ($parsed === null) {
            return null;
        }

        $netmailName = $this->netmailGroupName();
        if ($netmailName !== null && strcasecmp($parsed['group'], $netmailName) === 0) {
            $source = $this->netmailSource();
            $source->ensureNumbered();
            $number = $source->resolveMessageId($messageId);

            return $number === null ? null : [$source, $number];
        }

        $area = $this->groups->resolveGroup($parsed['group']);
        if ($area === null || !isset($this->subscribed[(int)$area['id']])) {
            return null;
        }

        $source = $this->sourceForArea($area);
        $source->ensureNumbered();
        $number = $source->resolveMessageId($messageId);
        if ($number === null) {
            return null;
        }

        return [$source, $number];
    }

    private function requireAuth(): bool
    {
        if ($this->authenticated) {
            return true;
        }
        $this->send('480 Authentication required');

        return false;
    }

    private function postingReady(): bool
    {
        return $this->config->isEnabled() && $this->config->isPostingAllowed() && $this->authenticated;
    }

    private function serverName(): string
    {
        try {
            $host = parse_url(Config::getSiteUrl(), PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return $host;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return 'localhost';
    }

    // ── Wire output ────────────────────────────────────────────────────────

    private function send(string $line): void
    {
        ($this->writeRaw)($line . "\r\n");
    }

    /**
     * Status line + dot-terminated multiline block, with dot-stuffing.
     *
     * @param string[] $lines
     */
    private function sendMulti(string $status, array $lines): void
    {
        $buf = $status . "\r\n";
        foreach ($lines as $line) {
            $line = str_replace(["\r", "\n"], '', (string)$line);
            if (isset($line[0]) && $line[0] === '.') {
                $line = '.' . $line;
            }
            $buf .= $line . "\r\n";
        }
        $buf .= ".\r\n";
        ($this->writeRaw)($buf);
    }

    // ── Static parsing helpers ─────────────────────────────────────────────

    /**
     * Parse the `yyyymmdd hhmmss [GMT]` trailer of NEWGROUPS/NEWNEWS into a UNIX ts.
     * Treated as UTC regardless of the GMT token (documented behaviour).
     */
    public static function parseSince(array $args): ?int
    {
        if (count($args) < 2) {
            return null;
        }
        $date = $args[0];
        $time = $args[1];
        if (!preg_match('/^\d{6}$|^\d{8}$/', $date) || !preg_match('/^\d{6}$/', $time)) {
            return null;
        }
        if (strlen($date) === 6) {
            $yy = (int)substr($date, 0, 2);
            $year = $yy < 70 ? 2000 + $yy : 1900 + $yy;
            $date = $year . substr($date, 2);
        }
        $ts = gmmktime(
            (int)substr($time, 0, 2),
            (int)substr($time, 2, 2),
            (int)substr($time, 4, 2),
            (int)substr($date, 4, 2),
            (int)substr($date, 6, 2),
            (int)substr($date, 0, 4)
        );

        return $ts === false ? null : $ts;
    }

    /**
     * Minimal wildmat matcher (RFC 3977 §4): comma-separated patterns, trailing `!`
     * negation, `*` and `?` wildcards. Sufficient for LIST/NEWNEWS filtering.
     */
    public static function wildmat(string $pattern, string $subject): bool
    {
        $matched = false;
        foreach (explode(',', $pattern) as $token) {
            $negate = str_starts_with($token, '!');
            if ($negate) {
                $token = substr($token, 1);
            }
            $regex = '/^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($token, '/')) . '$/i';
            if (preg_match($regex, $subject)) {
                $matched = !$negate;
            }
        }

        return $matched;
    }
}
