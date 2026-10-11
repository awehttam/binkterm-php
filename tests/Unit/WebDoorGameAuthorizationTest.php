<?php

declare(strict_types=1);

use BinktermPHP\GameConfig;
use BinktermPHP\WebDoorController;
use PHPUnit\Framework\TestCase;

/**
 * The WebDoor API (sessions, saves, leaderboards) must only read or write
 * data for an installed, enabled WebDoor: the caller-supplied game_id (or
 * /webdoors/{id}/ referer) is resolved against the WebDoor manifests and
 * config/webdoors.json, and anything else is refused before any query.
 * Uses the real installed manifests (e.g. wordle) with a controlled
 * GameConfig and a recording PDO stub; no database.
 */
final class WebDoorGameAuthorizationTest extends TestCase
{
    private array $savedStatics = [];
    private array $savedGet = [];
    private ?string $savedReferer = null;

    protected function setUp(): void
    {
        foreach (['config', 'loaded'] as $name) {
            $property = new ReflectionProperty(GameConfig::class, $name);
            $property->setAccessible(true);
            $this->savedStatics[$name] = [$property, $property->getValue()];
        }
        $this->setGameConfig(['wordle' => ['enabled' => true], 'hangman' => ['enabled' => false]]);
        $this->savedGet = $_GET;
        $this->savedReferer = $_SERVER['HTTP_REFERER'] ?? null;
        $_GET = [];
        unset($_SERVER['HTTP_REFERER']);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedStatics as [$property, $value]) {
            $property->setValue(null, $value);
        }
        $_GET = $this->savedGet;
        if ($this->savedReferer === null) {
            unset($_SERVER['HTTP_REFERER']);
        } else {
            $_SERVER['HTTP_REFERER'] = $this->savedReferer;
        }
    }

    public function testEnabledWebDoorIsServedUnderItsCanonicalId(): void
    {
        $_GET['game_id'] = 'wordle';
        [$controller, $pdo] = $this->controller();

        $result = $controller->listSaves();

        self::assertArrayHasKey('slots', $result);
        self::assertSame([[7, 'wordle'], [7, 'wordle']], $pdo->params);
    }

    public function testRefererIdentifiesTheGameToo(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://bbs.example/webdoors/wordle/index.html';
        [$controller, $pdo] = $this->controller();

        self::assertArrayHasKey('slots', $controller->listSaves());
        self::assertSame('wordle', $pdo->params[0][1]);
    }

    /**
     * @dataProvider refusedGameIds
     */
    public function testUnknownDisabledOrMissingGamesAreRefusedBeforeAnyQuery(?string $gameId): void
    {
        if ($gameId !== null) {
            $_GET['game_id'] = $gameId;
        }

        foreach (['listSaves', 'getSession'] as $method) {
            [$controller, $pdo] = $this->controller();
            $result = $controller->$method();
            self::assertFalse($result['success'], "{$method}({$gameId})");
            self::assertSame('errors.webdoor.game_unavailable', $result['error_code']);
            self::assertSame([], $pdo->queries, "{$method} must not touch the database");
        }
        foreach ([fn ($c) => $c->loadSave(1), fn ($c) => $c->saveGame(1), fn ($c) => $c->deleteSave(1),
                  fn ($c) => $c->getLeaderboard('high'), fn ($c) => $c->submitScore('high')] as $call) {
            [$controller, $pdo] = $this->controller();
            $result = $call($controller);
            self::assertSame('errors.webdoor.game_unavailable', $result['error_code'] ?? null);
            self::assertSame([], $pdo->queries);
        }
    }

    public static function refusedGameIds(): array
    {
        return [
            'not installed' => ['no-such-game'],
            'disabled in webdoors.json' => ['hangman'],
            'installed but not configured' => ['blackjack'],
            'no game id at all' => [null],
            'path traversal' => ['../wordle'],
        ];
    }

    private function controller(): array
    {
        $controller = (new ReflectionClass(WebDoorController::class))->newInstanceWithoutConstructor();
        $pdo = new WebDoorAuthorizationPdo();
        $auth = new class {
            public function getCurrentUser(): array
            {
                return ['user_id' => 7, 'username' => 'alice'];
            }
        };
        foreach (['db' => $pdo, 'auth' => $auth] as $name => $value) {
            $property = new ReflectionProperty(WebDoorController::class, $name);
            $property->setAccessible(true);
            $property->setValue($controller, $value);
        }

        return [$controller, $pdo];
    }

    private function setGameConfig(array $config): void
    {
        $this->savedStatics['config'][0]->setValue(null, $config);
        $this->savedStatics['loaded'][0]->setValue(null, true);
    }
}

final class WebDoorAuthorizationPdo extends PDO
{
    /** @var list<string> */
    public array $queries = [];
    /** @var list<array> */
    public array $params = [];

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;
        return new WebDoorAuthorizationStatement($this);
    }
}

final class WebDoorAuthorizationStatement extends PDOStatement
{
    public function __construct(private WebDoorAuthorizationPdo $pdo)
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->pdo->params[] = $params ?? [];
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return [];
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return ['total_bytes' => 0];
    }
}
