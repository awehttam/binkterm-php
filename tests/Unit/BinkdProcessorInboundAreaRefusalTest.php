<?php

declare(strict_types=1);

use BinktermPHP\BinkdProcessor;
use PHPUnit\Framework\TestCase;

/**
 * Inbound echomail must only be stored in a network echo area of its own
 * network. When the network domain could not be resolved, the area lookup
 * would otherwise match (or auto-create) a domain-less area - which is how
 * local areas are stored - and a matching local area must never receive
 * network mail. A tag that matches more than one area (domains differing
 * only by case) is ambiguous and is refused too.
 */
final class BinkdProcessorInboundAreaRefusalTest extends TestCase
{
    public function testUnresolvedDomainIsRefusedWithoutTouchingTheDatabase(): void
    {
        $db = new InboundAreaPdo(['id' => 1, 'tag' => 'GENERAL', 'domain' => null, 'is_local' => true]);
        [$area, $refusal] = $this->resolve($db, 'general', null);

        self::assertNull($area);
        self::assertSame('network domain could not be resolved', $refusal);
        self::assertSame([], $db->queries, 'no lookup or auto-create for an unresolved domain');
    }

    public function testLocalAreaMatchIsRefused(): void
    {
        [$area, $refusal] = $this->resolve(new InboundAreaPdo(['id' => 2, 'tag' => 'GENERAL', 'domain' => 'examplenet', 'is_local' => true]), 'GENERAL', 'examplenet');

        self::assertNull($area);
        self::assertStringContainsString('local area', (string)$refusal);
    }

    public function testNetworkAreaIsReturned(): void
    {
        $row = ['id' => 3, 'tag' => 'GENERAL', 'domain' => 'examplenet', 'is_local' => false];
        [$area, $refusal] = $this->resolve(new InboundAreaPdo($row), 'general', 'ExampleNet');

        self::assertSame($row, $area);
        self::assertNull($refusal);
    }

    public function testAmbiguousMatchIsRefused(): void
    {
        $rows = [
            ['id' => 4, 'tag' => 'GENERAL', 'domain' => 'examplenet', 'is_local' => false],
            ['id' => 5, 'tag' => 'GENERAL', 'domain' => 'ExampleNet', 'is_local' => false],
        ];
        [$area, $refusal] = $this->resolve(new InboundAreaPdo($rows[0], $rows), 'GENERAL', 'examplenet');

        self::assertNull($area);
        self::assertStringContainsString('2 areas match', (string)$refusal);
    }

    public function testStorePathDropsAndLogsARefusedMessage(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../../src/BinkdProcessor.php');

        self::assertStringContainsString('$echoarea = $this->getOrCreateEchoarea($echoareaTag, $domain, $refusal);', $source);
        self::assertStringContainsString('[BINKD] Dropping echomail AREA:{$echoareaTag}', $source);
    }

    private function resolve(InboundAreaPdo $db, string $tag, ?string $domain): array
    {
        $processor = (new ReflectionClass(BinkdProcessor::class))->newInstanceWithoutConstructor();
        $property = new ReflectionProperty(BinkdProcessor::class, 'db');
        $property->setAccessible(true);
        $property->setValue($processor, $db);

        $method = new ReflectionMethod(BinkdProcessor::class, 'getOrCreateEchoarea');
        $method->setAccessible(true);
        $refusal = 'unset';
        $args = [$tag, $domain, &$refusal];
        $area = $method->invokeArgs($processor, $args);

        return [$area, $refusal];
    }
}

final class InboundAreaPdo extends PDO
{
    /** @var list<string> */
    public array $queries = [];

    public function __construct(private array $row, private ?array $rows = null)
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;

        return new InboundAreaStatement($this->row, $this->rows ?? [$this->row]);
    }
}

final class InboundAreaStatement extends PDOStatement
{
    public function __construct(private array $row, private array $rows)
    {
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->row;
    }
}
