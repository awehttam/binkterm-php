<?php

declare(strict_types=1);

use BinktermPHP\MessageHandler;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the centralized netmail visibility predicates
 * (MessageHandler::netmailVisibilityClause() / netmailNotDeletedClause()).
 *
 * These build the SQL fragment + params that scope every netmail query to the
 * rows a user may see. They are pure string/array builders — no DB — so the
 * handler is created without its constructor and the private methods are called
 * via reflection.
 */
final class NetmailVisibilityTest extends TestCase
{
    private MessageHandler $handler;

    /** @var array<string,mixed> */
    private array $user = ['id' => 7, 'username' => 'alice', 'real_name' => 'Alice Example'];

    protected function setUp(): void
    {
        $this->handler = (new ReflectionClass(MessageHandler::class))->newInstanceWithoutConstructor();
    }

    /**
     * @param array<int,mixed> $args
     * @return array{sql:string,params:array<int,mixed>}
     */
    private function call(string $method, array $args): array
    {
        $ref = new ReflectionMethod(MessageHandler::class, $method);
        $ref->setAccessible(true);

        /** @var array{sql:string,params:array<int,mixed>} $out */
        $out = $ref->invokeArgs($this->handler, $args);

        return $out;
    }

    public function testEitherSideMatchesUserIdOrRecipientIdentity(): void
    {
        $r = $this->call('netmailVisibilityClause', [$this->user, ['21:1/100', '21:1/100.1'], 'either', 'n']);

        self::assertStringContainsString('n.user_id = ?', $r['sql']);
        self::assertStringContainsString('LOWER(n.to_name)', $r['sql']);
        self::assertStringContainsString('n.to_address IN (?,?)', $r['sql']);
        self::assertSame([7, 'alice', 'Alice Example', '21:1/100', '21:1/100.1'], $r['params']);
    }

    public function testEitherSideFallsBackToUserIdWhenNoAddresses(): void
    {
        $r = $this->call('netmailVisibilityClause', [$this->user, [], 'either', 'n']);

        self::assertSame('n.user_id = ?', $r['sql']);
        self::assertSame([7], $r['params']);
    }

    public function testRecipientSideGuardsTheUserIdBranchAgainstOwnSentMail(): void
    {
        $r = $this->call('netmailVisibilityClause', [$this->user, ['21:1/100'], 'recipient', 'n']);

        // user_id branch must exclude rows the user themselves originated
        self::assertStringContainsString('n.user_id = ? AND NOT ((LOWER(n.from_name)', $r['sql']);
        self::assertStringContainsString('n.from_address IN (?)', $r['sql']);
        self::assertStringContainsString('n.to_address IN (?)', $r['sql']);
        self::assertSame(
            [7, 'alice', 'Alice Example', '21:1/100', 'alice', 'Alice Example', '21:1/100'],
            $r['params']
        );
    }

    public function testSenderSideMatchesFromIdentity(): void
    {
        $r = $this->call('netmailVisibilityClause', [$this->user, ['21:1/100'], 'sender', 'n']);

        self::assertStringContainsString('LOWER(n.from_name)', $r['sql']);
        self::assertStringContainsString('n.from_address IN (?)', $r['sql']);
        self::assertStringNotContainsString('user_id', $r['sql']);
        self::assertSame(['alice', 'Alice Example', '21:1/100'], $r['params']);
    }

    public function testNotDeletedClauseIsPerSide(): void
    {
        $r = $this->call('netmailNotDeletedClause', [$this->user, 'n']);

        self::assertStringContainsString('n.user_id = ? AND n.deleted_by_sender = TRUE', $r['sql']);
        self::assertStringContainsString('n.deleted_by_recipient = TRUE', $r['sql']);
        self::assertSame([7, 'alice', 'Alice Example'], $r['params']);
    }

    public function testAliasIsHonoured(): void
    {
        $r = $this->call('netmailVisibilityClause', [$this->user, ['21:1/100'], 'either', 'nm']);

        self::assertStringContainsString('nm.user_id = ?', $r['sql']);
        self::assertStringNotContainsString('n.user_id', $r['sql']);
    }
}
