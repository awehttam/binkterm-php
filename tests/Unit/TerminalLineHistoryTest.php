<?php

declare(strict_types=1);

require_once __DIR__ . '/../../telnet/src/TerminalLineHistory.php';

use BinktermPHP\TelnetServer\TerminalLineHistory;
use PHPUnit\Framework\TestCase;

final class TerminalLineHistoryTest extends TestCase
{
    public function testRecordsNewestLastPerKey(): void
    {
        $state = [];
        TerminalLineHistory::push($state, 'search', 'first');
        TerminalLineHistory::push($state, 'search', 'second');
        TerminalLineHistory::push($state, 'recipient', 'alice');

        self::assertSame(['first', 'second'], TerminalLineHistory::entries($state, 'search'));
        self::assertSame(['alice'], TerminalLineHistory::entries($state, 'recipient'));
    }

    public function testBlanksAndImmediateDuplicatesAreIgnored(): void
    {
        $state = [];
        TerminalLineHistory::push($state, 'k', 'x');
        TerminalLineHistory::push($state, 'k', '   ');
        TerminalLineHistory::push($state, 'k', 'x');
        TerminalLineHistory::push($state, 'k', '');

        self::assertSame(['x'], TerminalLineHistory::entries($state, 'k'));
    }

    public function testEmptyKeyNeverStores(): void
    {
        $state = [];
        TerminalLineHistory::push($state, '', 'secret-ish');
        self::assertSame([], TerminalLineHistory::entries($state, ''));
        self::assertArrayNotHasKey('line_history', $state);
    }

    public function testHistoryIsBounded(): void
    {
        $state = [];
        for ($i = 1; $i <= TerminalLineHistory::MAX_ENTRIES + 10; $i++) {
            TerminalLineHistory::push($state, 'k', 'v' . $i);
        }
        $entries = TerminalLineHistory::entries($state, 'k');

        self::assertCount(TerminalLineHistory::MAX_ENTRIES, $entries);
        self::assertSame('v' . (TerminalLineHistory::MAX_ENTRIES + 10), end($entries));
        self::assertSame('v11', $entries[0]);
    }

    public function testStepWalksNewestFirstThenBackToTheDraft(): void
    {
        $state = [];
        foreach (['one', 'two', 'three'] as $v) {
            TerminalLineHistory::push($state, 'k', $v);
        }

        [$i, $v] = TerminalLineHistory::step($state, 'k', 0, 1);   // UP
        self::assertSame([1, 'three'], [$i, $v]);
        [$i, $v] = TerminalLineHistory::step($state, 'k', $i, 1);  // UP
        self::assertSame([2, 'two'], [$i, $v]);
        [$i, $v] = TerminalLineHistory::step($state, 'k', $i, 1);  // UP
        self::assertSame([3, 'one'], [$i, $v]);
        [$i, $v] = TerminalLineHistory::step($state, 'k', $i, 1);  // UP past the oldest — clamp
        self::assertSame([3, 'one'], [$i, $v]);
        [$i, $v] = TerminalLineHistory::step($state, 'k', $i, -1); // DOWN
        self::assertSame([2, 'two'], [$i, $v]);
        [$i, $v] = TerminalLineHistory::step($state, 'k', 1, -1);  // DOWN off the front -> draft
        self::assertSame([0, null], [$i, $v]);
    }

    public function testStepWithNoHistoryReturnsDraft(): void
    {
        self::assertSame([0, null], TerminalLineHistory::step([], 'k', 0, 1));
    }

    public function testHistoryLivesOnlyInTheSuppliedState(): void
    {
        $sessionA = [];
        $sessionB = [];
        TerminalLineHistory::push($sessionA, 'k', 'a-value');

        self::assertSame(['a-value'], TerminalLineHistory::entries($sessionA, 'k'));
        self::assertSame([], TerminalLineHistory::entries($sessionB, 'k'), 'no cross-session leakage');
    }
}
