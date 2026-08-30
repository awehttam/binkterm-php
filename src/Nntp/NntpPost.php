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
use BinktermPHP\MessageHandler;
use PDO;

/**
 * Injects an article POSTed by a newsreader into echomail via the shared posting
 * path (`MessageHandler::postEchomail()`), applying NNTP-specific validation the
 * web/terminal controllers do not share: `Newsgroups:` resolution, an independent
 * cross-post cap that *rejects* (does not truncate), and per-user rate limits.
 *
 * See docs/proposals/NNTPServer.md — "Bidirectional Posting".
 */
class NntpPost
{
    private PDO $db;
    private NntpConfig $config;
    private Logger $logger;
    private NntpNewsgroups $groups;
    private int $userId;
    /** @var array<int,array<string,mixed>>  subscribed echoarea id => row */
    private array $subscribed;

    /**
     * @param array<int,array<string,mixed>> $subscribed
     */
    public function __construct(
        PDO $db,
        NntpConfig $config,
        Logger $logger,
        NntpNewsgroups $groups,
        int $userId,
        array $subscribed
    ) {
        $this->db = $db;
        $this->config = $config;
        $this->logger = $logger;
        $this->groups = $groups;
        $this->userId = $userId;
        $this->subscribed = $subscribed;
    }

    /**
     * Validate and inject a raw POSTed article.
     *
     * @return array{code:int,text:string}  NNTP status line (code + text)
     */
    public function submit(string $rawArticle): array
    {
        $article = NntpArticleParser::parse($rawArticle);
        $headers = $article['headers'];

        // Cancel / supersede / newgroup control messages have no FTN equivalent —
        // accept and drop.
        if (NntpArticleParser::isControl($headers)) {
            $this->logger->info('[nntp] dropped control message from user ' . $this->userId);
            return $this->ok('Article received (control message ignored)');
        }

        $groupNames = NntpArticleParser::tokenList($headers['newsgroups'] ?? null);
        if ($groupNames === []) {
            return $this->fail('Missing Newsgroups header');
        }

        // Netmail newsgroup — a separate, non-cross-postable path.
        $netmailGroup = $this->config->getNetmailGroupName();
        $namesNetmail = false;
        foreach ($groupNames as $name) {
            if (strcasecmp($name, $netmailGroup) === 0) {
                $namesNetmail = true;
                break;
            }
        }
        if ($namesNetmail) {
            if (count($groupNames) > 1) {
                return $this->fail('Netmail cannot be cross-posted');
            }
            return (new NntpNetmailPost($this->db, $this->config, $this->logger, $this->userId))->submit($article);
        }

        $max = $this->config->getMaxCrossPostAreas();
        if (count($groupNames) > $max) {
            return $this->fail("Too many groups in Newsgroups header (limit {$max}); post rejected");
        }

        // Resolve every target group up front; reject the whole post if any is
        // unknown or not subscribed.
        $targets = [];
        foreach ($groupNames as $name) {
            $area = $this->groups->resolveGroup($name);
            if ($area === null || !isset($this->subscribed[(int)$area['id']])) {
                return $this->fail("No such newsgroup (or not subscribed): {$name}");
            }
            $targets[] = $area;
        }

        $subject = trim((string)($headers['subject'] ?? ''));
        if ($subject === '') {
            return $this->fail('Missing Subject header');
        }

        $body = $article['body'];
        if (trim($body) === '') {
            return $this->fail('Empty article body');
        }

        $rateError = $this->rateLimitError();
        if ($rateError !== null) {
            return $this->fail($rateError);
        }

        $toName = trim((string)($headers['x-comment-to'] ?? $headers['to'] ?? '')) ?: 'All';

        // References -> immediate parent echomail.id, resolved within the primary area.
        $replyToId = $this->resolveParent(
            NntpArticleParser::lastReference($headers),
            (int)$targets[0]['id']
        );

        // Rewrite Internet "> " quoting to FSC-0032 " XX> " using the quoted
        // author's initials (the parent this reply threads onto), when enabled.
        if ($this->config->shouldConvertInboundQuotes() && $replyToId !== null) {
            $quoter = $this->parentFromName($replyToId);
            if ($quoter !== '') {
                $body = NntpQuoteStyle::toFtn($body, $quoter);
            }
        }

        $handler = new MessageHandler();
        $primaryPending = false;
        $posted = 0;
        $failures = [];

        foreach ($targets as $index => $area) {
            $isPrimary = ($index === 0);
            try {
                $result = $handler->postEchomail(
                    $this->userId,
                    (string)$area['tag'],
                    (string)($area['domain'] ?? ''),
                    $toName,
                    $subject,
                    $body,
                    $isPrimary ? $replyToId : null,
                    null,
                    !$isPrimary,   // skipCredits for cross-posted copies
                    null,
                    '',
                    'NNTP', // tearline component -> "--- BinktermPHP NNTP vX.Y.Z"
                    'UTF-8',
                    null
                );

                if ($result === 'pending') {
                    $posted++;
                    if ($isPrimary) {
                        $primaryPending = true;
                    }
                } elseif ($result) {
                    $posted++;
                } elseif ($isPrimary) {
                    return $this->fail('Posting failed');
                } else {
                    $failures[] = (string)$area['tag'];
                }
            } catch (\Throwable $e) {
                if ($isPrimary) {
                    $this->logger->warning('[nntp] post failed for ' . $area['tag'] . ': ' . $e->getMessage());
                    return $this->fail('Posting failed: ' . $e->getMessage());
                }
                $failures[] = (string)$area['tag'];
                $this->logger->warning('[nntp] cross-post failed for ' . $area['tag'] . ': ' . $e->getMessage());
            }
        }

        $this->logger->info(sprintf(
            '[nntp] user %d posted "%s" to %d/%d group(s)%s',
            $this->userId,
            $subject,
            $posted,
            count($targets),
            $failures ? ' (failed: ' . implode(', ', $failures) . ')' : ''
        ));

        if ($primaryPending) {
            return $this->ok('Article received and held for moderation');
        }

        return $this->ok('Article posted');
    }

    /**
     * @return string|null  error message when a per-user rate limit is exceeded
     */
    private function rateLimitError(): ?string
    {
        $perMinute = $this->config->getPostsPerMinute();
        $perHour = $this->config->getPostsPerHour();

        if ($perMinute > 0 && $this->recentPostCount('1 minute') >= $perMinute) {
            return "Rate limit exceeded ({$perMinute} posts/minute)";
        }
        if ($perHour > 0 && $this->recentPostCount('1 hour') >= $perHour) {
            return "Rate limit exceeded ({$perHour} posts/hour)";
        }

        return null;
    }

    private function recentPostCount(string $interval): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM echomail
             WHERE user_id = :uid
               AND date_received >= (NOW() AT TIME ZONE 'UTC') - INTERVAL '{$interval}'"
        );
        $stmt->execute(['uid' => $this->userId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Resolve a parent Message-ID (as this server would have emitted it) to an
     * echomail.id within the given area.
     */
    private function resolveParent(?string $messageId, int $echoareaId): ?int
    {
        if ($messageId === null) {
            return null;
        }
        $parsed = NntpMessageId::parse($messageId);
        if ($parsed === null) {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT id FROM echomail
             WHERE echoarea_id = :id
               AND COALESCE(moderation_status,'approved') = 'approved'
               AND (message_id = :serial OR message_id LIKE :like)
             ORDER BY id LIMIT 1"
        );
        $stmt->execute([
            'id' => $echoareaId,
            'serial' => $parsed['serial'],
            'like' => '% ' . $parsed['serial'],
        ]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int)$id;
    }

    /**
     * from_name of an echomail row (the quoted author for inbound quote conversion),
     * or '' when the row is gone.
     */
    private function parentFromName(int $echomailId): string
    {
        $stmt = $this->db->prepare('SELECT from_name FROM echomail WHERE id = ?');
        $stmt->execute([$echomailId]);
        $name = $stmt->fetchColumn();

        return $name === false ? '' : trim((string)$name);
    }

    /**
     * @return array{code:int,text:string}
     */
    private function ok(string $text): array
    {
        return ['code' => 240, 'text' => $text];
    }

    /**
     * @return array{code:int,text:string}
     */
    private function fail(string $text): array
    {
        return ['code' => 441, 'text' => $text];
    }
}
