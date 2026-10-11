<?php

declare(strict_types=1);

require_once __DIR__ . '/../../telnet/src/TerminalLineEditor.php';

use BinktermPHP\TelnetServer\TerminalLineEditor;
use PHPUnit\Framework\TestCase;

/**
 * The shared, UTF-8-safe line-editing contract. Pure state machine, no socket.
 */
final class TerminalLineEditorTest extends TestCase
{
    /** @param string[] $tokens */
    private function feed($e, array $tokens): string
    {
        $last = TerminalLineEditor::RESULT_CONTINUE;
        foreach ($tokens as $t) {
            $last = $e->apply($t);
        }

        return $last;
    }

    /** @param string $text @return string[] one CHAR: token per codepoint */
    private function typed(string $text): array
    {
        return array_map(static fn ($c) => 'CHAR:' . $c, mb_str_split($text, 1, 'UTF-8'));
    }

    public function testOrdinaryTyping(): void
    {
        $e = new TerminalLineEditor();
        $this->feed($e, $this->typed('hello world'));

        self::assertSame('hello world', $e->value());
        self::assertSame(11, $e->cursor());
    }

    public function testBackspaceAndForwardDelete(): void
    {
        $e = new TerminalLineEditor('abcdef', 255, true);
        $this->feed($e, ['END', 'BACKSPACE', 'BACKSPACE']);       // "abcd"
        $this->feed($e, ['HOME', 'DELETE']);                       // "bcd"

        self::assertSame('bcd', $e->value());
        self::assertSame(0, $e->cursor());
    }

    public function testCursorMovementInsertsMidLine(): void
    {
        $e = new TerminalLineEditor('ac', 255, true);
        $this->feed($e, ['LEFT', 'CHAR:b']);

        self::assertSame('abc', $e->value());
        self::assertSame(2, $e->cursor());
    }

    public function testHomeEndAndCtrlAltE(): void
    {
        $e = new TerminalLineEditor('12345', 255, true);
        $e->apply('HOME');
        self::assertSame(0, $e->cursor());
        $e->apply('END');
        self::assertSame(5, $e->cursor());
        $e->apply('CTRL_A');
        self::assertSame(0, $e->cursor());
        $e->apply('CTRL_E');
        self::assertSame(5, $e->cursor());
    }

    public function testCursorClampsAtBothEnds(): void
    {
        $e = new TerminalLineEditor('ab', 255, true);
        $this->feed($e, ['LEFT', 'LEFT', 'LEFT', 'LEFT']);
        self::assertSame(0, $e->cursor());
        $this->feed($e, ['RIGHT', 'RIGHT', 'RIGHT', 'RIGHT']);
        self::assertSame(2, $e->cursor());
    }

    public function testEmptyLineSubmits(): void
    {
        $e = new TerminalLineEditor();
        self::assertSame(TerminalLineEditor::RESULT_SUBMIT, $e->apply('ENTER'));
        self::assertSame('', $e->value());
    }

    public function testEscAndCtrlCCancel(): void
    {
        self::assertSame(TerminalLineEditor::RESULT_CANCEL, (new TerminalLineEditor('x'))->apply('ESC'));
        self::assertSame(TerminalLineEditor::RESULT_CANCEL, (new TerminalLineEditor('x'))->apply(''));
        self::assertSame(TerminalLineEditor::RESULT_CANCEL, (new TerminalLineEditor('x'))->apply('CTRL_C'));
    }

    public function testMaxLengthIsEnforcedOnTypingAndConstruction(): void
    {
        $e = new TerminalLineEditor('abcdef', 3, false);
        self::assertSame('abc', $e->value());
        $this->feed($e, $this->typed('xyz'));
        self::assertSame('abc', $e->value());
    }

    public function testUtf8IsEditedByCodepointNotByte(): void
    {
        $e = new TerminalLineEditor("caf\u{00E9}", 255, true); // c-a-f-e-acute
        self::assertSame(4, $e->cursor(), 'four codepoints, not five bytes');

        $e->apply('BACKSPACE');
        self::assertSame('caf', $e->value(), 'the whole accented char is removed');

        $e2 = new TerminalLineEditor("\u{00E9}\u{00E9}\u{00E9}", 255, true);
        $e2->apply('HOME');
        $e2->apply('RIGHT');
        $e2->apply('CHAR:x');
        self::assertSame("\u{00E9}x\u{00E9}\u{00E9}", $e2->value());
    }

    public function testControlBytesArrivingAsCharAreDropped(): void
    {
        $e = new TerminalLineEditor();
        $this->feed($e, ['CHAR:a', "CHAR:\x07", "CHAR:\x1b", 'CHAR:b', "CHAR:\x00"]);
        self::assertSame('ab', $e->value());
    }

    public function testUnhandledNavigationTokensAreIgnored(): void
    {
        $e = new TerminalLineEditor('ab', 255, true);
        foreach (['UP', 'DOWN', 'TAB', 'PGUP', 'PGDOWN', 'SHIFT_TAB', 'F5'] as $t) {
            self::assertSame(TerminalLineEditor::RESULT_CONTINUE, $e->apply($t));
        }
        self::assertSame('ab', $e->value());
        self::assertSame(2, $e->cursor());
    }

    public function testCursorMovementCanBeDisabled(): void
    {
        $e = new TerminalLineEditor('abc', 255, false);
        $e->apply('LEFT');
        $e->apply('HOME');
        $e->apply('CHAR:x');
        self::assertSame('abcx', $e->value(), 'append-only: LEFT/HOME are no-ops, text lands at the end');
    }

    public function testSetValueReplacesBufferAndParksCursorAtEnd(): void
    {
        $e = new TerminalLineEditor('old text here', 255, true);
        $e->apply('HOME');
        $e->setValue('recalled');
        self::assertSame('recalled', $e->value());
        self::assertSame(8, $e->cursor());
    }
}
