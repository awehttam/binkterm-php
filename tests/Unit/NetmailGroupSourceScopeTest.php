<?php

declare(strict_types=1);

use BinktermPHP\MessageHandler;
use BinktermPHP\Nntp\NetmailGroupSource;
use BinktermPHP\Nntp\NntpNetmailArticleBuilder;
use BinktermPHP\Nntp\NntpNetmailArticleNumbers;
use PHPUnit\Framework\TestCase;

/**
 * Fail-closed contract for {@see NetmailGroupSource::loadRows()}: even when a
 * caller hands it a raw `netmail.id` belonging to another user, the row is not
 * returned because loadRows re-applies the reading user's visibility scope.
 *
 * Runs against in-memory SQLite with a stub MessageHandler supplying the
 * predicate (production target is PostgreSQL). The real visibility predicate is
 * covered by NetmailVisibilityTest; per-user numbering by
 * {@see NntpNetmailArticleNumbersTest}.
 */
final class NetmailGroupSourceScopeTest extends TestCase
{
    private PDO $db;
    private MessageHandler $handler;
    private NntpNetmailArticleNumbers $numbers;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->db->exec(
            'CREATE TABLE netmail (
                id INTEGER PRIMARY KEY, user_id INTEGER,
                from_address TEXT, to_address TEXT, from_name TEXT, to_name TEXT,
                subject TEXT, message_text TEXT,
                date_written TEXT, date_received TEXT,
                attributes TEXT, is_sent INTEGER DEFAULT 0, reply_to_id INTEGER,
                message_id TEXT, reply_address TEXT,
                kludge_lines TEXT, bottom_kludges TEXT, message_charset TEXT,
                deleted_by_sender INTEGER DEFAULT 0, deleted_by_recipient INTEGER DEFAULT 0
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

        // Stub: "user sees a netmail row iff netmail.user_id = <them>".
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
                return (int)($row['user_id'] ?? 0) === $userId && (int)($row['is_sent'] ?? 0) === 1;
            }
        };

        $this->requireSqliteFeatures();
        $this->numbers = new NntpNetmailArticleNumbers($this->db, $this->handler);
    }

    private function requireSqliteFeatures(): void
    {
        try {
            $this->db->exec('CREATE TABLE _probe (id INTEGER)');
            $this->db->exec('INSERT INTO _probe (id) VALUES (1)');
            $this->db->query('SELECT ROW_NUMBER() OVER (ORDER BY id) FROM _probe')->fetchAll();
            $this->db->query(
                'INSERT INTO nntp_netmail_watermark (user_id, last_article_number) VALUES (1, 0)
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
        $this->db->prepare(
            'INSERT INTO netmail (id, user_id, from_address, to_address, from_name, to_name, subject, message_text, message_charset)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $userId, '21:1/1', '21:1/2', 'Sender ' . $id, 'Rcpt', 'Subject ' . $id, 'Body ' . $id, 'UTF-8']);
    }

    private function sourceFor(int $userId): NetmailGroupSource
    {
        return new NetmailGroupSource(
            $this->db,
            $userId,
            'netmail',
            'Your netmail',
            true,
            $this->handler,
            $this->numbers,
            new NntpNetmailArticleBuilder($this->db, 'netmail', 'test.example')
        );
    }

    private function callLoadRows(NetmailGroupSource $source, array $ids): array
    {
        $m = new ReflectionMethod(NetmailGroupSource::class, 'loadRows');
        $m->setAccessible(true);

        return $m->invoke($source, $ids);
    }

    public function testLoadRowsReturnsOnlyTheReadingUsersRows(): void
    {
        $this->insertNetmail(10, 1); // user 1
        $this->insertNetmail(11, 2); // user 2

        $rows = $this->callLoadRows($this->sourceFor(1), [10, 11]);

        self::assertArrayHasKey(10, $rows);
        self::assertArrayNotHasKey(11, $rows, 'another user\'s netmail row must not load');
    }

    public function testLoadRowsExcludesSoftDeletedRows(): void
    {
        $this->insertNetmail(20, 1);
        $this->db->exec('UPDATE netmail SET deleted_by_recipient = 1 WHERE id = 20');

        self::assertSame([], $this->callLoadRows($this->sourceFor(1), [20]));
    }

    public function testArticleBatchDropsForeignIdHandedInByCaller(): void
    {
        // Simulates a future buggy caller passing an unvetted id straight through.
        $this->insertNetmail(30, 2); // belongs to user 2

        $built = $this->sourceFor(1)->articleBatch([99 => 30]);

        self::assertSame([], $built);
    }
}
