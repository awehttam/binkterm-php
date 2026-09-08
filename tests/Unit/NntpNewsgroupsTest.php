<?php

declare(strict_types=1);

use BinktermPHP\NetworkManager;
use BinktermPHP\Nntp\NntpConfig;
use BinktermPHP\Nntp\NntpNewsgroups;
use BinktermPHP\Nntp\NntpSession;
use PHPUnit\Framework\TestCase;

final class NntpNewsgroupsTest extends TestCase
{
    private function newsgroups(string $prefixMode = 'network_name'): NntpNewsgroups
    {
        $db = new PDO('sqlite::memory:');

        $config = NntpConfig::fromArray(['newsgroup_prefix_mode' => $prefixMode]);

        $networks = new class ($db) extends NetworkManager {
            public function getByDomain(string $domain): ?array
            {
                $map = [
                    'lovlynet' => ['name' => 'LovlyNet'],
                    'fidonet' => ['name' => 'FidoNet'],
                    'weird net' => ['name' => 'Weird Net!'],
                ];
                return $map[strtolower($domain)] ?? null;
            }
        };

        return new NntpNewsgroups($db, $config, $networks);
    }

    public function testNetworkNamePrefix(): void
    {
        $g = $this->newsgroups();
        self::assertSame('LovlyNet.LVLY_BINKTERMPHP', $g->groupNameForArea(['tag' => 'LVLY_BINKTERMPHP', 'domain' => 'lovlynet']));
        self::assertSame('FidoNet.GENERAL', $g->groupNameForArea(['tag' => 'GENERAL', 'domain' => 'fidonet']));
    }

    public function testPrefixSanitizesPunctuationAndSpaces(): void
    {
        $g = $this->newsgroups();
        self::assertSame('WeirdNet.TEST', $g->groupNameForArea(['tag' => 'TEST', 'domain' => 'weird net']));
    }

    public function testDomainPrefixMode(): void
    {
        $g = $this->newsgroups('domain');
        self::assertSame('fidonet.GENERAL', $g->groupNameForArea(['tag' => 'GENERAL', 'domain' => 'fidonet']));
    }

    public function testLocalAreaUsesLocalPrefix(): void
    {
        $g = $this->newsgroups();
        self::assertSame('Local.ANNOUNCEMENTS', $g->groupNameForArea(['tag' => 'ANNOUNCEMENTS', 'domain' => '', 'is_local' => true]));
    }

    public function testInvalidTagYieldsNull(): void
    {
        $g = $this->newsgroups();
        self::assertNull($g->groupNameForArea(['tag' => 'Q&A', 'domain' => 'fidonet']));
        self::assertNull($g->groupNameForArea(['tag' => '', 'domain' => 'fidonet']));
        self::assertNull($g->groupNameForArea(['tag' => 'has space', 'domain' => 'fidonet']));
    }

    public function testUnknownDomainFallsBackToSanitizedDomain(): void
    {
        $g = $this->newsgroups();
        self::assertSame('othernet.CHAT', $g->groupNameForArea(['tag' => 'CHAT', 'domain' => 'othernet']));
    }

    public function testWildmatMatching(): void
    {
        self::assertTrue(NntpSession::wildmat('*', 'FidoNet.GENERAL'));
        self::assertTrue(NntpSession::wildmat('FidoNet.*', 'FidoNet.GENERAL'));
        self::assertFalse(NntpSession::wildmat('LovlyNet.*', 'FidoNet.GENERAL'));
        self::assertFalse(NntpSession::wildmat('*,!FidoNet.*', 'FidoNet.GENERAL'));
        self::assertTrue(NntpSession::wildmat('*,!LovlyNet.*', 'FidoNet.GENERAL'));
    }

    public function testParseSinceAcceptsEightAndSixDigitDates(): void
    {
        $a = NntpSession::parseSince(['20260215', '120000', 'GMT']);
        self::assertSame(gmmktime(12, 0, 0, 2, 15, 2026), $a);

        $b = NntpSession::parseSince(['260215', '000000']);
        self::assertSame(gmmktime(0, 0, 0, 2, 15, 2026), $b);

        self::assertNull(NntpSession::parseSince(['bad', '120000']));
        self::assertNull(NntpSession::parseSince(['20260215']));
    }
}
