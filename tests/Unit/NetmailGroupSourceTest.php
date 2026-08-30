<?php

declare(strict_types=1);

use BinktermPHP\MessageHandler;
use BinktermPHP\Nntp\NetmailGroupSource;
use BinktermPHP\Nntp\NntpNetmailArticleBuilder;
use BinktermPHP\Nntp\NntpNetmailArticleNumbers;
use PHPUnit\Framework\TestCase;

/**
 * NetmailGroupSource end-to-end over an in-memory SQLite DB: article shape,
 * the sent-folder tag, cross-user isolation of ARTICLE and Message-ID lookups.
 */
final class NetmailGroupSourceTest extends TestCase
{
    private PDO $db;
    private MessageHandler $handler;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->db->exec(
            "CREATE TABLE netmail (
                id INTEGER PRIMARY KEY, user_id INTEGER,
                from_address TEXT, to_address TEXT, from_name TEXT, to_name TEXT,
                subject TEXT, message_text TEXT, date_written TEXT, date_received TEXT,
                attributes INTEGER DEFAULT 0, is_sent INTEGER DEFAULT 0, reply_to_id INTEGER,
                message_id TEXT, reply_address TEXT, kludge_lines TEXT, bottom_kludges TEXT,
                message_charset TEXT DEFAULT 'UTF-8',
                deleted_by_sender INTEGER DEFAULT 0, deleted_by_recipient INTEGER DEFAULT 0
            )"
        );
        $this->db->exec(
            'CREATE TABLE nntp_netmail_article_numbers (
                user_id INTEGER NOT NULL, article_number INTEGER NOT NULL, netmail_id INTEGER NOT NULL,
                PRIMARY KEY (user_id, article_number)
            )'
        );
        $this->db->exec('CREATE UNIQUE INDEX ux_nnan ON nntp_netmail_article_numbers (user_id, netmail_id)');
        $this->db->exec('CREATE TABLE nntp_netmail_watermark (user_id INTEGER PRIMARY KEY, last_article_number INTEGER NOT NULL DEFAULT 0)');

        $this->handler = new class extends MessageHandler {
            public function __construct() {}
            public function netmailVisibilityFilter(int $userId, string $alias = 'n', string $side = 'either'): array
            {
                return ['sql' => "$alias.user_id = ?", 'params' => [$userId]];
            }
            public function netmailNotDeletedFilter(int $userId, string $alias = 'n'): array
            {
                return ['sql' => "($alias.deleted_by_sender = 0 AND $alias.deleted_by_recipient = 0)", 'params' => []];
            }
            public function netmailRowIsOutgoing(int $userId, array $row): bool
            {
                return trim((string)($row['from_name'] ?? '')) === 'Me';
            }
        };

        try {
            $this->db->query('SELECT ROW_NUMBER() OVER (ORDER BY 1)')->fetchAll();
        } catch (\Throwable $e) {
            self::markTestSkipped('SQLite lacks window functions');
        }
    }

    private function source(int $userId): NetmailGroupSource
    {
        $numbers = new NntpNetmailArticleNumbers($this->db, $this->handler);
        $builder = new NntpNetmailArticleBuilder($this->db, 'netmail', 'bbs.example.org', false, null);

        return new NetmailGroupSource(
            $this->db, $userId, 'netmail', 'Your private netmail', true,
            $this->handler, $numbers, $builder
        );
    }

    private function insert(int $id, int $userId, string $fromName, string $subject, ?string $msgid): void
    {
        $this->db->prepare(
            'INSERT INTO netmail (id, user_id, from_address, to_address, from_name, to_name, subject, message_text, date_written, date_received, message_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id, $userId, '21:1/100', '21:1/2', $fromName, 'You', $subject, "Body of {$id}",
            '2026-08-30 12:00:00', '2026-08-30 12:00:05', $msgid,
        ]);
    }

    public function testArticleShapeAndSentTag(): void
    {
        $this->insert(1, 7, 'Alice', 'Inbound hello', '21:1/100 AAAA0001');
        $this->insert(2, 7, 'Me', 'My reply', '21:1/2 BBBB0002');

        $src = $this->source(7);
        $src->ensureNumbered();

        $inbound = $src->article(1);
        self::assertNotNull($inbound);
        $h = implode("\n", $inbound['headers']);
        self::assertStringContainsString('Newsgroups: netmail', $h);
        self::assertStringContainsString('To: ', $h);
        self::assertStringContainsString('X-FTN-From-Address: 21:1/100', $h);
        self::assertStringContainsString('X-FTN-To-Address: 21:1/2', $h);
        self::assertStringNotContainsString('X-FTN-AREA', $h);
        self::assertStringNotContainsString('X-FTN-SEEN-BY', $h);
        self::assertStringNotContainsString('X-BinktermPHP-Folder: sent', $h);

        $sent = $src->article(2);
        self::assertNotNull($sent);
        self::assertStringContainsString('X-BinktermPHP-Folder: sent', implode("\n", $sent['headers']));
    }

    public function testCrossUserIsolation(): void
    {
        $this->insert(1, 7, 'Alice', 'For user 7', '21:1/100 AAAA0001');
        $this->insert(2, 9, 'Bob', 'For user 9', '21:1/200 CCCC0003');

        $s7 = $this->source(7);
        $s9 = $this->source(9);
        $s7->ensureNumbered();
        $s9->ensureNumbered();

        // Each sees exactly one article, numbered 1.
        self::assertSame(1, $s7->bounds()['count']);
        self::assertSame(1, $s9->bounds()['count']);

        // User 7's source cannot fetch user 9's message by number or by Message-ID.
        self::assertNotNull($s7->article(1));
        self::assertNull($s7->article(2));
        self::assertNull($s7->resolveMessageId('<CCCC0003.z21n1f200p0.netmail@bbs.example.org>'));
        self::assertSame(1, $s9->resolveMessageId('<CCCC0003.z21n1f200p0.netmail@bbs.example.org>'));
    }
}
