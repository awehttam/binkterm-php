<?php

declare(strict_types=1);

use BinktermPHP\MessageHandler;
use BinktermPHP\Nntp\NntpNetmailArticleNumbers;
use PHPUnit\Framework\TestCase;

/**
 * Per-user netmail article numbering: isolation between users, monotonic
 * watermark, retired numbers.
 *
 * Runs against an in-memory SQLite DB with a stub MessageHandler that supplies
 * the visibility predicate (the real predicate is covered by
 * {@see NetmailVisibilityTest}). Skips if the SQLite build lacks the window /
 * upsert features the allocator needs — the production target is PostgreSQL.
 */
final class NntpNetmailArticleNumbersTest extends TestCase
{
    private PDO $db;
    private MessageHandler $handler;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->db->exec(
            'CREATE TABLE netmail (
                id INTEGER PRIMARY KEY, user_id INTEGER, from_name TEXT, from_address TEXT,
                to_name TEXT, to_address TEXT, deleted_by_sender INTEGER DEFAULT 0,
                deleted_by_recipient INTEGER DEFAULT 0
            )'
        );
        $this->db->exec(
            'CREATE TABLE nntp_netmail_article_numbers (
                user_id INTEGER NOT NULL, article_number INTEGER NOT NULL, netmail_id INTEGER NOT NULL,
                PRIMARY KEY (user_id, article_number)
            )'
        );
        $this->db->exec('CREATE UNIQUE INDEX ux_nnan ON nntp_netmail_article_numbers (user_id, netmail_id)');
        $this->db->exec(
            'CREATE TABLE nntp_netmail_watermark (user_id INTEGER PRIMARY KEY, last_article_number INTEGER NOT NULL DEFAULT 0)'
        );

        // Stub handler: "user sees a netmail row iff netmail.user_id = <them>".
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
        };

        $this->requireSqliteFeatures();
    }

    private function requireSqliteFeatures(): void
    {
        try {
            $this->db->exec('CREATE TABLE _probe (id INTEGER)');
            $this->db->exec('INSERT INTO _probe (id) VALUES (1)');
            $this->db->query('SELECT ROW_NUMBER() OVER (ORDER BY id) FROM _probe')->fetchAll();
            $this->db->exec(
                'INSERT INTO nntp_netmail_watermark (user_id, last_article_number) VALUES (1, 0)
                 ON CONFLICT (user_id) DO UPDATE SET last_article_number = nntp_netmail_watermark.last_article_number'
            );
            $this->db->query(
                'INSERT INTO nntp_netmail_watermark (user_id, last_article_number) VALUES (2, 0)
                 ON CONFLICT (user_id) DO UPDATE SET last_article_number = 0 RETURNING last_article_number'
            )->fetch();
            $this->db->exec('DELETE FROM nntp_netmail_watermark');
            $this->db->exec('DROP TABLE _probe');
        } catch (\Throwable $e) {
            self::markTestSkipped('SQLite build lacks window functions / upsert RETURNING: ' . $e->getMessage());
        }
    }

    private function insertNetmail(int $id, int $userId): void
    {
        $this->db->prepare('INSERT INTO netmail (id, user_id, from_name, from_address, to_name, to_address) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$id, $userId, 'Sender ' . $id, '21:1/1', 'Rcpt', '21:1/2']);
    }

    public function testNumbersAreDensePerUserAndIsolated(): void
    {
        // Interleaved ids across two users.
        $this->insertNetmail(10, 1);
        $this->insertNetmail(11, 2);
        $this->insertNetmail(12, 1);
        $this->insertNetmail(13, 1);
        $this->insertNetmail(14, 2);

        $numbers = new NntpNetmailArticleNumbers($this->db, $this->handler);

        $numbers->ensureUser(1);
        $numbers->ensureUser(2);

        // User 1: rows 10,12,13 -> 1,2,3
        self::assertSame(['low' => 1, 'high' => 3, 'count' => 3], $numbers->groupBounds(1));
        self::assertSame([1 => 10, 2 => 12, 3 => 13], $numbers->range(1, 1, null));

        // User 2: rows 11,14 -> 1,2  (independent space)
        self::assertSame(['low' => 1, 'high' => 2, 'count' => 2], $numbers->groupBounds(2));
        self::assertSame([1 => 11, 2 => 14], $numbers->range(2, 1, null));

        // User 1 cannot address user 2's row and vice versa.
        self::assertSame(12, $numbers->netmailIdFor(1, 2));
        self::assertNull($numbers->netmailIdFor(2, 3));
    }

    public function testWatermarkIsMonotonicAndNumbersAreRetired(): void
    {
        $this->insertNetmail(1, 1);
        $this->insertNetmail(2, 1);
        $this->insertNetmail(3, 1);

        $numbers = new NntpNetmailArticleNumbers($this->db, $this->handler);
        $numbers->ensureUser(1);
        self::assertSame(['low' => 1, 'high' => 3, 'count' => 3], $numbers->groupBounds(1));

        // Soft-delete the middle row: its number is retired, watermark stays.
        $this->db->exec('UPDATE netmail SET deleted_by_recipient = 1 WHERE id = 2');
        $numbers->ensureUser(1);
        $b = $numbers->groupBounds(1);
        self::assertSame(3, $b['high']);
        self::assertSame(2, $b['count']);
        self::assertSame([1 => 1, 3 => 3], $numbers->range(1, 1, null));
        self::assertNull($numbers->netmailIdFor(1, 2));

        // A new row picks up number 4, never reusing 2.
        $this->insertNetmail(4, 1);
        $numbers->ensureUser(1);
        self::assertSame(4, $numbers->numberFor(1, 4));
        self::assertSame(4, $numbers->groupBounds(1)['high']);
    }
}
