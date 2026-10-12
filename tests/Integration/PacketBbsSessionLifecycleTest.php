<?php

use BinktermPHP\BbsConfig;
use BinktermPHP\Config;
use BinktermPHP\Database;
use BinktermPHP\Binkp\Logger;
use BinktermPHP\PacketBbs\PacketBbsGateway;
use BinktermPHP\PacketBbs\PacketBbsSession;
use BinktermPHP\PacketBbs\PacketBbsTotp;
use BinktermPHP\PacketBbs\PacketBbsChatNotifier;
use PHPUnit\Framework\TestCase;

/** Opt-in PostgreSQL tests. All fixtures are temporary; never load the application DB config. */
class PacketBbsSessionLifecycleTest extends TestCase
{
    private PDO $db;
    private PacketBbsSession $repo;
    private PacketBbsGateway $gateway;
    private array $saved = [];

    protected function setUp(): void
    {
        $dsn = getenv('PACKETBBS_TEST_DSN');
        if (!$dsn) {
            $this->markTestSkipped('Set PACKETBBS_TEST_DSN to an isolated PostgreSQL database.');
        }
        $this->db = new PDO($dsn, getenv('PACKETBBS_TEST_USER') ?: 'postgres', getenv('PACKETBBS_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec("SET search_path TO pg_temp; SET timezone TO 'UTC'");
        $this->db->exec(<<<'SQL'
CREATE TEMP TABLE packet_bbs_sessions (
 node_id text PRIMARY KEY, bridge_node_id text, user_id int, bbs_session_id text,
 menu_state text DEFAULT 'main', pagination_cursor int DEFAULT 1, pagination_context text,
 compose_buffer text, compose_type text, compose_meta jsonb, session_state jsonb DEFAULT '{}',
 last_activity_at timestamptz DEFAULT NOW(), created_at timestamptz DEFAULT NOW()
);
CREATE TEMP TABLE packet_bbs_nodes (node_id text PRIMARY KEY, last_seen_at timestamptz);
CREATE TEMP TABLE user_sessions (session_id text PRIMARY KEY, user_id int, expires_at timestamptz,
 ip_address text, user_agent text, last_activity timestamptz, service text, activity text);
CREATE TEMP TABLE packet_bbs_outbound_queue (id serial PRIMARY KEY, node_id text, payload text, sent_at timestamptz);
CREATE TEMP TABLE packet_bbs_login_attempts (node_id text, username text, success boolean, attempted_at timestamptz DEFAULT NOW());
CREATE TEMP TABLE users (id int PRIMARY KEY, username text, is_active boolean, real_name text, email text,
 is_admin boolean DEFAULT FALSE, manage_hub_point boolean DEFAULT FALSE, password_hash text, created_at timestamptz,
 last_login timestamptz, location text, about_me text, fidonet_address text);
CREATE TEMP TABLE users_meta (user_id int, keyname text, valname text);
CREATE TEMP TABLE totp_used_codes (user_id int, step bigint, used_at timestamptz DEFAULT NOW(), UNIQUE(user_id, step));
CREATE TEMP TABLE bulletins (id int, is_active boolean, active_from timestamptz, active_until timestamptz, sort_order int);
CREATE TEMP TABLE bulletin_reads (bulletin_id int, user_id int);
INSERT INTO packet_bbs_nodes VALUES ('bridge', NULL);
INSERT INTO users VALUES (1, 'alice', TRUE);
SQL);
        $database = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($database, $this->db);
        foreach ([[Database::class, 'instance', $database], [BbsConfig::class, 'loaded', true],
            [BbsConfig::class, 'config', ['packet_bbs' => ['session_timeout_minutes' => 15]]],
            [Config::class, 'loaded', true]] as [$class, $name, $value]) {
            $property = new ReflectionProperty($class, $name);
            $this->saved[] = [$property, $property->getValue()];
            $property->setValue(null, $value);
        }
        $this->repo = new PacketBbsSession($this->db);
        $this->gateway = (new ReflectionClass(PacketBbsGateway::class))->newInstanceWithoutConstructor();
        $logger = $this->getMockBuilder(Logger::class)->disableOriginalConstructor()->onlyMethods(['info', 'warning'])->getMock();
        foreach (['db' => $this->db, 'sessionRepo' => $this->repo, 'logger' => $logger] as $name => $value) {
            (new ReflectionProperty($this->gateway, $name))->setValue($this->gateway, $value);
        }
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->saved) as [$property, $value]) {
            $property->setValue(null, $value);
        }
    }

    private function seed(int $age, ?int $userId = 1): string
    {
        $this->repo->getOrCreate('sender', 'bridge');
        $this->repo->update('sender', [
            'user_id' => $userId, 'bbs_session_id' => $userId ? 'old-session' : null,
            'menu_state' => $userId ? 'compose_echomail' : 'main',
            'compose_buffer' => 'private draft', 'compose_type' => 'echomail',
            'compose_meta' => ['subject' => 'private'], 'pagination_cursor' => 3,
            'pagination_context' => '{}', 'session_state' => ['current_area' => ['tag' => 'TEST'], 'active_flow' => ['type' => 'post']],
        ]);
        // A real linked row: Auth::validateSession() requires an unexpired session.
        if ($userId) $this->db->exec("INSERT INTO user_sessions (session_id, user_id, expires_at) VALUES ('old-session', 1, NOW() + INTERVAL '1 day')");
        $this->db->prepare("UPDATE packet_bbs_sessions SET last_activity_at = NOW() - INTERVAL '1 second' * ?")->execute([$age]);
        return $this->raw()['last_activity_at'];
    }

    private function raw(): array
    {
        return $this->db->query("SELECT * FROM packet_bbs_sessions WHERE node_id = 'sender'")->fetch(PDO::FETCH_ASSOC);
    }

    private function command(string $command): string
    {
        return $this->gateway->handleCommand('sender', 'meshcore', $command, 'bridge');
    }

    public function testQuitDiscardsPendingPrivateContentButKeepsDeliveredHistory(): void
    {
        $this->seed(600);
        $this->db->exec("INSERT INTO packet_bbs_outbound_queue (node_id, payload, sent_at) VALUES
            ('sender', 'private A', NULL), ('sender', 'delivered', NOW()), ('another', 'unrelated', NULL)");
        $this->repo->destroy('sender');
        $this->assertNull($this->repo->load('sender'));
        $this->assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM user_sessions')->fetchColumn());
        $this->assertSame(['delivered', 'unrelated'], $this->db->query('SELECT payload FROM packet_bbs_outbound_queue ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame([], $this->gateway->getPendingMessages('sender'));
    }

    public function testLoginAsAnotherUserCannotReceivePriorUsersQueuedContent(): void
    {
        $this->seed(600);
        $this->db->exec("UPDATE packet_bbs_sessions SET menu_state = 'main', session_state = '{}'");
        $this->db->exec("INSERT INTO packet_bbs_outbound_queue (node_id, payload) VALUES ('sender', 'private A')");
        $this->db->exec("INSERT INTO users VALUES (2, 'bob', TRUE)");
        $stmt = $this->db->prepare('INSERT INTO users_meta VALUES (2, ?, ?)');
        $stmt->execute(['packet_bbs_totp_enabled', '1']);
        $stmt->execute(['packet_bbs_totp_secret', 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ']);
        $code = (new ReflectionMethod(PacketBbsTotp::class, 'computeHotp'))->invoke(null, '12345678901234567890', (int)floor(time() / 30));
        $this->assertStringContainsString('Hi bob.', $this->command('L bob ' . $code));
        $this->assertSame(2, $this->raw()['user_id']);
        $this->assertSame([], $this->gateway->getPendingMessages('sender'));
    }

    public function testLegacyBindingIsClaimedByFirstCommandWithoutRefreshingActivity(): void
    {
        $before = $this->seed(600);
        $this->db->exec("UPDATE packet_bbs_sessions SET bridge_node_id = NULL");
        $session = $this->repo->getOrCreate('sender', 'bridge');
        $this->assertSame('bridge', $session['bridge_node_id']);
        $this->assertSame($before, $session['last_activity_at']);
        $this->assertNull($this->repo->getOrCreate('sender', 'other-bridge'));
        $this->assertFalse($this->repo->isBridgeAuthorized('sender', 'other-bridge'));
        $this->assertSame($before, $this->raw()['last_activity_at']);
    }

    public function testLegacyBindingIsClaimedByFirstPollAndRejectsOtherBridges(): void
    {
        $before = $this->seed(600);
        $this->db->exec("UPDATE packet_bbs_sessions SET bridge_node_id = ''");
        $this->assertTrue($this->repo->isBridgeAuthorized('sender', 'bridge'));
        $this->assertSame('bridge', $this->raw()['bridge_node_id']);
        $this->assertFalse($this->repo->isBridgeAuthorized('sender', 'other-bridge'));
        $this->assertNull($this->repo->getOrCreate('sender', 'other-bridge'));
        $this->assertSame($before, $this->raw()['last_activity_at']);
        $this->assertTrue($this->repo->isBridgeAuthorized('fresh-sender', 'other-bridge'));
    }

    public function testExpiredLookupRevokesIdentityAndDraftWithoutRefreshingOrLosingNotice(): void
    {
        $before = $this->seed(960);
        foreach ([$this->repo->getOrCreate('sender', 'bridge'), $this->repo->load('sender')] as $session) {
            $this->assertSame($before, $session['last_activity_at']);
            $this->assertNull($session['user_id']);
            $this->assertNull($session['bbs_session_id']);
            $this->assertSame('main', $session['menu_state']);
            $this->assertSame(1, $session['pagination_cursor']);
            $this->assertNull($session['pagination_context']);
            $this->assertNull($session['compose_buffer']);
            $this->assertNull($session['compose_type']);
            $this->assertSame([], $session['compose_meta']);
            $this->assertSame(['auth_expired' => true], $session['session_state']);
            $this->assertSame('bridge', $session['bridge_node_id']);
        }
        $this->assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM user_sessions')->fetchColumn());
    }

    public function testExpiredSubmitIsNotExecutedAndNoticeIsConsumedOnce(): void
    {
        $this->seed(960);
        // No MessageHandler is injected: reaching submission would fail this test.
        $this->assertSame('Session expired. LOGIN again.', $this->command('/SEND'));
        $this->assertSame([], $this->repo->load('sender')['session_state']);
        $this->assertStringContainsString('LOGIN', $this->command('N'));
        $this->assertNull($this->raw()['user_id']);
    }

    public function testWithinWindowCommandRefreshesAndContinuesDraft(): void
    {
        $before = $this->seed(600);
        $this->assertSame('+', $this->command('another line'));
        $this->assertNotSame($before, $this->raw()['last_activity_at']);
        $this->assertSame(1, $this->raw()['user_id']);
        $this->assertSame("private draftanother line\n", $this->raw()['compose_buffer']);
    }

    public function testOrdinaryAuthenticatedCommandRefreshesActivity(): void
    {
        $before = $this->seed(600);
        $this->assertSame('Send HELP.', $this->command(''));
        $this->assertNotSame($before, $this->raw()['last_activity_at']);
        $this->assertSame(1, $this->raw()['user_id']);
    }

    public function testConfiguredAuthenticationTimeoutIsHonored(): void
    {
        (new ReflectionProperty(BbsConfig::class, 'config'))->setValue(null, [
            'packet_bbs' => ['session_timeout_minutes' => 5],
        ]);
        $this->seed(360);
        $this->assertSame('Session expired. LOGIN again.', $this->command(''));
    }

    public function testRetentionSettingIsIndependentAndCleanupKeepsSixteenMinuteRow(): void
    {
        $before = $this->seed(960);
        $this->repo->cleanExpired();
        $this->assertSame($before, $this->raw()['last_activity_at']);
        $this->assertNull($this->raw()['user_id']);
        $previous = $_ENV['PACKETBBS_SESSION_RETENTION_SECONDS'] ?? null;
        try {
            $_ENV['PACKETBBS_SESSION_RETENTION_SECONDS'] = '172800';
            $this->db->exec("UPDATE packet_bbs_sessions SET last_activity_at = NOW() - INTERVAL '25 hours'");
            $this->repo->cleanExpired();
            $this->assertNotNull($this->repo->load('sender'));
            $this->db->exec("UPDATE packet_bbs_sessions SET last_activity_at = NOW() - INTERVAL '49 hours'");
            $this->repo->cleanExpired();
            $this->assertNull($this->repo->load('sender'));
        } finally {
            if ($previous === null) unset($_ENV['PACKETBBS_SESSION_RETENTION_SECONDS']);
            else $_ENV['PACKETBBS_SESSION_RETENTION_SECONDS'] = $previous;
        }
    }

    public function testGuestAndNewSessionRemainGuestsAndRefreshOnCommandOnly(): void
    {
        $new = $this->repo->getOrCreate('sender', 'bridge');
        $this->assertNull($new['user_id']);
        $this->assertSame('main', $new['menu_state']);
        $this->db->exec("UPDATE packet_bbs_sessions SET last_activity_at = NOW() - INTERVAL '1 hour'");
        $before = $this->raw()['last_activity_at'];
        $this->reReadGuest($before);
        $this->assertStringContainsString('LOGIN', $this->command('N'));
        $this->assertNotSame($before, $this->raw()['last_activity_at']);
        $this->assertNull($this->raw()['user_id']);
    }

    private function reReadGuest(string $before): void
    {
        $guest = $this->repo->getOrCreate('sender', 'bridge');
        $this->assertSame($before, $guest['last_activity_at']);
        $this->assertEmpty($guest['session_state']);
    }

    public function testCleanupRetainsExpiredRowUntilIndependentDayThreshold(): void
    {
        $before = $this->seed(23 * 3600);
        $this->repo->cleanExpired();
        $this->assertSame($before, $this->raw()['last_activity_at']);
        $this->assertNull($this->raw()['user_id']);
        $this->assertSame('Session expired. LOGIN again.', $this->command('N'));
        $this->db->exec("UPDATE packet_bbs_sessions SET last_activity_at = NOW() - INTERVAL '25 hours'");
        $this->repo->cleanExpired();
        $this->assertNull($this->repo->load('sender'));
        $this->assertStringContainsString('LOGIN', $this->command('N'));
        $this->assertNull($this->raw()['user_id']);
        $this->assertSame('main', $this->raw()['menu_state']);
    }

    public function testExpiredChatStopsNotificationsAndPollingDoesNotConsumeExpiryNotice(): void
    {
        $before = $this->seed(960);
        $this->db->exec("UPDATE packet_bbs_sessions SET menu_state = 'chat', session_state = '{\"current_chat_dm\":{\"user_id\":1}}'");
        $this->db->exec("INSERT INTO packet_bbs_outbound_queue (node_id, payload) VALUES ('sender', 'private queued message')");
        PacketBbsChatNotifier::enqueueForDm($this->db, 1, 1, 'new private message');
        $this->assertSame([], $this->gateway->getPendingMessages('sender'));
        $this->assertSame($before, $this->raw()['last_activity_at']);
        $this->assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM packet_bbs_outbound_queue')->fetchColumn());
        $this->assertSame('Session expired. LOGIN again.', $this->command('N'));
    }

    public function testActiveChatDeliveryDoesNotRefreshSessionActivity(): void
    {
        $before = $this->seed(600);
        $this->db->exec("UPDATE packet_bbs_sessions SET menu_state = 'chat', session_state = '{\"current_chat_dm\":{\"user_id\":1}}'");
        PacketBbsChatNotifier::enqueueForDm($this->db, 1, 1, 'active message');
        $messages = $this->gateway->getPendingMessages('sender');
        $this->assertCount(1, $messages);
        $this->assertSame('alice: active message', $messages[0]['payload']);
        $this->assertSame([], $this->gateway->getPendingMessages('sender'));
        $this->assertSame($before, $this->raw()['last_activity_at']);
    }

    // ---- linked-session fail-closed ------------------------------------------

    public function testAuthenticatedCommandWithValidLinkedSessionWorksAndRefreshesPresence(): void
    {
        $this->seed(600);
        $this->db->exec("UPDATE user_sessions SET last_activity = NOW() - INTERVAL '1 hour' WHERE session_id = 'old-session'");
        $this->assertSame('Send HELP.', $this->command(''));
        $this->assertSame(1, $this->raw()['user_id']);
        $this->assertSame('old-session', $this->raw()['bbs_session_id']);
        $row = $this->db->query("SELECT last_activity, activity FROM user_sessions WHERE session_id = 'old-session'")->fetch(PDO::FETCH_ASSOC);
        $this->assertGreaterThan(time() - 60, strtotime($row['last_activity']), 'valid command refreshes user_sessions.last_activity');
        $this->assertSame('PacketBBS', $row['activity']);
    }

    public function testRevokedLinkedSessionFailsClosedBeforeTheCommandExecutes(): void
    {
        $this->seed(600);
        $this->db->exec("INSERT INTO packet_bbs_outbound_queue (node_id, payload, sent_at) VALUES ('sender', 'private', NULL)");
        // Revoke the ONE linked row externally (admin kick / logout elsewhere).
        $this->db->exec("DELETE FROM user_sessions WHERE session_id = 'old-session'");

        // /SEND in compose mode would reach submitCompose(); no MessageHandler
        // is injected, so executing it would error. It must not be reached.
        $this->assertSame('Session expired. LOGIN again.', $this->command('/SEND'));

        $row = $this->raw();
        $this->assertNull($row['user_id'], 'identity cleared');
        $this->assertNull($row['bbs_session_id']);
        $this->assertSame('main', $row['menu_state']);
        $this->assertNull($row['compose_buffer'], 'draft discarded like idle expiry');
        $this->assertSame([], $this->repo->load('sender')['session_state'], 'notice consumed in the same reply');
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM packet_bbs_outbound_queue WHERE sent_at IS NULL")->fetchColumn());
        $this->assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM user_sessions')->fetchColumn(), 'no replacement user_sessions row');
        // Next command is an ordinary guest command: LOGIN is required.
        $this->assertStringContainsString('LOGIN', $this->command('N'));
        $this->assertNull($this->raw()['user_id']);
    }

    public function testRevokeAllSessionsForUserHasTheSameEffect(): void
    {
        $this->seed(600);
        $this->db->exec("INSERT INTO user_sessions (session_id, user_id, expires_at) VALUES ('web-tab', 1, NOW() + INTERVAL '1 day')");
        $this->db->exec("DELETE FROM user_sessions WHERE user_id = 1");
        $this->assertSame('Session expired. LOGIN again.', $this->command(''));
        $this->assertNull($this->raw()['user_id']);
        $this->assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM user_sessions')->fetchColumn());
        $this->assertStringContainsString('LOGIN', $this->command('N'));
    }

    public function testAuthenticatedRowWithoutLinkedSessionIdFailsClosed(): void
    {
        $this->seed(600);
        $this->repo->update('sender', ['bbs_session_id' => null]);
        $this->assertSame('Session expired. LOGIN again.', $this->command(''));
        $this->assertNull($this->raw()['user_id']);
    }

    public function testExpiredLinkedSessionRowFailsClosed(): void
    {
        $this->seed(600);
        $this->db->exec("UPDATE user_sessions SET expires_at = NOW() - INTERVAL '1 minute' WHERE session_id = 'old-session'");
        $this->assertSame('Session expired. LOGIN again.', $this->command(''));
        $this->assertNull($this->raw()['user_id']);
        $this->assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM user_sessions')->fetchColumn(), 'stale linked row removed with the identity');
    }

    public function testPollingDoesNotDeliverQueuedContentToARevokedIdentity(): void
    {
        $before = $this->seed(600);
        $this->db->exec("UPDATE packet_bbs_sessions SET menu_state = 'chat', session_state = '{\"current_chat_dm\":{\"user_id\":1}}'");
        PacketBbsChatNotifier::enqueueForDm($this->db, 1, 1, 'new private message');
        $this->assertCount(1, $this->db->query("SELECT id FROM packet_bbs_outbound_queue WHERE sent_at IS NULL")->fetchAll());
        $this->db->exec("DELETE FROM user_sessions WHERE session_id = 'old-session'");

        $this->assertSame([], $this->gateway->getPendingMessages('sender'));
        $this->assertNull($this->raw()['user_id']);
        $this->assertSame($before, $this->raw()['last_activity_at'], 'polling never refreshes activity');
        $this->assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM packet_bbs_outbound_queue')->fetchColumn(), 'queued private content discarded');
        // The retained notice is consumed by the next command, like idle expiry.
        $this->assertSame('Session expired. LOGIN again.', $this->command('N'));
        $this->assertStringContainsString('LOGIN', $this->command('N'));
    }

    public function testSuccessfulTotpReloginAfterExpiryRestoresFreshAuthentication(): void
    {
        $this->seed(960);
        $this->assertSame('Session expired. LOGIN again.', $this->command('L alice ignored'));
        // Fixed RFC test secret, never a generated/enrolled real credential.
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $stmt = $this->db->prepare('INSERT INTO users_meta VALUES (1, ?, ?)');
        $stmt->execute(['packet_bbs_totp_enabled', '1']);
        $stmt->execute(['packet_bbs_totp_secret', $secret]);
        $code = (new ReflectionMethod(PacketBbsTotp::class, 'computeHotp'))->invoke(null, '12345678901234567890', (int)floor(time() / 30));
        $this->assertStringContainsString('Hi alice.', $this->command('L alice ' . $code));
        $session = $this->repo->load('sender');
        $this->assertSame(1, $session['user_id']);
        $this->assertNotEmpty($session['bbs_session_id']);
        $this->assertNotSame('old-session', $session['bbs_session_id']);
        $this->assertSame([], $session['session_state']);
        $this->assertNull($session['compose_buffer']);
        $this->assertSame('Send HELP.', $this->command(''));
    }
}
