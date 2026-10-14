<?php

declare(strict_types=1);

use BinktermPHP\Avatar\AnsiAvatar;
use BinktermPHP\Avatar\AnsiAvatarApi;
use BinktermPHP\Avatar\AnsiAvatarPayload;
use BinktermPHP\Avatar\AnsiAvatarStore;
use BinktermPHP\Avatar\AnsiAvatarTerminal;
use BinktermPHP\Avatar\Cp437;
use BinktermPHP\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Unit/Support/TestDatabase.php';

/**
 * Codec, terminal-width, and disposable-database persistence tests for ANSI avatars.
 */
class AnsiAvatarTest extends TestCase
{
    /** @var list<int> */
    private array $createdUsers = [];

    protected function tearDown(): void
    {
        if ($this->createdUsers === []) {
            return;
        }
        $pdo = TestDatabase::pdo();
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
        foreach ($this->createdUsers as $id) {
            $stmt->execute([$id]);
        }
    }

    public function testEveryByteStaysTenColumnsAndCannotInject(): void
    {
        foreach (['utf8', 'cp437', 'ascii'] as $charset) {
            for ($byte = 0; $byte < 256; $byte++) {
                $avatar = $this->solid($byte, 14, 1);
                $lines = AnsiAvatarTerminal::lines($avatar, true, $charset);
                $this->assertIsArray($lines);
                $this->assertCount(6, $lines);
                foreach ($lines as $line) {
                    $this->assertSame(11, substr_count($line, "\x1b["), "SGR count byte {$byte} {$charset}");
                    $this->assertStringNotContainsString("\x1b[5", $line);
                    $this->assertSame(10, AnsiAvatarTerminal::visibleColumns($line, $charset));
                    $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $line) ?? $line;
                    $this->assertSame(0, preg_match('/[\x00-\x1F\x7F]/', $plain));
                    if ($charset === 'utf8') {
                        $this->assertLessThan(38, strlen($plain), 'utf8 row must survive a 40-column wrap');
                    }
                }
            }
        }
    }

    public function testControlByteIsNeverACommand(): void
    {
        $avatar = $this->solid(0x1B, 14, 1);
        $utf8 = AnsiAvatarTerminal::lines($avatar, true, 'utf8')[0];
        $cp437 = AnsiAvatarTerminal::lines($avatar, true, 'cp437')[0];
        $this->assertStringContainsString('←', $utf8);
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $cp437) ?? $cp437;
        $this->assertSame(str_repeat('<', 10), $plain);
        $this->assertStringNotContainsString("\x1b", str_replace(["\x1b["], '', $cp437));
        $this->assertSame('0;1;33;44', AnsiAvatarTerminal::sgr(14, 1));
        $this->assertStringNotContainsString('5', AnsiAvatarTerminal::sgr(14, 1));
    }

    public function testPayloadIgnoresCallerUserId(): void
    {
        $avatar = $this->solid(0xDB, 15, 4);
        $parsed = AnsiAvatarPayload::parseBody(json_encode([
            'ansi' => $avatar->toBase64(),
            'user_id' => 999001,
            'id' => 999002,
        ], JSON_THROW_ON_ERROR));
        $this->assertTrue($avatar->equals($parsed));
        $this->assertFalse(AnsiAvatarPayload::csrfMatches(str_repeat('a', 64), str_repeat('a', 63)));
    }

    public function testStoreRoundTripIsOwnerScopedAndSizeChecked(): void
    {
        $pdo = TestDatabase::pdo();
        $owner = $this->makeUser($pdo, 'owner');
        $other = $this->makeUser($pdo, 'other');
        $pdo->prepare('UPDATE users SET email = ?, location = ?, about_me = ? WHERE id = ?')
            ->execute(['owner@example.test', 'Test Lab', 'Biography stays', $owner]);

        $store = new AnsiAvatarStore($pdo);
        $api = new AnsiAvatarApi($pdo);
        $mine = $this->solid(0x41, 14, 1);
        $theirs = $this->solid(0x42, 2, 4);
        $store->save($other, $theirs);

        $saved = $api->save($owner, json_encode([
            'ansi' => $mine->toBase64(),
            'user_id' => $other,
        ], JSON_THROW_ON_ERROR));
        $this->assertSame($mine->toBase64(), $saved['ansi']);
        $this->assertTrue($mine->equals($store->find($owner)));
        $this->assertTrue($theirs->equals($store->find($other)));

        $profile = $pdo->prepare('SELECT email, location, about_me FROM users WHERE id = ?');
        $profile->execute([$owner]);
        $this->assertSame(
            ['email' => 'owner@example.test', 'location' => 'Test Lab', 'about_me' => 'Biography stays'],
            $profile->fetch(PDO::FETCH_ASSOC)
        );

        $api->remove($owner);
        $this->assertNull($store->find($owner));
        $this->assertTrue($theirs->equals($store->find($other)));

        foreach ([119, 121] as $size) {
            try {
                $pdo->prepare('INSERT INTO user_ansi_avatars (user_id, avatar_bin) VALUES (?, ?)')
                    ->execute([$owner, str_repeat('Z', $size)]);
                $this->fail("Database accepted a {$size}-byte avatar");
            } catch (PDOException $e) {
                $this->assertStringContainsString('user_ansi_avatars_exact_size', $e->getMessage());
            }
        }
    }

    public function testInactiveUserIsNotPublic(): void
    {
        $pdo = TestDatabase::pdo();
        $userId = $this->makeUser($pdo, 'inactive');
        $pdo->prepare('UPDATE users SET is_active = FALSE WHERE id = ?')->execute([$userId]);
        $this->assertFalse((new AnsiAvatarApi($pdo))->userIsActive($userId));
        $this->assertFalse((new AnsiAvatarApi($pdo))->userIsActive(0));
    }

    public function testJavascriptPlateDoesNotUseInnerHtml(): void
    {
        $js = (string)file_get_contents(dirname(__DIR__, 2) . '/public_html/js/avatar-ansi.js');
        $this->assertStringNotContainsString('innerHTML', $js);
        $this->assertStringNotContainsString('insertAdjacentHTML', $js);
        $this->assertMatchesRegularExpression('/const GLYPHS = \[/', $js);
        preg_match('/const GLYPHS = (\[.*?\]);/s', $js, $match);
        $glyphs = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(256, $glyphs);
        $this->assertSame('←', $glyphs[0x1B]);
        $this->assertSame('⌂', $glyphs[0x7F]);
        $this->assertSame('█', $glyphs[0xDB]);
        $this->assertSame(Cp437::glyph(0x1B), $glyphs[0x1B]);
    }

    private function solid(int $char, int $fg, int $bg): AnsiAvatar
    {
        $rows = [];
        for ($row = 0; $row < 6; $row++) {
            $line = [];
            for ($col = 0; $col < 10; $col++) {
                $line[] = [$char, $fg, $bg];
            }
            $rows[] = $line;
        }
        return AnsiAvatar::fromCells($rows);
    }

    private function makeUser(PDO $pdo, string $label): int
    {
        $suffix = bin2hex(random_bytes(4));
        $username = 'ansi' . $label . $suffix;
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, real_name, password_hash, email, is_active)
             VALUES (?, ?, ?, ?, TRUE) RETURNING id'
        );
        $stmt->execute([
            $username,
            'Ansi ' . $label . ' ' . $suffix,
            password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
            $username . '@example.test',
        ]);
        $id = (int)$stmt->fetchColumn();
        $this->createdUsers[] = $id;
        return $id;
    }
}
