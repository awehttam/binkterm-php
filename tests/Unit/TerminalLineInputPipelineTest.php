<?php

declare(strict_types=1);

require_once __DIR__ . '/../../telnet/src/TelnetUtils.php';
require_once __DIR__ . '/../../telnet/src/TerminalBoxRenderer.php';
require_once __DIR__ . '/../../telnet/src/BbsSession.php';
require_once __DIR__ . '/../../telnet/src/TerminalLineEditor.php';
require_once __DIR__ . '/../../telnet/src/TerminalLineHistory.php';
require_once __DIR__ . '/../../telnet/src/TerminalShellInterface.php';
require_once __DIR__ . '/../../telnet/src/TuiShell.php';
require_once __DIR__ . '/../../telnet/src/LineShell.php';
require_once __DIR__ . '/../../telnet/src/TerminalShellFactory.php';

use BinktermPHP\TelnetServer\BbsSession;
use BinktermPHP\TelnetServer\LineShell;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end input-pipeline behaviour: real {@see BbsSession} readers driven over
 * a socket pair. Covers the burst / paste / CR-LF / UTF-8 / drain-on-submit
 * correctness the shared line editor and {@see BbsSession::drainPendingInput()}
 * are there to guarantee.
 */
final class TerminalLineInputPipelineTest extends TestCase
{
    /** @var resource */
    private $srv;
    /** @var resource */
    private $cli;
    private BbsSession $bbs;
    private array $state;

    protected function setUp(): void
    {
        [$this->srv, $this->cli] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_blocking($this->srv, false);
        stream_set_blocking($this->cli, false);

        $this->bbs = new BbsSession($this->srv, 'http://127.0.0.1', false, false, false, false);
        $this->state = [
            'input_echo' => true, 'cols' => 80, 'rows' => 24, 'locale' => 'en', 'pushback' => '',
            'last_activity' => time(), 'idle_warned' => false,
            'idle_warning_timeout' => 300, 'idle_disconnect_timeout' => 420,
            'term_shell_mode' => 'line',
        ];
    }

    protected function tearDown(): void
    {
        @fclose($this->srv);
        @fclose($this->cli);
    }

    private function client(string $bytes): void
    {
        fwrite($this->cli, $bytes);
        fflush($this->cli);
    }

    /** Bytes still queued on the server side right now. */
    private function pendingServerBytes(): string
    {
        $out = '';
        while (($chunk = @fread($this->srv, 4096)) !== false && $chunk !== '') {
            $out .= $chunk;
        }

        return $out;
    }

    /** Everything the server has written to the client so far. */
    private function serverOutput(): string
    {
        $out = '';
        while (($chunk = @fread($this->cli, 8192)) !== false && $chunk !== '') {
            $out .= $chunk;
        }

        return $out;
    }

    /** Columns of every "position cursor then show it" escape, in emission order. */
    private function cursorColumns(string $bytes): array
    {
        preg_match_all("/\033\\[\\d+;(\\d+)H\033\\[\\?25h/", $bytes, $m);

        return array_map('intval', $m[1]);
    }

    // ===== BbsSession::readTelnetLine (legacy prompt path: login, registration) =====

    public function testPlainLineReadsUntilTheTerminator(): void
    {
        $this->client("hello\r\n");
        self::assertSame('hello', $this->bbs->readLineWithIdleCheck($this->srv, $this->state));
    }

    public function testUtf8LineIsPreservedNotDropped(): void
    {
        $this->client("caf\u{00E9} \u{4E2D}\u{6587}\r\n");
        self::assertSame("caf\u{00E9} \u{4E2D}\u{6587}", $this->bbs->readLineWithIdleCheck($this->srv, $this->state));
    }

    public function testMultilinePasteDoesNotLeakPastTheFirstLine(): void
    {
        // A paste of three lines into a single prompt: the reader returns the
        // first line and drains the rest so it cannot run against the next
        // screen / prompt / password field.
        $this->client("line one\r\nrm -rf everything\r\nsecretpw\r\n");

        self::assertSame('line one', $this->bbs->readLineWithIdleCheck($this->srv, $this->state));
        self::assertSame('', $this->state['pushback'], 'pushback cleared');
        self::assertSame('', $this->pendingServerBytes(), 'nothing left queued on the socket');
    }

    public function testCrLfDoesNotProduceAPhantomEmptySecondLine(): void
    {
        $this->client("value\r\n");
        self::assertSame('value', $this->bbs->readLineWithIdleCheck($this->srv, $this->state));

        // A second prompt immediately after must block for real input, not eat a
        // stray LF as an empty submit.
        $this->client("second\r\n");
        self::assertSame('second', $this->bbs->readLineWithIdleCheck($this->srv, $this->state));
    }

    public function testBackspaceIsUtf8SafeInTheLegacyReader(): void
    {
        // c-a-f-e-acute, then one backspace, then x, then Enter => cafx
        $this->client("caf\u{00E9}\x7fx\r\n");
        self::assertSame('cafx', $this->bbs->readLineWithIdleCheck($this->srv, $this->state));
    }

    public function testCtrlCDrainsAndReturnsNull(): void
    {
        $this->client("junk\x03more junk queued\r\n");
        self::assertNull($this->bbs->readLineWithIdleCheck($this->srv, $this->state));
        self::assertSame('', $this->pendingServerBytes());
    }

    // ===== readRawChar UTF-8 reassembly =====

    public function testReadRawCharReassemblesAUtf8Codepoint(): void
    {
        $this->client("\u{00E9}");
        $c = $this->bbs->readRawChar($this->srv, $this->state);
        self::assertSame("\u{00E9}", $c);
        self::assertSame(2, strlen((string) $c), 'two bytes, one character');
    }

    public function testReadKeyWithTimeoutEmitsAUtf8CharToken(): void
    {
        $this->client("\u{4E2D}");
        [$key, $timedOut, $disc] = $this->bbs->readKeyWithTimeout($this->srv, $this->state, 50);
        self::assertFalse($timedOut);
        self::assertFalse($disc);
        self::assertSame("CHAR:\u{4E2D}", $key);
    }

    public function testTruncatedUtf8LeadByteDoesNotHang(): void
    {
        // Lead byte with no continuation: return the lead byte alone, fast.
        $this->client("\xC3");
        $t0 = microtime(true);
        $c  = $this->bbs->readRawChar($this->srv, $this->state);
        self::assertLessThan(0.5, microtime(true) - $t0, 'bounded by the 50ms continuation peek');
        self::assertSame("\xC3", $c);
    }

    // ===== bracketed paste (SyncTerm) =====

    public function testBracketedPasteMarkersAreFoldedAwayByReadRawChar(): void
    {
        $this->client("\033[200~");
        self::assertSame("\x00", $this->bbs->readRawChar($this->srv, $this->state));

        $this->client("\033[201~");
        self::assertSame("\x00", $this->bbs->readRawChar($this->srv, $this->state));
    }

    public function testReadKeyWithTimeoutTreatsABracketedPasteMarkerAsChatterNotAKeypress(): void
    {
        $this->client("\033[200~");
        [$key, $timedOut] = $this->bbs->readKeyWithTimeout($this->srv, $this->state, 50);
        // Not an empty 'cancel' token surfaced to a text field — the caller's
        // loop re-reads.
        self::assertTrue($timedOut);
        self::assertSame('', $key);
    }

    public function testReadTelnetLineAcceptsABracketedPaste(): void
    {
        // Exactly what SyncTerm sends on paste: ESC[200~ <text> ESC[201~, then CR.
        $this->client("\033[200~HELLO PASTE\033[201~\r\n");
        self::assertSame('HELLO PASTE', $this->bbs->readLineWithIdleCheck($this->srv, $this->state));
        self::assertSame('', $this->pendingServerBytes());
    }

    public function testReadTelnetLineBracketedPasteFirstLineOnlyNoLeak(): void
    {
        $this->client("\033[200~first line\rSECOND MUST NOT RUN\r\033[201~\r\n");
        self::assertSame('first line', $this->bbs->readLineWithIdleCheck($this->srv, $this->state));
        self::assertSame('', $this->pendingServerBytes(), 'the rest of the paste, and the end marker, are drained');
    }

    // ===== drainPendingInput =====

    public function testDrainPendingInputIsNonBlockingAndClearsQueuedBytes(): void
    {
        $this->client(str_repeat("xyz\r\n", 200));
        $t0 = microtime(true);
        $this->bbs->drainPendingInput($this->srv, $this->state);
        self::assertLessThan(1.0, microtime(true) - $t0);
        self::assertSame('', $this->state['pushback']);
        self::assertSame('', $this->pendingServerBytes());
    }

    public function testDrainDoesNotWaitForInputThatHasNotArrivedYet(): void
    {
        $t0 = microtime(true);
        $this->bbs->drainPendingInput($this->srv, $this->state); // nothing queued
        self::assertLessThan(0.2, microtime(true) - $t0);
    }

    // ===== LineShell::readPromptLine (modern line prompt) =====

    private function lineShell(): LineShell
    {
        return new LineShell($this->bbs);
    }

    private function invokePromptLine(string $prompt, bool $echo): ?string
    {
        $m = new \ReflectionMethod(LineShell::class, 'readPromptLine');
        $m->setAccessible(true);
        $shell = $this->lineShell();
        $srv   = $this->srv;
        $args  = [$srv, &$this->state, $prompt, $echo, null];

        return $m->invokeArgs($shell, $args);
    }

    public function testLineShellPromptIsUtf8SafeAndCrLfClean(): void
    {
        $this->client("caf\u{00E9}\r\n");
        self::assertSame("caf\u{00E9}", $this->invokePromptLine('> ', true));

        $this->client("again\r\n");
        self::assertSame('again', $this->invokePromptLine('> ', true), 'no phantom empty line from the earlier CRLF');
    }

    public function testLineShellPromptDrainsAMultilinePaste(): void
    {
        $this->client("first\nSECOND SHOULD NOT RUN\n");
        self::assertSame('first', $this->invokePromptLine('cmd> ', true));
        self::assertSame('', $this->pendingServerBytes());
        self::assertSame('', $this->state['pushback']);
    }

    public function testLineShellPromptAcceptsABracketedPaste(): void
    {
        $this->client("\033[200~HELLO PASTE\033[201~\r\n");
        self::assertSame('HELLO PASTE', $this->invokePromptLine('> ', true));
        self::assertSame('', $this->pendingServerBytes());
    }

    public function testShowInputDialogAcceptsABracketedPasteAndDoesNotCancel(): void
    {
        // Previously with SyncTerm: ESC[200~ normalised to '' -> the editor
        // treated it as cancel -> the whole paste vanished.
        $this->client("\033[200~search terms here\033[201~\r\n");
        $out = \BinktermPHP\TelnetServer\TelnetUtils::showInputDialog(
            $this->srv,
            $this->state,
            $this->bbs,
            'Search',
            'Find:',
            '',
            60,
            null,
            [],
            []
        );
        self::assertSame('search terms here', $out);
    }

    public function testShowInputDialogBracketedPasteWithEmbeddedNewlineKeepsFirstLineNoLeak(): void
    {
        $this->client("\033[200~line1\rDROP TABLE users\r\033[201~\r\n");
        $out = \BinktermPHP\TelnetServer\TelnetUtils::showInputDialog(
            $this->srv, $this->state, $this->bbs, 'T', 'P', '', 60, null, [], []
        );
        self::assertSame('line1', $out);
        self::assertSame('', $this->pendingServerBytes());
    }

    public function testShowInputDialogLeftArrowInsertsMidLineThroughTheRealParser(): void
    {
        // The exact human assay: type abcdef, Left x3, type x, Enter.
        // Real BbsSession parser: SyncTerm Left = ESC [ D.
        $this->client("abcdef\033[D\033[D\033[Dx\r\n");
        $out = \BinktermPHP\TelnetServer\TelnetUtils::showInputDialog(
            $this->srv, $this->state, $this->bbs, 'Search', 'Find:', '', 60, null, [], []
        );

        self::assertSame('abcxdef', $out, 'Left moved the insertion point; x was inserted mid-line');
    }

    public function testShowInputDialogRendersTheCursorAtTheInsertionPointNotAtEnd(): void
    {
        // Same edit, but no submit: the last redraw before the idle disconnect
        // must park the terminal cursor at the logical column (4 chars in),
        // not at end-of-text (7 chars in). 80x24 layout: box left col 9, input
        // row 12, so "space + 4 chars" -> col 15; end-of-text would be col 18.
        $this->state['last_activity']          = time();
        $this->state['idle_warning_timeout']   = 1;
        $this->state['idle_disconnect_timeout'] = 2;

        $this->client("abcdef\033[D\033[D\033[Dx");
        $out = \BinktermPHP\TelnetServer\TelnetUtils::showInputDialog(
            $this->srv, $this->state, $this->bbs, 'Search', 'Find:', '', 60, null, [], []
        );
        self::assertNull($out, 'idle disconnect returned null (no Enter was sent)');

        $cols = $this->cursorColumns($this->serverOutput());
        self::assertNotEmpty($cols);
        self::assertSame(15, end($cols), 'final cursor column is the insertion point (abcx|def)');
        self::assertNotContains(18, $cols, 'the cursor is never parked at end-of-text after Left');
    }

    public function testShowInputDialogCursorMotionKeysEditMidLine(): void
    {
        // Home to start, Right twice, Delete the 3rd char, End, append '!'.
        // abcdef -> (Home)(Right)(Right) abcdef, (Delete) abdef, (End)(!) abdef!
        $this->client("abcdef\033[H\033[C\033[C\033[3~\033[F!\r\n");
        $out = \BinktermPHP\TelnetServer\TelnetUtils::showInputDialog(
            $this->srv, $this->state, $this->bbs, 'T', 'P', '', 60, null, [], []
        );

        self::assertSame('abdef!', $out);
        self::assertSame('', $this->pendingServerBytes(), 'no input left queued');
    }

    public function testShowInputDialogUtf8MidLineEditIsCodepointSafe(): void
    {
        // caf<e-acute>  ->  Left once (before the accent)  ->  insert 'X'  ->  cafX<e-acute>
        $this->client("caf\u{00E9}\033[DX\r\n");
        $out = \BinktermPHP\TelnetServer\TelnetUtils::showInputDialog(
            $this->srv, $this->state, $this->bbs, 'T', 'P', '', 60, null, [], []
        );

        self::assertSame("cafX\u{00E9}", $out);
    }

    public function testLineShellPasswordPromptMasksAndKeepsNoHistory(): void
    {
        $this->state['line_prompt_history_key'] = 'should_be_ignored_when_sensitive';
        $this->client("hunter2\r\n");
        self::assertSame('hunter2', $this->invokePromptLine('Password: ', false));
        self::assertArrayNotHasKey('line_history', $this->state, 'a masked prompt never records history');

        // Echo must not contain the cleartext password.
        $echo = $this->pendingServerBytes(); // whatever is left (should be nothing)
        self::assertStringNotContainsString('hunter2', $echo);
    }
}
