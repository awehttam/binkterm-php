<?php

declare(strict_types=1);

use BinktermPHP\TelnetServer\BbsSession;
use PHPUnit\Framework\TestCase;

// telnet/src/ classes are not Composer-autoloaded (see telnet/CLAUDE.md).
require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';
require_once __DIR__ . '/../../telnet/src/TerminalBoxRenderer.php';
require_once __DIR__ . '/../../telnet/src/BbsSession.php';

/**
 * Key decoding in BbsSession::readRawChar() / readKeyWithTimeout() for the
 * sequences real terminals send:
 *  - parametrised cursor keys (ESC[1D, ESC[1;5D) decode like the bare arrow;
 *  - SS3 / application cursor-key mode (ESC O A..D, H, F) decodes as arrows;
 *  - bracketed-paste markers (ESC[200~ / ESC[201~) are protocol chatter, so
 *    the pasted text flows through and no empty "cancel" token appears;
 *  - a standalone ESC is reported as the 'ESC' token;
 *  - device reports (cursor position report) remain chatter.
 */
final class TerminalKeyDecodingTest extends TestCase
{
    public function testParametrisedCursorKeysDecodeAsArrows(): void
    {
        self::assertSame(['LEFT', 'LEFT', 'RIGHT', 'UP', 'HOME', 'END'], $this->keys("\033[1D\033[1;5D\033[1C\033[1;2A\033[1H\033[1F"));
    }

    public function testSs3ApplicationCursorKeysDecodeAsArrows(): void
    {
        self::assertSame(['UP', 'DOWN', 'RIGHT', 'LEFT', 'HOME', 'END'], $this->keys("\033OA\033OB\033OC\033OD\033OH\033OF"));
    }

    public function testBracketedPasteMarkersAreChatterAndPastedTextFlowsThrough(): void
    {
        self::assertSame(['CHAR:h', 'CHAR:i'], $this->keys("\033[200~hi\033[201~"));
    }

    public function testStandaloneEscIsReportedAsEscToken(): void
    {
        self::assertSame(['ESC'], $this->keys("\033"));
    }

    public function testCursorPositionReportIsStillChatter(): void
    {
        self::assertSame(['CHAR:x'], $this->keys("\033[24;80Rx"));
    }

    public function testChatterIsASoftTimeoutWithoutCountingAsActivity(): void
    {
        [$session, $server, $client] = $this->wire();
        $state = $this->state();
        $state['last_activity'] = time() - 5;
        fwrite($client, "\033[200~");

        $result = $session->readKeyWithTimeout($server, $state, 500);

        self::assertSame(['', true, false], $result);
        self::assertLessThan(time() - 4, $state['last_activity'], 'chatter must not refresh last_activity');
    }

    /** @return list<string> non-empty tokens decoded from $bytes */
    private function keys(string $bytes): array
    {
        [$session, $server, $client] = $this->wire();
        $state = $this->state();
        fwrite($client, $bytes);

        $tokens = [];
        for ($i = 0; $i < 40; $i++) {
            [$key, $timedOut] = $session->readKeyWithTimeout($server, $state, 150);
            if ($key === null) {
                break;
            }
            if ($key !== '') {
                $tokens[] = $key;
            } elseif (!$timedOut) {
                $tokens[] = '(empty)';
            }
            $read = [$server];
            $w = $e = null;
            if (($state['pushback'] ?? '') === '' && @stream_select($read, $w, $e, 0, 100000) < 1) {
                break;
            }
        }

        return $tokens;
    }

    private function wire(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false) {
            self::markTestSkipped('stream_socket_pair() unavailable');
        }
        [$server, $client] = $pair;
        stream_set_blocking($server, true);
        stream_set_timeout($server, 2);
        stream_set_blocking($client, false);

        return [new BbsSession($server, 'http://127.0.0.1', false, false, false, false), $server, $client];
    }

    private function state(): array
    {
        return [
            'telnet_mode' => null,
            'input_echo' => true,
            'cols' => 80,
            'rows' => 24,
            'last_activity' => time(),
            'idle_warned' => false,
            'idle_warning_timeout' => 300,
            'idle_disconnect_timeout' => 420,
            'pushback' => '',
            'locale' => 'en',
        ];
    }
}
