<?php

/**
 * PHPUnit tests for MessageHandler::buildModerationVisibilityFilter().
 *
 * buildModerationVisibilityFilter() is a pure SQL-fragment builder — it has no
 * database I/O, so we can exercise it with a stub MessageHandler that skips
 * the constructor's database wiring.
 *
 * Run: php vendor/bin/phpunit tests/Unit/MessageHandlerModerationFilterTest.php
 */

use BinktermPHP\MessageHandler;
use PHPUnit\Framework\TestCase;

class MessageHandlerModerationFilterTest extends TestCase
{
    private MessageHandler $handler;

    protected function setUp(): void
    {
        // Bypass the real constructor (which requires a live DB + logger)
        // so we can test the pure logic of buildModerationVisibilityFilter().
        $this->handler = (new \ReflectionClass(MessageHandler::class))
            ->newInstanceWithoutConstructor();
    }

    // ── Unauthenticated / null user ────────────────────────────────────────────

    public function testNullUserSeesOnlyApproved(): void
    {
        $result = $this->handler->buildModerationVisibilityFilter(null);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('sql', $result);
        $this->assertArrayHasKey('params', $result);

        $this->assertStringContainsString("moderation_status = 'approved'", $result['sql']);
        $this->assertEmpty($result['params']);
    }

    public function testZeroUserIdSeesOnlyApproved(): void
    {
        // 0 is falsy, treated the same as unauthenticated
        $result = $this->handler->buildModerationVisibilityFilter(0);

        $this->assertStringContainsString("moderation_status = 'approved'", $result['sql']);
        $this->assertEmpty($result['params']);
    }

    // ── Authenticated user ─────────────────────────────────────────────────────

    public function testAuthenticatedUserSeesApprovedAndOwnPending(): void
    {
        $result = $this->handler->buildModerationVisibilityFilter(42);

        $this->assertIsArray($result);
        $this->assertStringContainsString("moderation_status = 'approved'", $result['sql']);
        $this->assertStringContainsString("moderation_status = 'pending'", $result['sql']);
        $this->assertStringContainsString('user_id = ?', $result['sql']);
        $this->assertSame([42], $result['params']);
    }

    public function testAuthenticatedUserParamsContainUserId(): void
    {
        $result = $this->handler->buildModerationVisibilityFilter(99);
        $this->assertCount(1, $result['params']);
        $this->assertSame(99, $result['params'][0]);
    }

    // ── Rejected messages never visible ───────────────────────────────────────

    public function testRejectedNotMentionedForAnonymousUser(): void
    {
        $result = $this->handler->buildModerationVisibilityFilter(null);
        $this->assertStringNotContainsString('rejected', $result['sql']);
    }

    public function testRejectedNotMentionedForAuthenticatedUser(): void
    {
        $result = $this->handler->buildModerationVisibilityFilter(1);
        $this->assertStringNotContainsString('rejected', $result['sql']);
    }

    // ── Custom table alias ─────────────────────────────────────────────────────

    public function testDefaultAliasIsEm(): void
    {
        $result = $this->handler->buildModerationVisibilityFilter(null);
        $this->assertStringContainsString('em.moderation_status', $result['sql']);
    }

    public function testCustomAliasIsUsed(): void
    {
        $result = $this->handler->buildModerationVisibilityFilter(null, 'msg');
        $this->assertStringContainsString('msg.moderation_status', $result['sql']);
        $this->assertStringNotContainsString('em.moderation_status', $result['sql']);
    }

    public function testCustomAliasWithAuthenticatedUser(): void
    {
        $result = $this->handler->buildModerationVisibilityFilter(7, 'x');
        $this->assertStringContainsString('x.moderation_status', $result['sql']);
        $this->assertStringContainsString('x.user_id', $result['sql']);
    }

    // ── SQL fragment is safely appendable ─────────────────────────────────────

    public function testSqlFragmentStartsWithAnd(): void
    {
        $nullResult = $this->handler->buildModerationVisibilityFilter(null);
        $authResult = $this->handler->buildModerationVisibilityFilter(5);

        $this->assertStringStartsWith(' AND ', trim($nullResult['sql'], ' ') === $nullResult['sql']
            ? $nullResult['sql']
            : ' ' . ltrim($nullResult['sql']));
        $this->assertMatchesRegularExpression('/^\s*AND\s+/', $nullResult['sql']);
        $this->assertMatchesRegularExpression('/^\s*AND\s+/', $authResult['sql']);
    }

    // ── Return structure ───────────────────────────────────────────────────────

    public function testReturnHasSqlAndParamsKeys(): void
    {
        foreach ([null, 0, 1, 123] as $userId) {
            $result = $this->handler->buildModerationVisibilityFilter($userId);
            $this->assertArrayHasKey('sql', $result, "Missing 'sql' key for userId={$userId}");
            $this->assertArrayHasKey('params', $result, "Missing 'params' key for userId={$userId}");
            $this->assertIsString($result['sql'], "'sql' must be a string for userId={$userId}");
            $this->assertIsArray($result['params'], "'params' must be an array for userId={$userId}");
        }
    }

    // ── Boundary: user ID 1 (first real user) ─────────────────────────────────

    public function testUserIdOneIsHandledCorrectly(): void
    {
        $result = $this->handler->buildModerationVisibilityFilter(1);
        $this->assertSame([1], $result['params']);
        $this->assertStringContainsString('user_id = ?', $result['sql']);
    }
}