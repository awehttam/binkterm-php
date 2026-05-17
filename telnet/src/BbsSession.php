<?php

namespace BinktermPHP\TelnetServer;

use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Binkp\Logger;
use BinktermPHP\BbsConfig;
use BinktermPHP\Config;
use BinktermPHP\I18n\Translator;
use BinktermPHP\Version;

/**
 * BbsSession — handles a single authenticated BBS session over any transport.
 *
 * Extracted from TelnetServer so that both the Telnet daemon and the SSH daemon
 * can share identical BBS logic (menus, mail, editor, polls, doors, etc.).
 *
 * Transport-specific differences are gated on $isSsh / $isTls:
 *  - SSH:    skips TELNET negotiation, ESC challenge, and echo IAC commands.
 *            The SSH layer handles terminal setup; BbsSession just reads/writes
 *            the plaintext channel socket.
 *  - Telnet: full TELNET protocol negotiation and anti-bot ESC challenge.
 *  - TLS:    banner shows the encrypted-connection indicator.
 */
class BbsSession
{
    // ===== TELNET PROTOCOL CONSTANTS =====
    private const IAC         = 255;
    private const OPT_BINARY  = 0;
    private const DONT        = 254;
    private const TELNET_DO   = 253;
    private const WONT        = 252;
    private const WILL        = 251;
    private const SB          = 250;
    private const SE          = 240;
    private const OPT_ECHO    = 1;
    private const OPT_SUPPRESS_GA = 3;
    private const OPT_NAWS    = 31;
    private const OPT_LINEMODE = 34;
    private const OPT_TTYPE   = 24;

    // ===== KEY SEQUENCE CONSTANTS =====
    private const KEY_UP     = "\033[A";
    private const KEY_DOWN   = "\033[B";
    private const KEY_RIGHT  = "\033[C";
    private const KEY_LEFT   = "\033[D";
    private const KEY_HOME   = "\033[H";
    private const KEY_END    = "\033[F";
    private const KEY_DELETE = "\033[3~";
    private const KEY_PGUP   = "\033[5~";
    private const KEY_PGDOWN = "\033[6~";
    private const KEY_SHIFT_TAB = "\033[Z";

    // ===== ANSI COLOR CONSTANTS =====
    private const ANSI_RESET   = "\033[0m";
    private const ANSI_BOLD    = "\033[1m";
    private const ANSI_DIM     = "\033[2m";
    private const ANSI_BLUE    = "\033[34m";
    private const ANSI_CYAN    = "\033[36m";
    private const ANSI_GREEN   = "\033[32m";
    private const ANSI_YELLOW  = "\033[33m";
    private const ANSI_MAGENTA = "\033[35m";
    private const ANSI_RED     = "\033[31m";
    private const ANSI_BG_BLUE = "\033[44m";

    /** @var resource */
    private $conn;
    private string $apiBase;
    private bool $debug;
    private bool $insecure;
    private bool $isTls;
    private bool $isSsh;
    private bool $tlsEnabled;
    private int $tlsPort;
    private ?Logger $logger;
    private bool $asciiTextMode;
    /** @var string Terminal character set: 'utf8', 'cp437', or 'ascii' */
    private string $terminalCharset = 'ascii';
    /** @var bool Whether ANSI color is enabled for this terminal */
    private bool $ansiColorEnabled = true;
    /** @var bool Whether the terminal supports Sixel graphics (Primary DA attribute 4) */
    private bool $sixelSupported = false;
    private array $failedLoginAttempts = [];
    private Translator $translator;
    private string $systemLocale;
    private ?string $peerName;
    private ?string $peerIp;

    /**
     * Pre-authenticated session data supplied by SSH layer.
     * When set, the login UI is skipped and this session is used directly.
     * Keys: 'session' (cookie), 'username', 'csrf_token'.
     *
     * @var array|null
     */
    private ?array $preAuthSession;

    /**
     * @param resource    $conn           Bidirectional socket for this session
     * @param string      $apiBase        BBS API base URL
     * @param bool        $debug          Enable verbose debug output
     * @param bool        $insecure       Disable SSL cert verification on API calls
     * @param bool        $isTls          Connection arrived on the TLS port
     * @param bool        $isSsh          Connection arrived via SSH (skip TELNET protocol)
     * @param bool        $tlsEnabled     Whether TLS is enabled on the server (for banner hint)
     * @param int         $tlsPort        TLS port number (for banner hint)
     * @param Logger|null $logger          Logger instance, or null for no logging
     * @param array|null  $preAuthSession Pre-authenticated user data from SSH layer
     * @param string|null $peerName       Peer address captured at accept time
     * @param string|null $peerIp         Parsed peer IP captured at accept time
     */
    public function __construct(
        $conn,
        string $apiBase,
        bool $debug,
        bool $insecure,
        bool $isTls,
        bool $isSsh,
        bool $tlsEnabled = true,
        int $tlsPort = 8023,
        ?Logger $logger = null,
        ?array $preAuthSession = null,
        ?string $peerName = null,
        ?string $peerIp = null
    ) {
        $this->conn           = $conn;
        $this->apiBase        = rtrim($apiBase, '/');
        $this->debug          = $debug;
        $this->insecure       = $insecure;
        $this->isTls          = $isTls;
        $this->isSsh          = $isSsh;
        $this->tlsEnabled     = $tlsEnabled;
        $this->tlsPort        = $tlsPort;
        $this->logger         = $logger;
        // Conservative default for Telnet until terminal capabilities are known.
        $this->asciiTextMode  = !$this->isSsh;
        $this->preAuthSession = $preAuthSession;
        $this->translator     = new Translator();
        $this->systemLocale   = (string)Config::env('I18N_DEFAULT_LOCALE', 'en');
        $this->peerName       = $peerName;
        $this->peerIp         = $peerIp;
    }

    // ===== PUBLIC INTERFACE =====

    /**
     * Run the BBS session from start (banner / login) to finish (logout / disconnect).
     *
     * @param bool $forked True when running inside a pcntl_fork() child — causes
     *                     exit(0) instead of return on session end.
     */
    public function run(bool $forked): void
    {
        $conn = $this->conn;
        stream_set_timeout($conn, 300);
        stream_set_write_buffer($conn, 0); // Disable write buffering so banner/prompts are sent immediately

        $state = [
            'telnet_mode' => null,
            'input_echo'  => true,
            'cols'        => 80,
            'rows'        => 24,
            'terminal_type' => '',
            'terminal_info_logged' => false,
            'last_activity'          => time(),
            'idle_warned'            => false,
            'idle_warning_timeout'   => 300,
            'idle_disconnect_timeout'=> 420,
            'pushback' => '',
            'locale'   => $this->systemLocale,
            'isTls'    => $this->isTls,
            'isSsh'    => $this->isSsh,
        ];

        // Seed terminal size from SSH pty-req if provided
        if ($this->preAuthSession !== null) {
            if (!empty($this->preAuthSession['cols'])) { $state['cols'] = (int)$this->preAuthSession['cols']; }
            if (!empty($this->preAuthSession['rows'])) { $state['rows'] = (int)$this->preAuthSession['rows']; }
        }

        if ($this->debug) {
            $this->log("Connection initialized: screen size {$state['cols']}x{$state['rows']}");
        }

        if (!$this->isSsh) {
            $this->negotiateTelnet($conn);
            // TLS telnet clients commonly delay TELNET option replies until after
            // they see visible output. Blocking on TTYPE before the banner causes
            // a blank-screen stall on port 8023. Skip the TTYPE probe for TLS and
            // proceed immediately: charset stays at the conservative ASCII default
            // until saved settings are applied post-login (or the detection wizard
            // runs for new users). ANSI color is assumed because virtually all TLS
            // telnet clients are modern and color-capable.
            if ($this->isTls) {
                $this->ansiColorEnabled = true;
                TelnetUtils::setAnsiColorEnabled(true);
                if ($this->debug) { $this->log('TLS startup: ANSI enabled; charset deferred to saved settings'); }
                $this->probeSixelSupport($conn, $state);
            } else {
                // Probe ANSI support before showing the banner by doing the TTYPE
                // handshake properly: send TTYPE SEND only after receiving WILL TTYPE,
                // then use the IS response to determine color capability.
                // Default to no color until confirmed; SSH clients are assumed ANSI.
                $this->ansiColorEnabled = false;
                TelnetUtils::setAnsiColorEnabled(false);
                if ($this->probeAnsiSupport($conn, $state)) {
                    $this->ansiColorEnabled = true;
                    TelnetUtils::setAnsiColorEnabled(true);
                    if ($this->debug) { $this->log('ANSI auto-detect: ANSI color enabled'); }
                    $this->probeSixelSupport($conn, $state);
                } else {
                    if ($this->debug) { $this->log('ANSI auto-detect: TTYPE absent or dumb terminal, defaulting to plain ASCII'); }
                }
            }
        } else {
            // SSH clients are assumed ANSI/UTF-8; probe for sixel support the same
            // way telnet does — send a Primary DA request and check for attribute 4.
            $this->probeSixelSupport($conn, $state);
        }

        if (!$this->peerName || !$this->peerIp) {
            $this->writeLine($conn, "I don't know who you are.");
            $this->log("Connection with no peer address — dropped");
            fclose($conn);
            if ($forked) { exit(0); }
            return;
        }
        $peerName = $this->peerName;
        $peerIp   = $this->peerIp;

        if ($this->isRateLimited($peerIp)) {
            $this->writeLine($conn, '');
            $this->writeLine($conn, $this->colorize(
                $this->t('ui.terminalserver.server.rate_limited', 'Too many failed login attempts. Please try again later.', [], $state['locale']),
                self::ANSI_RED
            ));
            $this->writeLine($conn, '');
            $this->log("Rate limited connection from {$peerName}");
            fclose($conn);
            if ($forked) { exit(0); }
            return;
        }

        $this->showLoginBanner($conn, $state);

        // if (!$this->isSsh && !$this->requireEscapeKey($conn, $state)) {
        //     $this->log("Bot/timeout on ESC challenge from {$peerName} - connection dropped");
        //     fclose($conn);
        //     if ($forked) { exit(0); }
        //     return;
        // }

        // ===== LOGIN / REGISTER =====

        $loginResult = null;

        if ($this->preAuthSession !== null && isset($this->preAuthSession['session'])) {
            // SSH: already authenticated at the protocol layer
            $loginResult = $this->preAuthSession;
        } else {
            $showQwkTransfer = BbsConfig::isFeatureEnabled('qwk');
            while ($loginResult === null) {
                $this->writeLine($conn, $this->t('ui.terminalserver.server.login_menu.prompt', 'Would you like to:', [], $state['locale']));
                $this->writeLine($conn, $this->t('ui.terminalserver.server.login_menu.login',   '  (L) Login to existing account', [], $state['locale']));
                $this->writeLine($conn, $this->t('ui.terminalserver.server.login_menu.reset_password', '  (R) Reset lost password', [], $state['locale']));
                $this->writeLine($conn, $this->t('ui.terminalserver.server.login_menu.register','  (N) Register new account', [], $state['locale']));
                if ($showQwkTransfer) {
                    $this->writeLine($conn, $this->t('ui.terminalserver.server.login_menu.qwk_transfer', '  (K) QWK transfer', [], $state['locale']));
                }
                $this->writeLine($conn, $this->t('ui.terminalserver.server.login_menu.quit',    '  (Q) Quit', [], $state['locale']));
                $this->writeLine($conn, '');
                $choice = $this->prompt($conn, $state, $this->t('ui.terminalserver.server.login_menu.choice', 'Your choice: ', [], $state['locale']), true);
                $normalizedChoice = strtolower(trim((string)$choice));

                if ($choice === null || $normalizedChoice === 'q') {
                    $this->writeLine($conn, $this->colorize(
                        $this->t('ui.terminalserver.server.goodbye', 'Goodbye!', [], $state['locale']),
                        self::ANSI_CYAN
                    ));
                    fclose($conn);
                    if ($forked) { exit(0); }
                    return;
                }

                if ($normalizedChoice === 'r') {
                    $this->attemptLostPasswordReset($conn, $state);
                    $this->writeLine($conn, '');
                    continue;
                }

                if ($normalizedChoice === 'n') {
                    $registered = $this->attemptRegistration($conn, $state);
                    if ($registered) {
                        $this->writeLine($conn, $this->t('ui.terminalserver.server.press_enter_disconnect', 'Press Enter to disconnect.', [], $state['locale']));
                        $this->readLineWithIdleCheck($conn, $state);
                        fclose($conn);
                        if ($forked) { exit(0); }
                        return;
                    }
                    $this->writeLine($conn, '');
                    continue;
                }

                $qwkTransferMode = $showQwkTransfer && $normalizedChoice === 'k';
                if ($normalizedChoice !== 'l' && !$qwkTransferMode) {
                    $this->writeLine($conn, $this->colorize(
                        $this->t('ui.terminalserver.server.login_menu.invalid_choice', 'Invalid selection.', [], $state['locale']),
                        self::ANSI_RED
                    ));
                    $this->writeLine($conn, '');
                    continue;
                }

                $this->writeLine($conn, '');
                $maxAttempts = 3;
                for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                    $attemptedUsername = '';
                    $loginResult = $this->attemptLogin($conn, $state, $attemptedUsername);

                    if ($loginResult !== null) {
                        $loginResult['qwk_transfer_mode'] = $qwkTransferMode;
                        $this->writeLine($conn, $this->colorize(
                            $this->t('ui.terminalserver.server.login.success', 'Login successful.', [], $state['locale']),
                            self::ANSI_GREEN
                        ));
                        $this->writeLine($conn, '');
                        break 2;
                    }

                    $this->recordFailedLogin($peerIp);
                    $userLabel = $attemptedUsername !== '' ? " (user: {$attemptedUsername})" : '';
                    $this->log("Failed login attempt from {$peerName}{$userLabel} (attempt {$attempt}/{$maxAttempts})");

                    if ($attempt < $maxAttempts) {
                        $remaining = $maxAttempts - $attempt;
                        $this->writeLine($conn, $this->colorize(
                            $this->t('ui.terminalserver.server.login.failed_remaining', 'Login failed. {remaining} attempt(s) remaining.', ['remaining' => $remaining], $state['locale']),
                            self::ANSI_RED
                        ));
                        $this->writeLine($conn, '');
                    } else {
                        $this->writeLine($conn, $this->colorize(
                            $this->t('ui.terminalserver.server.login.failed_max', 'Login failed. Maximum attempts exceeded.', [], $state['locale']),
                            self::ANSI_RED
                        ));
                        $this->writeLine($conn, '');
                    }
                }

                if ($loginResult === null) {
                    $this->log("Login failed (max attempts) from {$peerName}");
                    fclose($conn);
                    if ($forked) { exit(0); }
                    return;
                }
            }
        }

        // ===== POST-LOGIN SETUP =====

        $session   = $loginResult['session'];
        $username  = $loginResult['username'];
        $loginTime = time();

        $state['csrf_token'] = $loginResult['csrf_token'] ?? null;

        $initResp = TelnetUtils::apiRequest($this->apiBase, 'GET', '/api/config/session-init', null, $session);
        $initData = $initResp['data'] ?? [];

        $userSettings = $initData['user'] ?? [];
        $state['user_timezone']     = $userSettings['timezone']    ?? 'UTC';
        $state['user_date_format']  = $userSettings['date_format'] ?? 'Y-m-d H:i:s';
        $state['username']          = $username;
        $state['qwk_transfer_mode'] = !empty($loginResult['qwk_transfer_mode']);
        $userLocale = $userSettings['locale'] ?? '';
        if ($userLocale !== '' && $this->translator->isSupportedLocale($userLocale)) {
            $state['locale'] = $userLocale;
        }

        $auth       = new \BinktermPHP\Auth();
        $userRecord = $auth->validateSession($session);
        $state['is_admin'] = !empty($userRecord['is_admin']);
        $state['user_id']  = (int)($userRecord['user_id'] ?? $userRecord['id'] ?? 0);

        $this->clearFailedLogins($peerIp);

        $transport = $this->isSsh ? 'ssh' : 'telnet';
        $this->log("Login: {$username} from {$peerName} via {$transport}");

        \BinktermPHP\ActivityTracker::track(
            $userRecord['user_id'] ?? null,
            \BinktermPHP\ActivityTracker::TYPE_LOGIN,
            null,
            $transport,
            ['ip' => $peerIp]
        );

        $config = BinkpConfig::getInstance();
        $this->setTerminalTitle($conn, $config->getSystemName());

        $netmailHandler       = new NetmailHandler($this, $this->apiBase);
        $echomailHandler      = new EchomailHandler($this, $this->apiBase);
        $chatHandler          = new ChatHandler($this, $this->apiBase);
        $shoutboxHandler      = new ShoutboxHandler($this, $this->apiBase);
        $bulletinsHandler     = new BulletinsHandler($this, $this->apiBase);
        $pollsHandler         = new PollsHandler($this, $this->apiBase);
        $doorHandler          = new DoorHandler($this, $this->apiBase);
        $fileHandler          = new FileHandler($this, $this->apiBase, $this->isSsh);
        $interestsHandler     = new InterestsHandler($this, $this->apiBase);
        $qwkHandler           = new QwkMenuHandler($this, $this->apiBase, $this->isSsh);
        $bbsListHandler       = new BbsListHandler($this, $this->apiBase);
        $nodelistHandler      = new NodelistBrowserHandler($this, $this->apiBase);

        // Load saved terminal settings and apply them to the session
        $terminalSettingsHandler = new TerminalSettingsHandler($this, $this->apiBase);
        $terminalSettingsHandler->loadSettings($conn, $state, $session, $initData['terminal'] ?? null);
        $settingsHandler = new SettingsHandler($this, $this->apiBase);

        // Run first-time detection wizard if no terminal settings have been saved yet
        if (($state['terminal_charset'] ?? null) === null && empty($state['qwk_transfer_mode'])) {
            $terminalSettingsHandler->runDetectionWizard($conn, $state, $session);
        }

        if (!empty($state['qwk_transfer_mode'])) {
            $this->log("Menu: {$username} -> QWK Offline Mail (direct)");
            $logoutRequested = $qwkHandler->show($conn, $state, $session, true);
            if ($logoutRequested) {
                $this->logoutSession($session);
            }
            $this->writeLine($conn, '');
            $this->writeLine($conn, $this->colorize(
                $this->t('ui.terminalserver.server.goodbye', 'Goodbye!', [], $state['locale']),
                self::ANSI_CYAN
            ));
            if (is_resource($conn)) { fflush($conn); }
            $this->logDuration("Logout", $username, $loginTime);
            $this->setTerminalTitle($conn, '');
            fclose($conn);
            if ($forked) { exit(0); }
            return;
        }

        $this->showSystemNews($conn, $state);
        $bulletinsHandler->showUnread($conn, $state, $session);
        $shoutboxHandler->show($conn, $state, $session, 5, false);

        if (Config::env('ENABLE_INTERESTS') === 'true') {
            $interestsHandler->showOnboardingHintIfNeeded($conn, $state, $session);
        }

        $dashboardStats = MailUtils::getDashboardStats($this->apiBase, $session);

        $idleData = $initData['idle'] ?? [];
        if (!empty($idleData['warn_seconds'])) {
            $state['idle_warning_timeout'] = (int)$idleData['warn_seconds'];
        }
        if (!empty($idleData['disconnect_seconds'])) {
            $state['idle_disconnect_timeout'] = (int)$idleData['disconnect_seconds'];
        }

        $menuKeys = is_array($initData['term_menu_keys'] ?? null)
            ? $initData['term_menu_keys']
            : [
                'netmail' => 'n', 'echomail' => 'e', 'shoutbox' => 's', 'bulletins' => 'u',
                'polls'   => 'p', 'doors'    => 'd', 'files'    => 'f', 'settings'  => 't',
                'interests' => 'i', 'whosonline' => 'w', 'qwk' => 'k',
                'bbslist' => 'b', 'nodelist'  => 'l', 'localchat' => 'c', 'quit' => 'q',
            ];
        $keyToAction = []; // populated on each menu render in the else branch below

        // ===== MAIN MENU LOOP =====
        while (true) {
            $this->logTerminalInfoOnce($state);

            if (!is_resource($conn) || feof($conn)) {
                $this->logDuration("Connection lost", $username, $loginTime);
                break;
            }

            if (($this->sixelSupported && TelnetUtils::showSixelScreenIfExists("mainmenu.sixel", $this, $conn))
                || TelnetUtils::showScreenIfExists("mainmenu.ans", $this, $conn)) {
                $this->writeLine($conn, '');
            } else {
                $cols      = $state['cols'] ?? 80;
                $menuWidth = min(76, $cols - 4);
                $innerWidth= $menuWidth - 4;
                $menuLeft  = max(0, (int)floor(($cols - $menuWidth) / 2));
                $menuPad   = str_repeat(' ', $menuLeft);
                $systemName= $config->getSystemName();
                $chars     = $this->getLineDrawingChars();
                $boxTop    = $this->encodeForTerminal($chars['tl'] . str_repeat($chars['h_bold'], $menuWidth - 2) . $chars['tr']);
                $boxBottom = $this->encodeForTerminal($chars['bl'] . str_repeat($chars['h_bold'], $menuWidth - 2) . $chars['br']);
                $divider   = $this->encodeForTerminal($chars['l_tee'] . str_repeat($chars['h'], $menuWidth - 2) . $chars['r_tee']);

                $this->safeWrite($conn, "\033[2J\033[H");

                $currentUtc = gmdate('Y-m-d H:i:s');
                $timeStr    = TelnetUtils::formatUserDate($currentUtc, $state, false);
                $statusLine = TelnetUtils::buildStatusBar([
                    ['text' => $systemName . '  ', 'color' => self::ANSI_BLUE],
                    ['text' => str_repeat(' ', max(1, $cols - strlen($systemName) - strlen($timeStr) - 2)), 'color' => self::ANSI_BLUE],
                    ['text' => $timeStr, 'color' => self::ANSI_BLUE],
                ], $cols);
                $this->safeWrite($conn, "\033[1;1H");
                $this->safeWrite($conn, $statusLine . "\r");
                $this->safeWrite($conn, "\033[2;1H");
                $this->writeLine($conn, '');
                $this->writeLine($conn, $menuPad . $this->colorize($boxTop, self::ANSI_BLUE . self::ANSI_BOLD));
                $titleLabel = $this->normalizeTerminalTextForClient(
                    $this->t('ui.terminalserver.server.menu.title', 'Main Menu', [], $state['locale']),
                    $state
                );
                $titleText = $this->mbStrPad($titleLabel, $innerWidth, ' ', STR_PAD_BOTH);
                $titleLine = $this->colorize($this->encodeForTerminal($chars['v']), self::ANSI_BLUE . self::ANSI_BOLD)
                    . $this->colorize(' ' . $titleText . ' ', self::ANSI_BG_BLUE . self::ANSI_CYAN . self::ANSI_BOLD)
                    . $this->colorize($this->encodeForTerminal($chars['v']), self::ANSI_BLUE . self::ANSI_BOLD);
                $this->writeLine($conn, $menuPad . $titleLine);
                $this->writeLine($conn, $menuPad . $this->colorize($divider, self::ANSI_BLUE));

                $showShoutbox   = BbsConfig::isFeatureEnabled('shoutbox');
                $showPolls      = BbsConfig::isFeatureEnabled('voting_booth');
                $showDoors      = BbsConfig::isFeatureEnabled('webdoors');
                $showFiles      = \BinktermPHP\FileAreaManager::isFeatureEnabled();
                $showInterests  = Config::env('ENABLE_INTERESTS') === 'true';
                $showQwk        = BbsConfig::isFeatureEnabled('qwk');
                $showBbsList    = BbsConfig::isFeatureEnabled('bbs_directory');
                $showNodelist   = $this->nodelistHasEntries();
                $locale         = $state['locale'];

                // Option keys driven by sysop key map + feature flags
                $netmailKey      = $menuKeys['netmail']   ?? null;
                $echomailKey     = $menuKeys['echomail']  ?? null;
                $settingsKey     = $menuKeys['settings']  ?? null;
                $quitKey         = $menuKeys['quit']      ?? null;
                $localchatKey    = BbsConfig::isFeatureEnabled('chat') && isset($menuKeys['localchat'])  ? $menuKeys['localchat']  : null;
                $shoutboxOption  = $showShoutbox  && isset($menuKeys['shoutbox'])   ? $menuKeys['shoutbox']   : null;
                $bulletinsOption = $menuKeys['bulletins'] ?? null;
                $pollsOption     = $showPolls     && isset($menuKeys['polls'])      ? $menuKeys['polls']      : null;
                $doorsOption     = $showDoors     && isset($menuKeys['doors'])      ? $menuKeys['doors']      : null;
                $filesOption     = $showFiles     && isset($menuKeys['files'])      ? $menuKeys['files']      : null;
                $interestsOption = $showInterests && isset($menuKeys['interests'])  ? $menuKeys['interests']  : null;
                $qwkOption       = $showQwk       && isset($menuKeys['qwk'])        ? $menuKeys['qwk']        : null;
                $bbsListOption   = $showBbsList   && isset($menuKeys['bbslist'])    ? $menuKeys['bbslist']    : null;
                $nodelistOption  = $showNodelist  && isset($menuKeys['nodelist'])   ? $menuKeys['nodelist']   : null;
                $whosOnlineOption = $menuKeys['whosonline'] ?? null;

                // Inverted map: key char → action ID, used by the input handler and dispatch
                $keyToAction = [];
                foreach ([
                    'netmail'    => $netmailKey,
                    'echomail'   => $echomailKey,
                    'shoutbox'   => $shoutboxOption,
                    'bulletins'  => $bulletinsOption,
                    'polls'      => $pollsOption,
                    'localchat'  => $localchatKey,
                    'interests'  => $interestsOption,
                    'qwk'        => $qwkOption,
                    'doors'      => $doorsOption,
                    'files'      => $filesOption,
                    'bbslist'    => $bbsListOption,
                    'nodelist'   => $nodelistOption,
                    'whosonline' => $whosOnlineOption,
                    'settings'   => $settingsKey,
                    'quit'       => $quitKey,
                ] as $_action => $_key) {
                    if ($_key !== null) {
                        $keyToAction[$_key] = $_action;
                    }
                }

                // Two-column layout: colWidth = (menuWidth - 6) / 2
                // Box line: │ + ' ' + left(colWidth) + '  ' + right(colWidth) + ' ' + │
                $colWidth = (int)floor(($menuWidth - 6) / 2);
                $empty    = str_repeat(' ', $colWidth);

                // Pre-build stripped, normalised labels
                $lblNetmail    = $this->normalizeTerminalTextForClient($this->stripMenuHotkeyPrefix($this->t('ui.terminalserver.server.menu.netmail',   'N) Netmail ({count} unread)',     ['count' => $dashboardStats['unread_netmail']], $locale), 'N'), $state);
                $lblEchomail   = $this->normalizeTerminalTextForClient($this->stripMenuHotkeyPrefix($this->t('ui.terminalserver.server.menu.echomail',  'E) Echomail ({count} new)',       ['count' => $dashboardStats['new_echomail']],   $locale), 'E'), $state);
                $lblQwk        = $this->normalizeTerminalTextForClient($this->stripMenuHotkeyPrefix($this->t('ui.terminalserver.server.menu.qwk',        'K) QWK Offline Mail',             [],                                      $locale), 'K'), $state);
                $lblChat       = $this->normalizeTerminalTextForClient($this->stripMenuHotkeyPrefix($this->t('ui.terminalserver.server.menu.chat',       'C) Local Chat',                   [],                                      $locale), 'C'), $state);
                $lblWhosOnline = $this->normalizeTerminalTextForClient($this->stripMenuHotkeyPrefix($this->t('ui.terminalserver.server.menu.whos_online',"W) Who's Online",                 [],                                      $locale), 'W'), $state);
                $lblShoutbox   = $this->normalizeTerminalTextForClient($this->stripMenuHotkeyPrefix($this->t('ui.terminalserver.server.menu.shoutbox',   'S) Shoutbox',                     [],                                      $locale), 'S'), $state);
                $lblBulletins  = $this->normalizeTerminalTextForClient($this->stripMenuHotkeyPrefix($this->t('ui.terminalserver.server.menu.bulletins',  'U) Bulletins',                    [],                                      $locale), 'U'), $state);
                $lblPolls      = $this->normalizeTerminalTextForClient($this->stripMenuHotkeyPrefix($this->t('ui.terminalserver.server.menu.polls',       'P) Polls',                        [],                                      $locale), 'P'), $state);
                $lblDoors      = $this->normalizeTerminalTextForClient($this->stripMenuHotkeyPrefix($this->t('ui.terminalserver.server.menu.doors',       'D) Door Games',                   [],                                      $locale), 'D'), $state);
                $lblFiles      = $this->normalizeTerminalTextForClient($this->stripMenuHotkeyPrefix($this->t('ui.terminalserver.server.menu.files',       'F) Files',                        [],                                      $locale), 'F'), $state);
                $lblBbsList    = $this->normalizeTerminalTextForClient($this->stripMenuHotkeyPrefix($this->t('ui.terminalserver.server.menu.bbs_list',    'B) BBS Directory',                [],                                      $locale), 'B'), $state);
                $lblNodelist   = $this->normalizeTerminalTextForClient($this->stripMenuHotkeyPrefix($this->t('ui.terminalserver.server.menu.nodelist',    'L) Node List',                    [],                                      $locale), 'L'), $state);
                $lblSettings   = $this->normalizeTerminalTextForClient($this->stripMenuHotkeyPrefix($this->t('ui.terminalserver.server.menu.settings',    'T) Settings',                     [],                                      $locale), 'T'), $state);
                $lblInterests  = $this->normalizeTerminalTextForClient($this->stripMenuHotkeyPrefix($this->t('ui.terminalserver.server.menu.interests',   'I) Interests',                    [],                                      $locale), 'I'), $state);
                $lblQuit       = $this->normalizeTerminalTextForClient($this->stripMenuHotkeyPrefix($this->t('ui.terminalserver.server.menu.quit',        'Q) Quit',                         [],                                      $locale), 'Q'), $state);

                // Build rows as [leftCol, rightCol] where each side is exactly $colWidth visible chars
                $rows = [];
                $anyPrevSection = false;

                // --- Messaging (reflowed) ---
                $messagingItems = [];
                if ($netmailKey      !== null) $messagingItems[] = [strtoupper($netmailKey),      $lblNetmail];
                if ($echomailKey     !== null) $messagingItems[] = [strtoupper($echomailKey),     $lblEchomail];
                if ($qwkOption       !== null) $messagingItems[] = [strtoupper($qwkOption),       $lblQwk];
                if ($bulletinsOption !== null) $messagingItems[] = [strtoupper($bulletinsOption), $lblBulletins];

                if (!empty($messagingItems)) {
                    $rows[] = [$this->menuHeaderCol($this->t('ui.terminalserver.server.menu.section.messaging', 'Messaging', [], $locale), $colWidth, $state), $empty];
                    for ($j = 0; $j < count($messagingItems); $j += 2) {
                        $rows[] = [
                            $this->menuItemCol($messagingItems[$j][0], $messagingItems[$j][1], $colWidth, $state),
                            isset($messagingItems[$j + 1]) ? $this->menuItemCol($messagingItems[$j + 1][0], $messagingItems[$j + 1][1], $colWidth, $state) : $empty,
                        ];
                    }
                    $anyPrevSection = true;
                }

                // --- Community (left) + Explore (right) ---
                $communityItems = [];
                if ($whosOnlineOption !== null) $communityItems[] = [strtoupper($whosOnlineOption), $lblWhosOnline];
                if ($localchatKey    !== null)  $communityItems[] = [strtoupper($localchatKey),    $lblChat];
                if ($pollsOption     !== null)  $communityItems[] = [strtoupper($pollsOption),     $lblPolls];
                if ($shoutboxOption  !== null)  $communityItems[] = [strtoupper($shoutboxOption),  $lblShoutbox];
                if ($doorsOption     !== null)  $communityItems[] = [strtoupper($doorsOption),     $lblDoors];

                $exploreItems = [];
                if ($bbsListOption  !== null) $exploreItems[] = [strtoupper($bbsListOption),  $lblBbsList];
                if ($nodelistOption !== null) $exploreItems[] = [strtoupper($nodelistOption), $lblNodelist];

                if (!empty($communityItems) || !empty($exploreItems)) {
                    if ($anyPrevSection) $rows[] = [$empty, $empty];
                    $rows[] = [
                        !empty($communityItems) ? $this->menuHeaderCol($this->t('ui.terminalserver.server.menu.section.community', 'Community', [], $locale), $colWidth, $state) : $empty,
                        !empty($exploreItems)   ? $this->menuHeaderCol($this->t('ui.terminalserver.server.menu.section.explore',   'Explore',   [], $locale), $colWidth, $state) : $empty,
                    ];
                    $maxSection2 = max(count($communityItems), count($exploreItems));
                    for ($j = 0; $j < $maxSection2; $j++) {
                        $rows[] = [
                            isset($communityItems[$j]) ? $this->menuItemCol($communityItems[$j][0], $communityItems[$j][1], $colWidth, $state) : $empty,
                            isset($exploreItems[$j])   ? $this->menuItemCol($exploreItems[$j][0],   $exploreItems[$j][1],   $colWidth, $state) : $empty,
                        ];
                    }
                    $anyPrevSection = true;
                }

                // --- Files (left) + Settings (right) ---
                $filesItems    = $filesOption !== null ? [[strtoupper($filesOption), $lblFiles]] : [];
                $settingsItems = [];
                if ($settingsKey     !== null) $settingsItems[] = [strtoupper($settingsKey),     $lblSettings];
                if ($interestsOption !== null) $settingsItems[] = [strtoupper($interestsOption), $lblInterests];

                if (!empty($filesItems) || !empty($settingsItems)) {
                    if ($anyPrevSection) $rows[] = [$empty, $empty];
                    $rows[] = [
                        !empty($filesItems)    ? $this->menuHeaderCol($this->t('ui.terminalserver.server.menu.section.files',    'Files',    [], $locale), $colWidth, $state) : $empty,
                        !empty($settingsItems) ? $this->menuHeaderCol($this->t('ui.terminalserver.server.menu.section.settings', 'Settings', [], $locale), $colWidth, $state) : $empty,
                    ];
                    $maxSection3 = max(count($filesItems), count($settingsItems));
                    for ($j = 0; $j < $maxSection3; $j++) {
                        $rows[] = [
                            isset($filesItems[$j])    ? $this->menuItemCol($filesItems[$j][0],    $filesItems[$j][1],    $colWidth, $state) : $empty,
                            isset($settingsItems[$j]) ? $this->menuItemCol($settingsItems[$j][0], $settingsItems[$j][1], $colWidth, $state) : $empty,
                        ];
                    }
                    $anyPrevSection = true;
                }

                // --- Quit ---
                if ($quitKey !== null) {
                    $rows[] = [$this->menuItemCol(strtoupper($quitKey), $lblQuit, $colWidth, $state), $empty];
                }

                foreach ($rows as [$left, $right]) {
                    $line = $this->colorize($this->encodeForTerminal($chars['v']), self::ANSI_BLUE)
                        . ' ' . $left . '  ' . $right . ' '
                        . $this->colorize($this->encodeForTerminal($chars['v']), self::ANSI_BLUE);
                    $this->writeLine($conn, $menuPad . $line);
                }

                $this->writeLine($conn, $menuPad . $this->colorize($boxBottom, self::ANSI_BLUE . self::ANSI_BOLD));

                // Dashboard widgets: sidebar (wide screens) or bottom bar (narrow).
                // Row layout: 1=status, 2=blank, 3=box-top, 4=title, 5=divider,
                // 6…5+count($rows)=menu items, 6+count($rows)=box-bottom.
                $termRows     = $state['rows'] ?? 24;
                $boxStartRow  = 3;
                $boxBottomRow = 6 + count($rows);
                $this->renderMenuWidgets($conn, $state, $dashboardStats, $menuLeft, $menuWidth, $boxStartRow, $boxBottomRow, $termRows);

                $this->writeLine($conn, '');
            }

            $lastRenderCols = $state['cols'] ?? 80;
            $lastRenderRows = $state['rows'] ?? 24;

            $choice      = '';
            $promptShown = false;
            while ($choice === '') {
                if (!$promptShown) {
                    $this->safeWrite($conn, $this->colorize(
                        $this->t('ui.terminalserver.server.menu.select_option', 'Select option:', [], $state['locale']),
                        self::ANSI_DIM
                    ));
                    $promptShown = true;
                }

                $wasIdleWarned = $state['idle_warned'] ?? false;
                [$key, $timedOut, $shouldDisconnect] = $this->readTelnetKeyWithTimeout($conn, $state);

                if ($shouldDisconnect) {
                    $this->logDuration("Idle timeout", $username, $loginTime);
                    break 2;
                }
                if ($key === null) {
                    $this->logDuration("Disconnected", $username, $loginTime);
                    break 2;
                }
                if ($timedOut) { continue; }

                // Detect terminal resize (NAWS updated $state during readTelnetKeyWithTimeout)
                $currentCols = $state['cols'] ?? 80;
                $currentRows = $state['rows'] ?? 24;
                if ($currentCols !== $lastRenderCols || $currentRows !== $lastRenderRows) {
                    continue 2; // restart outer loop to re-render menu at new dimensions
                }

                if (str_starts_with($key, 'CHAR:')) {
                    $char = strtolower(substr($key, 5));
                    if (isset($keyToAction[$char]) || ctype_digit($char)) {
                        $choice = $char;
                    }
                }

                // If a non-menu key dismissed the idle warning, redraw the menu so the
                // user gets visible confirmation that the session is still active.
                if ($wasIdleWarned && !($state['idle_warned'] ?? false) && $choice === '') {
                    continue 2;
                }
            }

            if ($choice === '' || $choice === null) { break; }

            $action = $keyToAction[$choice] ?? null;

            if ($action === 'netmail') {
                $this->log("Menu: {$username} -> Netmail");
                $netmailHandler->show($conn, $state, $session);
                $dashboardStats = MailUtils::getDashboardStats($this->apiBase, $session);
            } elseif ($action === 'echomail') {
                $this->log("Menu: {$username} -> Echomail");
                $echomailHandler->showEchoareas($conn, $state, $session);
                $dashboardStats = MailUtils::getDashboardStats($this->apiBase, $session);
            } elseif ($action === 'shoutbox') {
                $this->log("Menu: {$username} -> Shoutbox");
                $shoutboxHandler->show($conn, $state, $session, 20);
            } elseif ($action === 'bulletins') {
                $this->log("Menu: {$username} -> Bulletins");
                $bulletinsHandler->show($conn, $state, $session);
                $dashboardStats = MailUtils::getDashboardStats($this->apiBase, $session);
            } elseif ($action === 'polls') {
                $this->log("Menu: {$username} -> Polls");
                $pollsHandler->show($conn, $state, $session);
            } elseif ($action === 'localchat') {
                $this->log("Menu: {$username} -> Local Chat");
                $chatHandler->show($conn, $state, $session);
            } elseif ($action === 'interests') {
                $this->log("Menu: {$username} -> Interests");
                $interestsHandler->show($conn, $state, $session);
            } elseif ($action === 'qwk') {
                $this->log("Menu: {$username} -> QWK Offline Mail");
                $qwkHandler->show($conn, $state, $session);
            } elseif ($action === 'doors') {
                $this->log("Menu: {$username} -> Door Games");
                $doorHandler->show($conn, $state, $session);
            } elseif ($action === 'files') {
                $this->log("Menu: {$username} -> Files");
                $fileHandler->show($conn, $state, $session);
            } elseif ($action === 'bbslist') {
                $this->log("Menu: {$username} -> BBS Directory");
                $bbsListHandler->show($conn, $state, $session);
            } elseif ($action === 'nodelist') {
                $this->log("Menu: {$username} -> Node List");
                $nodelistHandler->show($conn, $state, $session);
            } elseif ($action === 'whosonline') {
                $this->log("Menu: {$username} -> Who's Online");
                $this->showWhosOnline($conn, $state, $session);
            } elseif ($action === 'settings') {
                $this->log("Menu: {$username} -> Settings");
                $settingsHandler->show($conn, $state, $session);
            } elseif ($action === 'quit') {
                if (!($this->sixelSupported && TelnetUtils::showSixelScreenIfExists('bye.sixel', $this, $conn))) {
                    TelnetUtils::showScreenIfExists('bye.ans', $this, $conn);
                }
                $this->writeLine($conn, '');
                $this->writeLine($conn, $this->colorize(
                    $this->t('ui.terminalserver.server.farewell', 'Thank you for visiting, have a great day!', [], $state['locale']),
                    self::ANSI_CYAN . self::ANSI_BOLD
                ));
                $this->writeLine($conn, '');
                try {
                    $siteUrl = Config::getSiteUrl();
                    $this->writeLine($conn, $this->colorize(
                        $this->t('ui.terminalserver.server.visit_web', 'Come back and visit us on the web at {url}', ['url' => $siteUrl], $state['locale']),
                        self::ANSI_YELLOW
                    ));
                } catch (\Exception $e) {}
                $this->writeLine($conn, '');
                if (is_resource($conn)) { fflush($conn); }
                sleep(2);
                $this->logDuration("Logout", $username, $loginTime);
                $this->setTerminalTitle($conn, '');
                break;
            }
        }

        fclose($conn);
        if ($forked) { exit(0); }
    }

    // ===== TRANSLATION =====

    /**
     * Translate a terminal server UI string from the 'terminalserver' catalog namespace.
     */
    public function t(string $key, string $fallback, array $params = [], string $locale = ''): string
    {
        $result = $this->translator->translate($key, $params, $locale !== '' ? $locale : null, ['terminalserver']);
        if ($result === $key) {
            foreach ($params as $k => $v) {
                $fallback = str_replace('{' . $k . '}', (string)$v, $fallback);
            }
            return $this->asciiTextMode ? $this->normalizeTerminalAscii($fallback) : $fallback;
        }
        return $this->asciiTextMode ? $this->normalizeTerminalAscii($result) : $result;
    }

    // ===== I/O HELPERS =====

    /**
     * Write to the connection, suppressing notices on broken pipes.
     */
    public function safeWrite($conn, string $data): void
    {
        if (!is_resource($conn)) { return; }
        $prev = error_reporting();
        error_reporting($prev & ~E_NOTICE);
        $total  = strlen($data);
        $offset = 0;
        while ($offset < $total) {
            $written = @fwrite($conn, substr($data, $offset));
            if ($written === false || $written === 0) {
                break;  // connection closed or unrecoverable error
            }
            $offset += $written;
        }
        @fflush($conn);
        error_reporting($prev);
    }

    /**
     * Write a CRLF-terminated line.
     */
    public function writeLine($conn, string $text = ''): void
    {
        $this->safeWrite($conn, $text . "\r\n");
    }

    /**
     * Wrap text in an ANSI color sequence.
     */
    private function colorize(string $text, string $color): string
    {
        if (!$this->ansiColorEnabled) {
            return $text;
        }
        return $color . $text . self::ANSI_RESET;
    }

    /**
     * Colorize text, respecting the terminal's ANSI color capability.
     *
     * Public version used by handler classes that need color-aware output.
     */
    public function colorizeForTerminal(string $text, string $color): string
    {
        if (!$this->ansiColorEnabled) {
            return $text;
        }
        return TelnetUtils::colorize($text, $color);
    }

    /**
     * Build one main-menu line with blue borders, cyan hotkey, and regular text label.
     */
    private function renderMainMenuOptionLine(string $hotkey, string $translatedLine, int $menuWidth, array $state): string
    {
        $label = $this->normalizeTerminalTextForClient($this->stripMenuHotkeyPrefix($translatedLine, $hotkey), $state);
        $hotkeyPrefix = strtoupper($hotkey) . ') ';
        $innerWidth = $menuWidth - 2;
        $labelWidth = max(0, $innerWidth - 1 - strlen($hotkeyPrefix));
        $labelPadded = $this->fitTerminalLabel($label, $labelWidth, $state);

        $chars = $this->getLineDrawingChars();

        return $this->colorize($this->encodeForTerminal($chars['v']), self::ANSI_BLUE)
            . ' '
            . $this->colorize(strtoupper($hotkey), self::ANSI_CYAN . self::ANSI_BOLD)
            . $this->colorize(')', self::ANSI_BLUE)
            . ' '
            . $labelPadded
            . $this->colorize($this->encodeForTerminal($chars['v']), self::ANSI_BLUE);
    }

    /**
     * Render a single column menu item for the two-column main menu layout.
     * Returns a string exactly $colWidth visible characters wide.
     */
    private function menuItemCol(string $key, string $label, int $colWidth, array $state): string
    {
        $labelPadded = $this->fitTerminalLabel($label, max(0, $colWidth - 3), $state);
        return $this->colorize(strtoupper($key), self::ANSI_CYAN . self::ANSI_BOLD)
            . $this->colorize(')', self::ANSI_BLUE)
            . ' '
            . $labelPadded;
    }

    /**
     * Render a category header for the two-column main menu layout.
     * Returns a string exactly $colWidth visible characters wide.
     */
    private function menuHeaderCol(string $text, int $colWidth, array $state): string
    {
        $label  = '[' . $text . ']';
        $padded = $this->fitTerminalLabel($label, $colWidth, $state);
        return $this->colorize($padded, self::ANSI_CYAN . self::ANSI_BOLD);
    }

    /**
     * Route dashboard widget rendering to sidebar or bottom bar depending on
     * how much horizontal space is available beside the menu.
     *
     * Sidebar threshold: at least 16 columns to the right of the menu box.
     * Below that, falls back to a single-line stats bar written below the box.
     *
     * @param resource $conn
     */
    private function renderMenuWidgets(
        $conn, array $state, array $stats,
        int $menuLeft, int $menuWidth,
        int $boxStartRow, int $boxBottomRow, int $termRows
    ): void {
        $cols         = $state['cols'] ?? 80;
        $sidebarStart = $menuLeft + $menuWidth + 2; // 1-indexed, 1-col gap after menu right edge
        $sidebarAvail = $cols - $sidebarStart + 1;

        if ($sidebarAvail >= 16) {
            $panelWidth = min(22, $sidebarAvail);
            $this->renderDashboardSidebar(
                $conn, $state, $stats,
                $sidebarStart, $panelWidth,
                $boxStartRow, $boxBottomRow, $termRows
            );
        } else {
            // Show bottom bar only if there is at least one spare row below the box
            $promptRow = $boxBottomRow + 2; // blank line + prompt
            if ($termRows > $promptRow) {
                $this->renderDashboardBottomBar($conn, $state, $stats, $menuLeft, $menuWidth);
            }
        }
    }

    /**
     * Draw a dashboard stats panel to the right of the menu using ANSI absolute
     * cursor positioning. Saves and restores cursor so the calling code resumes
     * writing the prompt at the correct location.
     *
     * Widgets are shown in priority order and dropped from the bottom when
     * there is insufficient vertical space: netmail → echomail → online →
     * bulletins → credits.
     *
     * @param resource $conn
     */
    private function renderDashboardSidebar(
        $conn, array $state, array $stats,
        int $startCol, int $panelWidth,
        int $boxStartRow, int $maxRow, int $termRows
    ): void {
        $innerWidth = $panelWidth - 2;
        if ($innerWidth < 8) { return; }

        $availRows = min($maxRow, $termRows - 2) - $boxStartRow + 1;
        if ($availRows < 5) { return; }

        $locale = $state['locale'];
        $chars  = $this->getLineDrawingChars();

        $title   = $this->t('ui.terminalserver.dashboard.title',           'Dashboard', [], $locale);
        $lblNM   = $this->t('ui.terminalserver.dashboard.label.netmail',   'Netmail',   [], $locale);
        $lblECH  = $this->t('ui.terminalserver.dashboard.label.echomail',  'Echomail',  [], $locale);
        $lblOL   = $this->t('ui.terminalserver.dashboard.label.online',    'Online',    [], $locale);
        $lblBull = $this->t('ui.terminalserver.dashboard.label.bulletins', 'Bulletins', [], $locale);
        $lblCred = $this->t('ui.terminalserver.dashboard.label.credits',   'Credits',   [], $locale);

        // Build widget rows; each entry is either 'DIVIDER' or ['label', value].
        // header = 3 rows (top + title + divider), bottom border = 1 row.
        $widgetEntries = [];
        $widgetEntries[] = [$lblNM,  $stats['unread_netmail']];
        $widgetEntries[] = [$lblECH, $stats['new_echomail']];
        $rowsUsed = 3 + count($widgetEntries) + 1; // header + stats + bottom

        if ($rowsUsed + 2 <= $availRows) { // divider + online
            $widgetEntries[] = 'DIVIDER';
            $widgetEntries[] = [$lblOL, $stats['online_count']];
            $rowsUsed += 2;
        }
        if ($rowsUsed + 1 <= $availRows) { // bulletins (same divider group)
            $widgetEntries[] = [$lblBull, $stats['unread_bulletins']];
            $rowsUsed++;
        }
        if ($stats['credit_balance'] !== null && $rowsUsed + 2 <= $availRows) {
            $widgetEntries[] = 'DIVIDER';
            $widgetEntries[] = [$lblCred, $stats['credit_balance']];
        }

        $v      = $chars['v'];
        $topBar = $this->encodeForTerminal($chars['tl'] . str_repeat($chars['h'], $innerWidth) . $chars['tr']);
        $divBar = $this->encodeForTerminal($chars['l_tee'] . str_repeat($chars['h'], $innerWidth) . $chars['r_tee']);
        $botBar = $this->encodeForTerminal($chars['bl'] . str_repeat($chars['h'], $innerWidth) . $chars['br']);

        $titlePadded = $this->fitTerminalLabel($title, $innerWidth, $state);
        $titleLine   = $this->colorize($this->encodeForTerminal($v), self::ANSI_BLUE)
            . $this->colorize($titlePadded, self::ANSI_CYAN . self::ANSI_BOLD)
            . $this->colorize($this->encodeForTerminal($v), self::ANSI_BLUE);

        $lines   = [];
        $lines[] = $this->colorize($topBar, self::ANSI_BLUE);
        $lines[] = $titleLine;
        $lines[] = $this->colorize($divBar, self::ANSI_BLUE);

        $countWidth = min(5, $innerWidth - 2);
        $labelWidth = max(0, $innerWidth - $countWidth - 1);

        foreach ($widgetEntries as $entry) {
            if ($entry === 'DIVIDER') {
                $lines[] = $this->colorize($divBar, self::ANSI_BLUE);
                continue;
            }
            [$label, $value] = $entry;
            $labelStr  = $this->fitTerminalLabel($label, $labelWidth, $state);
            $countStr  = str_pad((string)(int)$value, $countWidth, ' ', STR_PAD_LEFT);
            $innerLine = $this->colorize($labelStr, self::ANSI_DIM)
                . ' '
                . $this->colorize($countStr, self::ANSI_BOLD);
            $lines[]   = $this->colorize($this->encodeForTerminal($v), self::ANSI_BLUE)
                . $innerLine
                . $this->colorize($this->encodeForTerminal($v), self::ANSI_BLUE);
        }
        $lines[] = $this->colorize($botBar, self::ANSI_BLUE);

        foreach ($lines as $i => $line) {
            $row = $boxStartRow + $i;
            if ($row > min($maxRow, $termRows - 1)) { break; }
            $this->safeWrite($conn, "\033[{$row};{$startCol}H");
            $this->safeWrite($conn, $line);
        }
        // Return cursor to the line immediately after the menu box so the
        // calling code can continue writing the blank spacer and prompt there.
        // Using an explicit absolute move instead of \033[s]/\033[u because
        // save/restore is not reliably supported across all terminal emulators.
        $this->safeWrite($conn, "\033[" . ($maxRow + 1) . ";1H");
    }

    /**
     * Render a compact one-line stats bar below the menu box.
     * Used on narrow screens that have no room for a sidebar.
     *
     * @param resource $conn
     */
    private function renderDashboardBottomBar(
        $conn, array $state, array $stats,
        int $menuLeft, int $menuWidth
    ): void {
        $locale  = $state['locale'];
        $lblNM   = $this->t('ui.terminalserver.dashboard.label.netmail',   'Netmail',   [], $locale);
        $lblECH  = $this->t('ui.terminalserver.dashboard.label.echomail',  'Echomail',  [], $locale);
        $lblOL   = $this->t('ui.terminalserver.dashboard.label.online',    'Online',    [], $locale);
        $lblBull = $this->t('ui.terminalserver.dashboard.label.bulletins', 'Bulletins', [], $locale);
        $lblCred = $this->t('ui.terminalserver.dashboard.label.credits',   'Credits',   [], $locale);

        $dot    = $this->colorize(' · ', self::ANSI_BLUE);
        $dotLen = 3; // visible width of ' · '
        $parts  = [];
        $parts[] = $this->colorize($lblNM  . ': ', self::ANSI_DIM) . $this->colorize((string)$stats['unread_netmail'],   self::ANSI_BOLD);
        $parts[] = $this->colorize($lblECH . ': ', self::ANSI_DIM) . $this->colorize((string)$stats['new_echomail'],     self::ANSI_BOLD);
        $parts[] = $this->colorize($lblOL  . ': ', self::ANSI_DIM) . $this->colorize((string)$stats['online_count'],     self::ANSI_BOLD);
        if ($stats['unread_bulletins'] > 0) {
            $parts[] = $this->colorize($lblBull . ': ', self::ANSI_DIM) . $this->colorize((string)$stats['unread_bulletins'], self::ANSI_BOLD);
        }
        if ($stats['credit_balance'] !== null) {
            $parts[] = $this->colorize($lblCred . ': ', self::ANSI_DIM) . $this->colorize((string)$stats['credit_balance'], self::ANSI_BOLD);
        }

        $padLen  = $menuLeft + 1; // columns used by left-margin indent
        $pad     = str_repeat(' ', $padLen);
        $avail   = max(1, ($state['cols'] ?? 80) - $padLen);

        // Strip ANSI escapes to measure visible column width.
        $visLen  = static function (string $s): int {
            return mb_strwidth(preg_replace('/\x1b\[[0-9;]*m/', '', $s), 'UTF-8');
        };

        // Pack parts greedily onto lines; wrap when the next part would overflow.
        $lines      = [];
        $curLine    = '';
        $curLen     = 0;
        foreach ($parts as $i => $part) {
            $partLen = $visLen($part);
            if ($curLine === '') {
                $curLine = $part;
                $curLen  = $partLen;
            } elseif ($curLen + $dotLen + $partLen <= $avail) {
                $curLine .= $dot . $part;
                $curLen  += $dotLen + $partLen;
            } else {
                $lines[]  = $curLine;
                $curLine  = $part;
                $curLen   = $partLen;
            }
        }
        if ($curLine !== '') {
            $lines[] = $curLine;
        }

        foreach ($lines as $line) {
            $this->writeLine($conn, $pad . $line);
        }
    }

    /**
     * Fit a menu label to a fixed terminal cell width.
     */
    private function fitTerminalLabel(string $label, int $width, array $state): string
    {
        if ($width <= 0) {
            return '';
        }

        if ($this->shouldUseAsciiFallback($state)) {
            if (strlen($label) > $width) {
                $label = substr($label, 0, $width);
            }
            return str_pad($label, $width);
        }

        $trimmed = mb_strimwidth($label, 0, $width, '', 'UTF-8');
        $pad = max(0, $width - mb_strwidth($trimmed, 'UTF-8'));
        return $trimmed . str_repeat(' ', $pad);
    }

    /**
     * Normalize text for the current client based on terminal capability detection.
     */
    private function normalizeTerminalTextForClient(string $text, array $state): string
    {
        return match ($this->terminalCharset) {
            'utf8'  => $text,
            'cp437' => $this->convertToCP437($text),
            default => $this->normalizeTerminalAscii($text),
        };
    }

    /**
     * Return true when this session should use ASCII-safe text rendering.
     */
    private function shouldUseAsciiFallback(array $state): bool
    {
        if ($this->isSsh) {
            // SSH clients are typically UTF-8 capable.
            return false;
        }

        $ttype = strtoupper((string)($state['terminal_type'] ?? ''));
        if ($ttype === '') {
            // Conservative default for telnet clients when unknown.
            return true;
        }

        $utf8Hints = ['UTF-8', 'UTF8', 'XTERM', 'ALACRITTY', 'KITTY', 'WEZTERM', 'ITERM', 'VTE'];
        foreach ($utf8Hints as $hint) {
            if (str_contains($ttype, $hint)) {
                return false;
            }
        }

        $asciiHints = ['ANSI', 'VT100', 'VT220', 'IBM', 'PCANSI', 'CP437', 'DUMB'];
        foreach ($asciiHints as $hint) {
            if (str_contains($ttype, $hint)) {
                return true;
            }
        }

        return true;
    }

    /**
     * Transliterate to 7-bit ASCII for terminals that do not render UTF-8 reliably.
     */
    private function normalizeTerminalAscii(string $text): string
    {
        if (!preg_match('/[^\x20-\x7E]/', $text)) {
            return $text;
        }

        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if (is_string($ascii) && $ascii !== '') {
                return $ascii;
            }
        }

        return preg_replace('/[^\x20-\x7E]/', '', $text) ?? $text;
    }

    /**
     * Apply terminal settings from state to session properties.
     */
    public function applyTerminalSettings(array $state): void
    {
        $charset = $state['terminal_charset'] ?? null;
        if ($charset === 'utf8') {
            $this->terminalCharset = 'utf8';
            $this->asciiTextMode   = false;
        } elseif ($charset === 'cp437') {
            $this->terminalCharset = 'cp437';
            $this->asciiTextMode   = false;
        } else {
            $fallback = $this->shouldUseAsciiFallback($state);
            $this->terminalCharset = $fallback ? 'ascii' : 'utf8';
            $this->asciiTextMode   = $fallback;
        }
        $this->ansiColorEnabled = ($state['terminal_ansi_color'] ?? 'yes') !== 'no';
        TelnetUtils::setAnsiColorEnabled($this->ansiColorEnabled);
    }

    /**
     * Encode a UTF-8 string for the current terminal's character set.
     */
    public function encodeForTerminal(string $text): string
    {
        return match ($this->terminalCharset) {
            'utf8'  => $text,
            'cp437' => $this->convertToCP437($text),
            default => $this->normalizeTerminalAscii($text),
        };
    }

    /**
     * Convert a UTF-8 string to CP437, transliterating where possible.
     */
    private function convertToCP437(string $text): string
    {
        if (!preg_match('/[^\x20-\x7E\r\n\t]/', $text)) {
            return $text;
        }
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'CP437//TRANSLIT//IGNORE', $text);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }
        return preg_replace('/[^\x20-\x7E\r\n\t]/', '', $text) ?? $text;
    }

    /**
     * Get line drawing characters for the active terminal character set.
     *
     * @return array{h:string,h_bold:string,v:string,tl:string,tr:string,bl:string,br:string,l_tee:string,r_tee:string}
     */
    private function getLineDrawingChars(): array
    {
        // When ANSI color is disabled, keep framing strictly ASCII to avoid
        // mixed OEM glyph rendering artifacts in monochrome terminal modes.
        if (!$this->ansiColorEnabled || $this->terminalCharset === 'ascii') {
            return [
                'h' => '-',
                'h_bold' => '=',
                'v' => '|',
                'tl' => '+',
                'tr' => '+',
                'bl' => '+',
                'br' => '+',
                'l_tee' => '+',
                'r_tee' => '+',
            ];
        }

        return [
            'h' => '─',
            'h_bold' => '═',
            'v' => '│',
            'tl' => '╔',
            'tr' => '╗',
            'bl' => '╚',
            'br' => '╝',
            'l_tee' => '╠',
            'r_tee' => '╣',
        ];
    }

    /**
     * Public wrapper so helper classes can reuse the terminal's frame glyphs.
     *
     * @return array{h:string,h_bold:string,v:string,tl:string,tr:string,bl:string,br:string,l_tee:string,r_tee:string}
     */
    public function getTerminalLineDrawingChars(): array
    {
        return $this->getLineDrawingChars();
    }

    /**
     * Return the resolved terminal character set for this session ('utf8', 'cp437', or 'ascii').
     *
     * Prefer this over reading $state['terminal_charset'] directly — the instance property
     * is authoritative and handles fallback inference (e.g. SSH clients, TTYPE negotiation)
     * that the raw state key does not capture.
     */
    public function getTerminalCharset(): string
    {
        return $this->terminalCharset;
    }

    /**
     * Remove a translated menu line's leading "<key>) " prefix if present.
     */
    private function stripMenuHotkeyPrefix(string $line, string $hotkey): string
    {
        $pattern = '/^\s*' . preg_quote($hotkey, '/') . '\)\s*/iu';
        $stripped = preg_replace($pattern, '', $line, 1);
        return $stripped ?? $line;
    }

    /**
     * Show a prompt, read a line.  Disables echo for passwords when $echo=false.
     */
    public function prompt($conn, array &$state, string $label, bool $echo = true): ?string
    {
        $this->setEcho($conn, $state, $echo);
        $this->safeWrite($conn, $label);
        $value = $this->readLineWithIdleCheck($conn, $state);
        if (!$echo) {
            $this->setEcho($conn, $state, true);
        }
        return $value;
    }

    /**
     * Control server-side echo.  For Telnet, sends IAC WILL/DONT ECHO commands.
     * SSH terminals manage echo via PTY settings negotiated at the SSH layer.
     */
    private function setEcho($conn, array &$state, bool $enable): void
    {
        $state['input_echo'] = $enable;
        if (!$this->isSsh) {
            $this->sendTelnetCommand($conn, self::WILL, self::OPT_ECHO);
            $this->sendTelnetCommand($conn, self::DONT, self::OPT_ECHO);
        }
    }

    /**
     * Read a line, looping through idle-timeout warnings.
     */
    public function readLineWithIdleCheck($conn, array &$state): ?string
    {
        while (true) {
            [$line, $timedOut, $shouldDisconnect] = $this->readTelnetLineWithTimeout($conn, $state);
            if ($shouldDisconnect) { return null; }
            if ($timedOut) { continue; }
            return $line;
        }
    }

    /**
     * Read a single key token, looping through idle-timeout warnings.
     * Returns normalized tokens: UP, DOWN, LEFT, RIGHT, HOME, END,
     * ENTER, BACKSPACE, CHAR:<c>, or null on disconnect.
     */
    public function readKeyWithIdleCheck($conn, array &$state): ?string
    {
        while (true) {
            [$key, $timedOut, $shouldDisconnect] = $this->readTelnetKeyWithTimeout($conn, $state);
            if ($shouldDisconnect) { return null; }
            if ($timedOut) { continue; }
            return $key;
        }
    }

    /**
     * Read a single key token with an upper-bound timeout.
     *
     * Returns [string|null $key, bool $timedOut, bool $shouldDisconnect].
     * This is intended for interactive full-screen handlers that need to
     * interleave input with periodic polling/redraw work.
     */
    public function readKeyWithTimeout($conn, array &$state, int $timeoutMs): array
    {
        $elapsed   = time() - ($state['last_activity'] ?? time());
        $warnAt    = (int)($state['idle_warning_timeout'] ?? 300);
        $disconnAt = (int)($state['idle_disconnect_timeout'] ?? 420);

        if ($elapsed >= $disconnAt) {
            $this->writeLine($conn, '');
            $this->writeLine($conn, $this->colorize($this->t('ui.terminalserver.server.idle.disconnect', 'Idle timeout - disconnecting...', [], $state['locale'] ?? $this->systemLocale), self::ANSI_YELLOW));
            $this->writeLine($conn, '');
            return [null, true, true];
        }
        if (empty($state['idle_warned']) && $elapsed >= $warnAt) {
            $this->writeLine($conn, '');
            $this->writeLine($conn, $this->colorize($this->t('ui.terminalserver.server.idle.warning_key', 'Are you still there? (Press any key to continue)', [], $state['locale'] ?? $this->systemLocale), self::ANSI_YELLOW . self::ANSI_BOLD));
            $this->writeLine($conn, '');
            $state['idle_warned'] = true;
            return ['', true, false];
        }

        $idleRemainingMs = (($state['idle_warned'] ?? false) ? $disconnAt : $warnAt) - $elapsed;
        $idleRemainingMs = max(0, $idleRemainingMs * 1000);
        $waitMs = max(0, min($timeoutMs, $idleRemainingMs, 30000));

        $read = [$conn];
        $write = $except = null;
        $sec = (int)floor($waitMs / 1000);
        $usec = ($waitMs % 1000) * 1000;
        $hasData = @stream_select($read, $write, $except, $sec, $usec);
        if ($hasData === false) { return [null, false, true]; }
        if ($hasData === 0) {
            if ((time() - ($state['last_activity'] ?? time())) >= $disconnAt) {
                return [null, true, true];
            }
            return ['', true, false];
        }

        $char = $this->readRawChar($conn, $state);
        if ($char === null) {
            return [null, false, true];
        }

        $state['last_activity'] = time();
        $state['idle_warned'] = false;

        if ($char === self::KEY_UP) { return ['UP', false, false]; }
        if ($char === self::KEY_DOWN) { return ['DOWN', false, false]; }
        if ($char === self::KEY_LEFT) { return ['LEFT', false, false]; }
        if ($char === self::KEY_RIGHT) { return ['RIGHT', false, false]; }
        if ($char === self::KEY_HOME) { return ['HOME', false, false]; }
        if ($char === self::KEY_END) { return ['END', false, false]; }
        if ($char === self::KEY_PGUP) { return ['PGUP', false, false]; }
        if ($char === self::KEY_PGDOWN) { return ['PGDOWN', false, false]; }
        if ($char === self::KEY_SHIFT_TAB) { return ['SHIFT_TAB', false, false]; }
        if ($char === self::KEY_DELETE) { return ['DELETE', false, false]; }

        $ord = ord($char[0]);
        if ($ord === 9) { return ['TAB', false, false]; }
        if ($ord === 13) {
            $read = [$conn];
            $write = $except = null;
            if (@stream_select($read, $write, $except, 0, 50000) > 0) {
                $next = $this->readRawChar($conn, $state);
                if ($next !== null && ord($next) !== 10 && ord($next) !== 0) {
                    $state['pushback'] = ($state['pushback'] ?? '') . $next;
                }
            }
            return ['ENTER', false, false];
        }
        if ($ord === 10) { return ['ENTER', false, false]; }
        if ($ord === 8 || $ord === 127) { return ['BACKSPACE', false, false]; }
        if ($ord >= 32 && $ord < 127) { return ['CHAR:' . $char, false, false]; }
        if ($ord === 3) { return ['CTRL_C', false, false]; }
        if ($ord === 5) { return ['CTRL_E', false, false]; }
        if ($ord === 11) { return ['CTRL_K', false, false]; }
        if ($ord === 13) { return ['ENTER', false, false]; }
        if ($ord === 26) { return ['CTRL_Z', false, false]; }

        return ['', false, false];
    }

    // ===== TELNET PROTOCOL =====

    /**
     * Send initial TELNET option negotiations to the client.
     */
    private function negotiateTelnet($conn): void
    {
        // Do NOT negotiate OPT_BINARY: if the client accepts binary mode it stops
        // interpreting 0xFF as IAC, but our ZMODEM layer always doubles 0xFF for
        // telnet transport.  Leaving binary mode un-negotiated keeps both sides
        // consistent — the client continues to IAC-unescape incoming data and
        // IAC-escape outgoing data.
        //
        // Do NOT send DO LINEMODE: some clients (e.g. ZOC) pause display rendering
        // while waiting for the server to send a linemode subnegotiation (IAC SB
        // LINEMODE MODE … IAC SE) that never comes, producing a blank-screen hang
        // until the user presses a key.  A BBS needs character-at-a-time mode;
        // WILL ECHO + WILL SUPPRESS-GA already establish that without linemode.
        //
        // WILL ECHO + DONT ECHO must be sent early so the client disables its own
        // local echo (pty ECHO flag) before the sixel probe sends ESC[c.  Without
        // this, the terminal generates the DA1 response and the telnet client echoes
        // it back to the screen as visible text (^[[?63;...c) before the banner.
        $this->sendTelnetCommand($conn, self::WILL,      self::OPT_ECHO);
        $this->sendTelnetCommand($conn, self::DONT,      self::OPT_ECHO);
        $this->sendTelnetCommand($conn, self::TELNET_DO, self::OPT_NAWS);
        $this->sendTelnetCommand($conn, self::WILL,      self::OPT_SUPPRESS_GA);
        $this->sendTelnetCommand($conn, self::TELNET_DO, self::OPT_TTYPE);
    }

    /**
     * Send a three-byte IAC command.
     */
    private function sendTelnetCommand($conn, int $cmd, int $opt): void
    {
        $this->safeWrite($conn, chr(self::IAC) . chr($cmd) . chr($opt));
    }

    /**
     * Send "TERMINAL-TYPE SEND" subnegotiation request.
     */
    private function requestTerminalType($conn): void
    {
        $this->safeWrite($conn, chr(self::IAC) . chr(self::SB) . chr(self::OPT_TTYPE) . chr(1) . chr(self::IAC) . chr(self::SE));
    }

    /**
     * Probe whether the remote terminal supports ANSI escape sequences by
     * sending a Device Status Report (DSR, ESC[6n) and waiting for a Cursor
     * Position Report (CPR, ESC[row;colR) in response.
     *
     * ANSI-capable terminals reply within a few hundred milliseconds.
     * Dumb terminals, raw TCP clients, and bots produce no response.
     *
     * Any bytes read that are not part of the CPR (e.g. lingering TELNET
     * subnegotiation data) are pushed back into $state['pushback'] so that
     * subsequent reads are not disrupted.
     *
     * @param resource $conn
     * @param array    &$state Session state (pushback used for leftover bytes)
     * @return bool True if a CPR response was received (ANSI supported)
     */
    private function probeAnsiSupport($conn, array &$state): bool
    {
        // Perform the RFC 1091 TTYPE handshake at connection start and use the
        // terminal-type string to determine ANSI color support.
        //
        // We send TTYPE SEND only after receiving IAC WILL TTYPE from the client
        // (proper RFC sequence).  Sending it immediately — before the client
        // acknowledges DO TTYPE — causes many clients to ignore the request.
        //
        // We do NOT send ESC[6n (DSR/CPR): several clients (notably SyncTerm)
        // pause display rendering while waiting to send the CPR reply, producing
        // a blank-screen hang until the user presses a key.

        $buf            = '';
        $deadline       = microtime(true) + 2.0;
        $inSb           = false;
        $sbOpt          = -1;
        $sbData         = '';
        $ttype          = '';
        $sentTtypeSend  = false;

        while (microtime(true) < $deadline) {
            if (!is_resource($conn) || feof($conn)) {
                break;
            }
            $read  = [$conn];
            $write = $except = null;
            $usec  = max(0, (int)(($deadline - microtime(true)) * 1_000_000));
            if (@stream_select($read, $write, $except, 0, $usec) < 1) {
                break;
            }

            $chunk = @fread($conn, 256);
            if ($chunk === false || $chunk === '') {
                if (feof($conn)) { break; }
                continue;
            }

            $i   = 0;
            $len = strlen($chunk);
            while ($i < $len) {
                $byte = ord($chunk[$i]);

                // Inside a subnegotiation — accumulate until IAC SE
                if ($inSb) {
                    if ($byte === self::IAC && $i + 1 < $len && ord($chunk[$i + 1]) === self::SE) {
                        if ($sbOpt === self::OPT_NAWS && strlen($sbData) >= 4) {
                            $w = (ord($sbData[0]) << 8) | ord($sbData[1]);
                            $h = (ord($sbData[2]) << 8) | ord($sbData[3]);
                            if ($w > 0) { $state['cols'] = $w; }
                            if ($h > 0) { $state['rows'] = $h; }
                            if ($this->debug) { $this->log("probe NAWS: {$w}x{$h}"); }
                        } elseif ($sbOpt === self::OPT_TTYPE && strlen($sbData) >= 2 && ord($sbData[0]) === 0) {
                            $ttype = trim(substr($sbData, 1));
                            $this->recordTerminalType($state, $ttype);
                        }
                        $inSb   = false;
                        $sbOpt  = -1;
                        $sbData = '';
                        $i += 2; // skip IAC SE
                    } else {
                        $sbData .= $chunk[$i];
                        $i++;
                    }
                    continue;
                }

                // IAC command handling
                if ($byte === self::IAC && $i + 1 < $len) {
                    $cmd = ord($chunk[$i + 1]);
                    if (in_array($cmd, [self::TELNET_DO, self::DONT, self::WILL, self::WONT], true)) {
                        $opt = ($i + 2 < $len) ? ord($chunk[$i + 2]) : -1;
                        // Send TTYPE SEND as soon as the client agrees to TTYPE
                        if ($cmd === self::WILL && $opt === self::OPT_TTYPE && !$sentTtypeSend) {
                            $this->requestTerminalType($conn);
                            $sentTtypeSend = true;
                        }
                        $i += ($opt >= 0) ? 3 : 2;
                        continue;
                    }
                    if ($cmd === self::SB && $i + 2 < $len) {
                        $sbOpt  = ord($chunk[$i + 2]);
                        $sbData = '';
                        $inSb   = true;
                        $i += 3; // IAC SB OPT
                        continue;
                    }
                    // Unknown IAC — skip the IAC byte only
                    $i++;
                    continue;
                }

                $buf .= $chunk[$i];
                $i++;
            }

            // Stop as soon as we have the TTYPE IS response
            if ($ttype !== '') {
                break;
            }
        }

        // Push back any non-IAC bytes accumulated (e.g. early keystrokes)
        if ($buf !== '') {
            $state['pushback'] = $buf . ($state['pushback'] ?? '');
        }

        // ANSI color: enabled for any recognised terminal type except DUMB / empty
        $ttypeUpper = strtoupper(trim($ttype));
        return $ttypeUpper !== '' && $ttypeUpper !== 'DUMB' && $ttypeUpper !== 'UNKNOWN';
    }

    /**
     * Query the terminal for Sixel graphics support via Primary Device Attributes (ESC[c).
     *
     * Sends the DA1 request and waits briefly for the response (ESC[?<params>c).
     * If attribute 4 appears in the parameter list the terminal supports Sixels.
     * Any non-DA bytes received are pushed back into $state['pushback'] so
     * subsequent reads are not disrupted.
     *
     * @param resource $conn
     * @param array    &$state Session state
     */
    private function probeSixelSupport($conn, array &$state): void
    {
        // Check the pushback buffer first: probeAnsiSupport drains all non-IAC
        // bytes (including ESC sequences) into state['pushback'].  If the
        // terminal sent a proactive DA1 response during the TTYPE exchange we
        // will find it there without needing to send a second ESC[c request.
        $existing = $state['pushback'] ?? '';
        if ($this->debug && $existing !== '') {
            $this->log('Sixel probe pushback: ' . bin2hex($existing));
        }
        if (preg_match('/\033\[\?([0-9;]+)c/', $existing, $m, PREG_OFFSET_CAPTURE)) {
            $attrs = explode(';', $m[1][0]);
            $this->sixelSupported = in_array('4', $attrs, true);
            // Strip the DA1 response from pushback so it is not echoed back
            // to the terminal as if it were user input.
            $daStart = $m[0][1];
            $daEnd   = $daStart + strlen($m[0][0]);
            $state['pushback'] = substr($existing, 0, $daStart) . substr($existing, $daEnd);
            if ($this->debug) {
                $this->log('Sixel probe (from pushback): ' . ($this->sixelSupported ? 'supported' : 'not supported'));
            }
            return;
        }

        // Primary Device Attributes request: CSI c
        $this->safeWrite($conn, "\033[c");

        $deadline = microtime(true) + 1.5;
        $buf      = '';

        while (microtime(true) < $deadline) {
            if (!is_resource($conn) || feof($conn)) {
                break;
            }
            $read  = [$conn];
            $write = $except = null;
            $usec  = max(0, (int)(($deadline - microtime(true)) * 1_000_000));
            if (@stream_select($read, $write, $except, 0, $usec) < 1) {
                break;
            }

            $chunk = @fread($conn, 256);
            if ($chunk === false || $chunk === '') {
                if (feof($conn)) { break; }
                continue;
            }
            $buf .= $chunk;

            // Only break early when the full DA response pattern is present.
            // Breaking on any 'c' could fire prematurely on late telnet
            // negotiation data or other terminal responses arriving in the
            // same read window before the DA reply comes through.
            if (preg_match('/\033\[\?[0-9;]*c/', $buf)) {
                break;
            }
        }

        if ($this->debug) {
            $this->log('Sixel probe raw: ' . bin2hex($buf));
        }

        // Parse Primary DA response: ESC [ ? <params> c
        if (preg_match('/\033\[\?([0-9;]+)c/', $buf, $matches)) {
            $attrs = explode(';', $matches[1]);
            $this->sixelSupported = in_array('4', $attrs, true);

            // Push back any bytes that follow the DA response
            $daEnd    = strpos($buf, $matches[0]) + strlen($matches[0]);
            $leftover = substr($buf, $daEnd);
            if ($leftover !== '') {
                $state['pushback'] = $leftover . ($state['pushback'] ?? '');
            }
        } else {
            // No DA1 response (ESC[?...c) received. Strip any other device
            // attribute responses (DA2 ESC[>...c, DA3 ESC[=...c) before
            // pushing back — they are terminal protocol replies to our probe,
            // not user input, and must not be injected into the input stream.
            $leftover = preg_replace('/\033\[[?>=]?[0-9;]*c/', '', $buf);
            if ($leftover !== '') {
                $state['pushback'] = $leftover . ($state['pushback'] ?? '');
            }
        }

        if ($this->debug) {
            $this->log('Sixel probe: ' . ($this->sixelSupported ? 'supported' : 'not supported'));
        }
    }

    /**
     * Multibyte-aware str_pad. Pads $str to $length visual columns using mb_strlen
     * so that UTF-8 strings with accented characters align correctly in the terminal.
     */
    private function mbStrPad(string $str, int $length, string $padStr = ' ', int $padType = STR_PAD_RIGHT): string
    {
        $pad = max(0, $length - mb_strlen($str, 'UTF-8'));
        $padding = str_repeat($padStr, $pad);
        if ($padType === STR_PAD_BOTH) {
            $left  = (int)floor($pad / 2);
            $right = $pad - $left;
            return str_repeat($padStr, $left) . $str . str_repeat($padStr, $right);
        }
        if ($padType === STR_PAD_LEFT) {
            return $padding . $str;
        }
        return $str . $padding;
    }

    // ===== RATE LIMITING =====

    private function isRateLimited(string $ip): bool
    {
        $this->cleanupOldLoginAttempts();
        return count($this->failedLoginAttempts[$ip] ?? []) >= 5;
    }

    private function cleanupOldLoginAttempts(): void
    {
        $cutoff = time() - 60;
        foreach ($this->failedLoginAttempts as $ip => $attempts) {
            $this->failedLoginAttempts[$ip] = array_filter($attempts, fn($t) => $t > $cutoff);
            if (empty($this->failedLoginAttempts[$ip])) {
                unset($this->failedLoginAttempts[$ip]);
            }
        }
    }

    private function recordFailedLogin(string $ip): void
    {
        $this->cleanupOldLoginAttempts();
        $this->failedLoginAttempts[$ip][] = time();
    }

    private function clearFailedLogins(string $ip): void
    {
        unset($this->failedLoginAttempts[$ip]);
    }

    // ===== BANNER =====

    /**
     * Display the login banner with system info and connection type indicator.
     */
    private function showLoginBanner($conn, array &$state): void
    {
        $this->writeLine($conn, '');
        $this->writeLine($conn, $this->colorize(
            $this->t('ui.terminalserver.server.banner.title', 'BinktermPHP Terminal', [], $state['locale']),
            self::ANSI_MAGENTA . self::ANSI_BOLD
        ));

        if ($this->isSsh) {
            $this->writeLine($conn, $this->colorize('Connected via SSH', self::ANSI_GREEN));
        } elseif ($this->isTls) {
            $this->writeLine($conn, $this->colorize(
                $this->t('ui.terminalserver.server.banner.tls', 'Connected using TLS', [], $state['locale']),
                self::ANSI_GREEN
            ));
        } elseif ($this->tlsEnabled && $this->tlsPort) {
            $this->writeLine($conn, $this->colorize(
                $this->t('ui.terminalserver.server.banner.no_tls', 'Connected without TLS - use port {port} for an encrypted connection', ['port' => $this->tlsPort], $state['locale']),
                self::ANSI_YELLOW
            ));
        }
        $this->writeLine($conn, '');

        if ($this->sixelSupported && TelnetUtils::showSixelScreenIfExists("login.sixel", $this, $conn)) {
            return;
        }

        if (TelnetUtils::showScreenIfExists("login.ans", $this, $conn)) {
            return;
        }

        $config  = BinkpConfig::getInstance();
        $siteUrl = '';
        try { $siteUrl = Config::getSiteUrl(); } catch (\Exception $e) {}

        $rawLines = [
            ['text' => '', 'color' => self::ANSI_DIM, 'center' => false],
            ['text' => $this->t('ui.terminalserver.server.banner.system',   'System: ',   [], $state['locale']) . $config->getSystemName(),     'color' => self::ANSI_CYAN, 'center' => false],
            ['text' => $this->t('ui.terminalserver.server.banner.location', 'Location: ', [], $state['locale']) . $config->getSystemLocation(), 'color' => self::ANSI_DIM,  'center' => false],
            ['text' => $this->t('ui.terminalserver.server.banner.origin',   'Origin: ',   [], $state['locale']) . $config->getSystemOrigin(),   'color' => self::ANSI_DIM,  'center' => false],
        ];
        if ($siteUrl !== '') {
            $rawLines[] = ['text' => '', 'color' => self::ANSI_DIM, 'center' => false];
            $rawLines[] = ['text' => $this->t('ui.terminalserver.server.banner.web', 'Web: ', [], $state['locale']) . $siteUrl, 'color' => self::ANSI_YELLOW, 'center' => false];
        }

        $maxLen = 0;
        foreach ($rawLines as $entry) { $maxLen = max($maxLen, strlen($entry['text'])); }
        $frameWidth = max(48, min(90, $maxLen + 6));
        $innerWidth = $frameWidth - 4;
        $chars      = $this->getLineDrawingChars();
        $border     = $this->encodeForTerminal($chars['tl'] . str_repeat($chars['h'], $frameWidth - 2) . $chars['tr']);
        $bottomBorder = $this->encodeForTerminal($chars['bl'] . str_repeat($chars['h'], $frameWidth - 2) . $chars['br']);
        $cols       = $state['cols'] ?? 80;
        $leftPad    = str_repeat(' ', max(0, (int)floor(($cols - $frameWidth) / 2)));

        $this->writeLine($conn, '');
        $this->writeLine($conn, $leftPad . $this->colorize($border, self::ANSI_MAGENTA));
        foreach ($rawLines as $entry) {
            $text    = $entry['text'];
            $wrapped = wordwrap($text, $innerWidth, "\n", true);
            foreach (explode("\n", $wrapped) as $part) {
                $padded  = $entry['center']
                    ? $this->mbStrPad($part, $innerWidth, ' ', STR_PAD_BOTH)
                    : $this->mbStrPad($part, $innerWidth);
                $line = $chars['v'] . ' ' . $padded . ' ' . $chars['v'];
                $this->writeLine($conn, $leftPad . $this->colorize($this->encodeForTerminal($line), $entry['color']));
            }
        }
        $this->writeLine($conn, $leftPad . $this->colorize($bottomBorder, self::ANSI_MAGENTA));
        $this->writeLine($conn, '');

        if ($siteUrl !== '') {
            $visitLine = $this->t('ui.terminalserver.server.banner.visit_web', 'For a good time visit us on the web @ {url}', ['url' => $siteUrl], $state['locale']);
            $visitPad  = str_repeat(' ', max(0, (int)floor(($cols - strlen($visitLine)) / 2)));
            $this->writeLine($conn, $visitPad . $this->colorize($visitLine, self::ANSI_YELLOW));
            $this->writeLine($conn, '');
        }
    }

    // ===== ANTI-BOT =====

    /**
     * Require the user to press ESC twice before login.
     * Skipped for SSH connections (SSH auth already proves interactivity).
     */
    private function requireEscapeKey($conn, array &$state): bool
    {
        $this->writeLine($conn, '');
        $this->writeLine($conn, $this->colorize(
            $this->t('ui.terminalserver.server.press_esc', 'Press ESC twice to continue...', [], $state['locale']),
            self::ANSI_CYAN
        ));

        $escCount = 0;
        $deadline = time() + 30;
        stream_set_timeout($conn, 2);

        while ($escCount < 2 && time() < $deadline) {
            if (!is_resource($conn) || feof($conn)) { break; }

            $read = [$conn]; $write = $except = null;
            if (@stream_select($read, $write, $except, 2, 0) < 1) { continue; }

            $char = fread($conn, 1);
            if ($char === false || $char === '') {
                if (feof($conn)) { break; }
                continue;
            }

            $byte = ord($char);

            // After CR line termination, swallow one optional LF on next read
            // without using stream_select() lookahead on wrapped streams.
            if (!empty($state['skip_lf_once'])) {
                $state['skip_lf_once'] = false;
                if ($byte === 10) {
                    continue;
                }
            }
            if ($byte === self::IAC) {
                $cmd = fread($conn, 1);
                if ($cmd !== false) {
                    $cmdByte = ord($cmd);
                    if (in_array($cmdByte, [self::TELNET_DO, self::DONT, self::WILL, self::WONT], true)) {
                        fread($conn, 1); // consume option byte
                    } elseif ($cmdByte === self::SB) {
                        // Consume subnegotiation data until IAC SE
                        while (($sb = fread($conn, 1)) !== false) {
                            if (ord($sb) === self::IAC) {
                                $next = fread($conn, 1);
                                if ($next !== false && ord($next) === self::SE) { break; }
                            }
                        }
                    }
                }
                continue;
            }
            if ($byte === 0x1B) {
                $escCount++;
                $this->safeWrite($conn, $this->colorize('*', self::ANSI_GREEN));
            }
        }

        stream_set_timeout($conn, 300);

        if ($escCount >= 2) {
            $this->writeLine($conn, '');
            $this->writeLine($conn, '');
            return true;
        }
        return false;
    }

    // ===== WHO'S ONLINE =====

    /**
     * Returns true if the nodelist table contains at least one entry.
     */
    private function nodelistHasEntries(): bool
    {
        try {
            $db   = \BinktermPHP\Database::getInstance()->getPdo();
            $stmt = $db->query("SELECT 1 FROM nodelist LIMIT 1");
            return (bool)$stmt->fetch();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Show the who's-online list and wait for a keypress.
     */
    private function showWhosOnline($conn, array &$state, string $session): void
    {
        $response = $this->apiRequest('GET', '/api/whosonline', null, $session);
        $users    = $response['data']['users']          ?? [];
        $minutes  = $response['data']['online_minutes'] ?? 15;
        $cols     = $state['cols'] ?? 80;
        $inner    = max(20, min($cols - 2, 78));

        $this->safeWrite($conn, "\033[2J\033[H");
        $this->writeLine($conn, $this->colorize(
            $this->t('ui.terminalserver.server.whos_online.title', "Who's Online (last {minutes} minutes)", ['minutes' => $minutes], $state['locale']),
            self::ANSI_CYAN . self::ANSI_BOLD
        ));
        $this->writeLine($conn, '');

        if (!$users) {
            $this->writeLine($conn, $this->colorize(
                $this->t('ui.terminalserver.server.whos_online.empty', 'No users online.', [], $state['locale']),
                self::ANSI_YELLOW
            ));
        } else {
            $idx = 0;
            foreach ($users as $user) {
                $parts = [$user['username'] ?? 'Unknown'];
                if (!empty($user['location'])) { $parts[] = $user['location']; }
                if (!empty($user['activity'])) { $parts[] = $user['activity']; }
                if (!empty($user['service']))  { $parts[] = $user['service'];  }
                $line    = implode(' | ', $parts);
                $wrapped = wordwrap($line, $inner, "\n", false);
                foreach (explode("\n", $wrapped) as $part) {
                    if (strlen($part) > $inner) { $part = substr($part, 0, $inner - 3) . '...'; }
                    $this->writeLine($conn, $this->colorize($part, ($idx % 2 === 0) ? self::ANSI_GREEN : self::ANSI_CYAN));
                    $idx++;
                }
            }
        }

        $this->writeLine($conn, '');
        $this->writeLine($conn, $this->colorize(
            $this->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $state['locale']),
            self::ANSI_YELLOW
        ));
        $this->readKeyWithIdleCheck($conn, $state);
    }

    /**
     * Show system news from data/systemnews.md before entering the shoutbox.
     */
    private function showSystemNews($conn, array &$state): void
    {
        $markdown = \BinktermPHP\AppearanceConfig::getSystemNewsMarkdown();
        if ($markdown === null || trim($markdown) === '') {
            return;
        }

        $cols = max(40, (int)($state['cols'] ?? 80));
        $contentWidth = max(20, min($cols - 8, 92) - 4);
        $lines = TerminalMarkupRenderer::render('markdown', $markdown, $contentWidth);
        if (!$this->ansiColorEnabled) {
            $lines = array_map(
                static fn(string $line): string => preg_replace('/\033\[[0-9;]*m/', '', $line) ?? $line,
                $lines
            );
        }

        $boxRenderer = new TerminalBoxRenderer($this);
        $boxRenderer->showPagedBox(
            $conn,
            $state,
            'SYSTEM NEWS',
            $lines,
            $this->t('ui.terminalserver.server.press_continue', 'Press any key to continue...', [], $state['locale']),
            4
        );
    }

    // ===== AUTHENTICATION =====

    /**
     * Interactive registration flow.
     *
     * @return bool True if registration was submitted successfully
     */
    private function attemptRegistration($conn, array &$state): bool
    {
        $this->writeLine($conn, '');
        $this->writeLine($conn, $this->colorize(
            $this->t('ui.terminalserver.server.registration.title', '=== New User Registration ===', [], $state['locale']),
            self::ANSI_CYAN . self::ANSI_BOLD
        ));
        $this->writeLine($conn, '');
        $this->writeLine($conn, $this->t('ui.terminalserver.server.registration.intro',       'Please provide the following information to create your account.', [], $state['locale']));
        $this->writeLine($conn, $this->colorize($this->t('ui.terminalserver.server.registration.cancel_hint', '(Type "cancel" at any prompt to abort registration)', [], $state['locale']), self::ANSI_DIM));
        $this->writeLine($conn, '');

        $fields = [
            ['key' => 'ui.terminalserver.server.registration.username', 'fallback' => \BinktermPHP\Config::allowSpacesInUsernames() ? 'Username (3-20 chars, letters/numbers/underscore/spaces): ' : 'Username (3-20 chars, letters/numbers/underscore): ', 'echo' => true,  'var' => 'username'],
            ['key' => 'ui.terminalserver.server.registration.password', 'fallback' => 'Password (min 8 characters): ',                        'echo' => false, 'var' => 'password'],
            ['key' => 'ui.terminalserver.server.registration.confirm',  'fallback' => 'Confirm password: ',                                   'echo' => false, 'var' => 'confirm'],
        ];
        $data = [];
        foreach ($fields as $f) {
            $val = $this->prompt($conn, $state, $this->t($f['key'], $f['fallback'], [], $state['locale']), $f['echo']);
            if (!$f['echo']) { $this->writeLine($conn, ''); }
            if ($val === null || strtolower(trim($val)) === 'cancel') { return false; }
            $data[$f['var']] = trim($val);
        }

        if ($data['password'] !== $data['confirm']) {
            $this->writeLine($conn, $this->colorize(
                $this->t('ui.terminalserver.server.registration.password_mismatch', 'Error: Passwords do not match.', [], $state['locale']),
                self::ANSI_RED
            ));
            $this->writeLine($conn, '');
            return false;
        }

        // Real name (required)
        $val = $this->prompt($conn, $state, $this->t('ui.terminalserver.server.registration.realname', 'Real Name: ', [], $state['locale']), true);
        if ($val === null || strtolower(trim($val)) === 'cancel') { return false; }
        $data['realname'] = trim($val);

        // Email (required)
        do {
            $val = $this->prompt($conn, $state, $this->t('ui.terminalserver.server.registration.email', 'Email: ', [], $state['locale']), true);
            if ($val === null || strtolower(trim($val)) === 'cancel') { return false; }
            $val = trim($val);
            if (empty($val) || !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                $this->writeLine($conn, $this->colorize(
                    $this->t('ui.terminalserver.server.registration.email_invalid', 'A valid email address is required.', [], $state['locale']),
                    self::ANSI_RED
                ));
            }
        } while (empty($val) || !filter_var($val, FILTER_VALIDATE_EMAIL));
        $data['email'] = $val;

        // Location (optional)
        $val = $this->prompt($conn, $state, $this->t('ui.terminalserver.server.registration.location', 'Location (optional): ', [], $state['locale']), true);
        if ($val === null || strtolower(trim($val)) === 'cancel') { return false; }
        $data['location'] = trim($val);

        // Reason for joining (required)
        do {
            $val = $this->prompt($conn, $state, $this->t('ui.terminalserver.server.registration.reason', 'Reason for joining: ', [], $state['locale']), true);
            if ($val === null || strtolower(trim($val)) === 'cancel') { return false; }
            $val = trim($val);
            if (empty($val)) {
                $this->writeLine($conn, $this->colorize(
                    $this->t('ui.terminalserver.server.registration.reason_required', 'Please provide a reason for joining.', [], $state['locale']),
                    self::ANSI_RED
                ));
            }
        } while (empty($val));
        $data['reason'] = $val;

        $this->writeLine($conn, '');
        $this->writeLine($conn, $this->t('ui.terminalserver.server.registration.submitting', 'Submitting registration...', [], $state['locale']));

        try {
            $result = $this->apiRequest('POST', '/api/register', [
                'username'  => $data['username'],
                'password'  => $data['password'],
                'real_name' => $data['realname'],
                'email'     => $data['email'],
                'location'  => $data['location'],
                'reason'    => $data['reason'],
            ], null);

            if ($result['status'] === 200 || $result['status'] === 201) {
                $this->writeLine($conn, '');
                $this->writeLine($conn, $this->colorize($this->t('ui.terminalserver.server.registration.success', 'Registration successful!', [], $state['locale']), self::ANSI_GREEN . self::ANSI_BOLD));
                $this->writeLine($conn, '');
                $this->writeLine($conn, $this->t('ui.terminalserver.server.registration.pending',        'Your account has been created and is pending approval.', [], $state['locale']));
                $this->writeLine($conn, $this->t('ui.terminalserver.server.registration.pending_review', 'You will be notified once an administrator has reviewed your registration.', [], $state['locale']));
                $this->writeLine($conn, '');
                return true;
            }

            $errorMsg = $result['data']['error'] ?? 'Registration failed';
            $this->writeLine($conn, '');
            $this->writeLine($conn, $this->colorize('Error: ' . $errorMsg, self::ANSI_RED));
            $this->writeLine($conn, '');
            return false;
        } catch (\Throwable $e) {
            $this->writeLine($conn, '');
            $this->writeLine($conn, $this->colorize('Error: ' . $e->getMessage(), self::ANSI_RED));
            $this->writeLine($conn, '');
            return false;
        }
    }

    /**
     * Begin the lost-password reset flow used by the web form.
     */
    private function attemptLostPasswordReset($conn, array &$state): void
    {
        $this->writeLine($conn, '');
        $this->writeLine($conn, $this->colorize(
            $this->t('ui.terminalserver.server.reset_password.title', '=== Password Reset ===', [], $state['locale']),
            self::ANSI_CYAN . self::ANSI_BOLD
        ));
        $this->writeLine($conn, '');
        $this->writeLine($conn, $this->t(
            'ui.terminalserver.server.reset_password.intro',
            'Enter your username, real name, or email address and we will email you a password reset link if the account exists.',
            [],
            $state['locale']
        ));
        $this->writeLine($conn, $this->colorize(
            $this->t('ui.terminalserver.server.reset_password.cancel_hint', '(Press Enter on a blank prompt to cancel)', [], $state['locale']),
            self::ANSI_DIM
        ));
        $this->writeLine($conn, '');

        $identifier = $this->prompt(
            $conn,
            $state,
            $this->t(
                'ui.terminalserver.server.reset_password.identifier_prompt',
                'Username, real name, or email: ',
                [],
                $state['locale']
            ),
            true
        );

        if ($identifier === null || trim($identifier) === '') {
            return;
        }

        $this->writeLine($conn, '');
        $this->writeLine($conn, $this->t(
            'ui.terminalserver.server.reset_password.submitting',
            'Requesting password reset...',
            [],
            $state['locale']
        ));

        try {
            $result = $this->apiRequest('POST', '/api/auth/forgot-password', [
                'usernameOrEmail' => trim($identifier),
            ], null);
        } catch (\Throwable $e) {
            $this->log("Password reset request failed: " . $e->getMessage());
            $this->writeLine($conn, '');
            $this->writeLine($conn, $this->colorize(
                $this->t(
                    'ui.terminalserver.server.reset_password.failed',
                    'Failed to process password reset request.',
                    [],
                    $state['locale']
                ),
                self::ANSI_RED
            ));
            $this->writeLine($conn, '');
            $this->writeLine($conn, $this->colorize(
                $this->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $state['locale']),
                self::ANSI_YELLOW
            ));
            $this->readRawChar($conn, $state);
            return;
        }

        if ($result['status'] >= 200 && $result['status'] < 300 && !empty($result['data']['success'])) {
            $this->writeLine($conn, '');
            $this->writeLine($conn, $this->colorize(
                $this->t(
                    'ui.terminalserver.server.reset_password.success',
                    'If an account with that username, real name, or email exists, a password reset link has been sent.',
                    [],
                    $state['locale']
                ),
                self::ANSI_GREEN
            ));
            $this->writeLine($conn, $this->t(
                'ui.terminalserver.server.reset_password.check_email',
                'Please check your email inbox and spam folder for the reset link.',
                [],
                $state['locale']
            ));
        } else {
            $errorMessage = trim((string)($result['data']['error'] ?? ''));
            $this->log(
                'Password reset request failed'
                . ($result['status'] ? " (HTTP {$result['status']})" : '')
                . ($errorMessage !== '' ? ": {$errorMessage}" : '')
            );
            $this->writeLine($conn, '');
            $this->writeLine($conn, $this->colorize(
                $this->t(
                    'ui.terminalserver.server.reset_password.failed',
                    'Failed to process password reset request.',
                    [],
                    $state['locale']
                ),
                self::ANSI_RED
            ));
        }

        $this->writeLine($conn, '');
        $this->writeLine($conn, $this->colorize(
            $this->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $state['locale']),
            self::ANSI_YELLOW
        ));
        $this->readRawChar($conn, $state);
    }

    /**
     * Prompt for username + password and call the login API.
     *
     * @return array|null ['session'=>..., 'username'=>..., 'csrf_token'=>...] or null on failure
     */
    private function attemptLogin($conn, array &$state, string &$attemptedUsername = ''): ?array
    {
        $username = $this->prompt($conn, $state, $this->t('ui.terminalserver.server.login.username_prompt', 'Username: ', [], $state['locale']), true);
        if ($username === null) { return null; }
        $attemptedUsername = $username;

        $password = $this->prompt($conn, $state, $this->t('ui.terminalserver.server.login.password_prompt', 'Password: ', [], $state['locale']), false);
        if ($password === null) { return null; }
        $this->writeLine($conn, '');

        $transport = $this->isSsh ? 'ssh' : 'telnet';
        try {
            $result = $this->apiRequest('POST', '/api/auth/login', [
                'username' => $username,
                'password' => $password,
                'service'  => $transport,
            ], null);
        } catch (\Throwable $e) {
            $this->writeLine($conn, $this->colorize('Login failed: ' . $e->getMessage(), self::ANSI_RED));
            return null;
        }

        if ($result['status'] !== 200 || empty($result['cookie'])) {
            return null;
        }

        return [
            'session'    => $result['cookie'],
            'username'   => $username,
            'csrf_token' => $result['data']['csrf_token'] ?? null,
        ];
    }

    // ===== READ WITH IDLE TIMEOUT =====

    /**
     * Read a line from the socket with idle-timeout management.
     * Returns [string|null $line, bool $timedOut, bool $shouldDisconnect].
     */
    private function readTelnetLineWithTimeout($conn, array &$state): array
    {
        $elapsed    = time() - $state['last_activity'];
        $warnAt     = $state['idle_warning_timeout'];
        $disconnAt  = $state['idle_disconnect_timeout'];

        if ($elapsed >= $disconnAt) {
            $this->writeLine($conn, '');
            $this->writeLine($conn, $this->colorize($this->t('ui.terminalserver.server.idle.disconnect', 'Idle timeout - disconnecting...', [], $state['locale']), self::ANSI_YELLOW));
            $this->writeLine($conn, '');
            return [null, true, true];
        }
        if (!$state['idle_warned'] && $elapsed >= $warnAt) {
            $this->writeLine($conn, '');
            $this->writeLine($conn, $this->colorize($this->t('ui.terminalserver.server.idle.warning_line', 'Are you still there? (Press Enter to continue)', [], $state['locale']), self::ANSI_YELLOW . self::ANSI_BOLD));
            $this->writeLine($conn, '');
            $state['idle_warned'] = true;
            return ['', true, false];
        }

        $timeout = $state['idle_warned']
            ? min($disconnAt - $elapsed, 30)
            : min($warnAt - $elapsed, 30);

        $read = [$conn]; $write = $except = null;
        $hasData = @stream_select($read, $write, $except, (int)$timeout, 0);
        if ($hasData === false) { return [null, false, true]; }
        if ($hasData === 0)     { return ['', true, false]; }

        $line = $this->readTelnetLine($conn, $state);
        if ($line !== null) {
            $state['last_activity'] = time();
            $state['idle_warned']   = false;
        }
        return [$line, false, false];
    }

    /**
     * Read a single key with idle-timeout management.
     * Returns [string|null $key, bool $timedOut, bool $shouldDisconnect].
     */
    private function readTelnetKeyWithTimeout($conn, array &$state): array
    {
        $elapsed   = time() - $state['last_activity'];
        $warnAt    = $state['idle_warning_timeout'];
        $disconnAt = $state['idle_disconnect_timeout'];

        if ($elapsed >= $disconnAt) {
            $this->writeLine($conn, '');
            $this->writeLine($conn, $this->colorize($this->t('ui.terminalserver.server.idle.disconnect', 'Idle timeout - disconnecting...', [], $state['locale']), self::ANSI_YELLOW));
            $this->writeLine($conn, '');
            return [null, true, true];
        }
        if (!$state['idle_warned'] && $elapsed >= $warnAt) {
            $this->writeLine($conn, '');
            $this->writeLine($conn, $this->colorize($this->t('ui.terminalserver.server.idle.warning_key', 'Are you still there? (Press any key to continue)', [], $state['locale']), self::ANSI_YELLOW . self::ANSI_BOLD));
            $this->writeLine($conn, '');
            $state['idle_warned'] = true;
            return ['', true, false];
        }

        $timeout = $state['idle_warned']
            ? min($disconnAt - $elapsed, 30)
            : min($warnAt - $elapsed, 30);

        $read = [$conn]; $write = $except = null;
        $hasData = @stream_select($read, $write, $except, (int)$timeout, 0);
        if ($hasData === false) { return [null, false, true]; }
        if ($hasData === 0)     { return ['', true, false]; }

        $char = $this->readRawChar($conn, $state);
        if ($char === null) { return [null, false, true]; }

        $state['last_activity'] = time();
        $state['idle_warned']   = false;

        if ($char === self::KEY_UP)    { return ['UP',    false, false]; }
        if ($char === self::KEY_DOWN)  { return ['DOWN',  false, false]; }
        if ($char === self::KEY_LEFT)  { return ['LEFT',  false, false]; }
        if ($char === self::KEY_RIGHT) { return ['RIGHT', false, false]; }
        if ($char === self::KEY_HOME)   { return ['HOME',   false, false]; }
        if ($char === self::KEY_END)    { return ['END',    false, false]; }
        if ($char === self::KEY_PGUP)   { return ['PGUP',   false, false]; }
        if ($char === self::KEY_PGDOWN) { return ['PGDOWN', false, false]; }
        if ($char === self::KEY_SHIFT_TAB) { return ['SHIFT_TAB', false, false]; }

        $ord = ord($char[0]);
        if ($ord === 9)                          { return ['TAB',       false, false]; }
        if ($ord === 13) {
            $read = [$conn]; $write = $except = null;
            if (@stream_select($read, $write, $except, 0, 50000) > 0) {
                $next = $this->readRawChar($conn, $state);
                if ($next !== null && ord($next) !== 10 && ord($next) !== 0) {
                    $state['pushback'] = ($state['pushback'] ?? '') . $next;
                }
            }
            return ['ENTER', false, false];
        }
        if ($ord === 10)                         { return ['ENTER',     false, false]; }
        if ($ord === 8 || $ord === 127)          { return ['BACKSPACE', false, false]; }
        if ($ord >= 32 && $ord < 127)            { return ['CHAR:' . $char, false, false]; }

        return ['', false, false];
    }

    /**
     * Read a full line from the socket, processing TELNET protocol bytes in-band.
     */
    private function readTelnetLine($conn, array &$state): ?string
    {
        if (!is_resource($conn) || feof($conn)) { return null; }
        $meta = stream_get_meta_data($conn);
        if ($meta['timed_out']) { return null; }

        $line = '';
        while (true) {
            if (!empty($state['pushback'])) {
                $char = $state['pushback'][0];
                $state['pushback'] = substr($state['pushback'], 1);
            } else {
                $char = fread($conn, 1);
            }

            if ($char === false || $char === '') {
                if (!is_resource($conn) || feof($conn)) { return null; }
                $meta = stream_get_meta_data($conn);
                if ($meta['timed_out']) { return null; }
                continue;
            }

            $byte = ord($char);

            // ---- TELNET state machine ----
            if (!empty($state['telnet_mode'])) {
                if ($state['telnet_mode'] === 'IAC') {
                    if ($byte === self::IAC) {
                        $line .= chr(self::IAC);
                        $state['telnet_mode'] = null;
                    } elseif (in_array($byte, [self::TELNET_DO, self::DONT, self::WILL, self::WONT], true)) {
                        $state['telnet_mode'] = 'IAC_CMD';
                        $state['telnet_cmd']  = $byte;
                    } elseif ($byte === self::SB) {
                        $state['telnet_mode'] = 'SB';
                        $state['sb_opt']      = null;
                        $state['sb_data']     = '';
                    } else {
                        $state['telnet_mode'] = null;
                    }
                    continue;
                }
                if ($state['telnet_mode'] === 'IAC_CMD') {
                    if (($state['telnet_cmd'] ?? null) === self::WILL && $byte === self::OPT_TTYPE) {
                        $this->requestTerminalType($conn);
                    }
                    $state['telnet_mode'] = null;
                    $state['telnet_cmd']  = null;
                    continue;
                }
                if ($state['telnet_mode'] === 'SB') {
                    if ($state['sb_opt'] === null) { $state['sb_opt'] = $byte; continue; }
                    if ($byte === self::IAC)        { $state['telnet_mode'] = 'SB_IAC'; continue; }
                    $state['sb_data'] .= chr($byte);
                    continue;
                }
                if ($state['telnet_mode'] === 'SB_IAC') {
                    if ($byte === self::SE) {
                        if ($state['sb_opt'] === self::OPT_NAWS && strlen($state['sb_data']) >= 4) {
                            $w = (ord($state['sb_data'][0]) << 8) + ord($state['sb_data'][1]);
                            $h = (ord($state['sb_data'][2]) << 8) + ord($state['sb_data'][3]);
                            if ($w > 0) { $state['cols'] = $w; }
                            if ($h > 0) { $state['rows'] = $h; }
                            if ($this->debug) { $this->log("NAWS: {$w}x{$h}"); }
                        } elseif ($state['sb_opt'] === self::OPT_TTYPE && strlen($state['sb_data']) >= 2 && ord($state['sb_data'][0]) === 0) {
                            $ttype = trim(substr($state['sb_data'], 1));
                            $this->recordTerminalType($state, $ttype);
                        }
                        $state['telnet_mode'] = null;
                        $state['sb_opt']      = null;
                        $state['sb_data']     = '';
                        continue;
                    }
                    if ($byte === self::IAC) {
                        $state['sb_data']     .= chr(self::IAC);
                        $state['telnet_mode']  = 'SB';
                        continue;
                    }
                    $state['telnet_mode'] = 'SB';
                    continue;
                }
            }

            if ($byte === self::IAC) { $state['telnet_mode'] = 'IAC'; continue; }

            // Consume the LF (or NUL) that follows a CR in non-binary telnet.
            if (!empty($state['skip_lf_once'])) {
                $state['skip_lf_once'] = false;
                if ($byte === 10 || $byte === 0) { continue; }
            }

            if ($byte === 10) {
                if (!empty($state['input_echo'])) { $this->safeWrite($conn, "\r\n"); }
                return $line;
            }
            if ($byte === 13) {
                $state['skip_lf_once'] = true;
                if (!empty($state['input_echo'])) { $this->safeWrite($conn, "\r\n"); }
                return $line;
            }
            if ($byte === 8 || $byte === 127) {
                if ($line !== '') {
                    $line = substr($line, 0, -1);
                    if (!empty($state['input_echo'])) { $this->safeWrite($conn, "\x08 \x08"); }
                }
                continue;
            }
            if ($byte === 0) { continue; }

            $line .= chr($byte);
            if (!empty($state['input_echo'])) { $this->safeWrite($conn, chr($byte)); }
        }
    }

    // ===== EDITOR =====

    /**
     * Read multiline input: full-screen editor if terminal is tall enough, else line-by-line.
     */
    public function readMultiline($conn, array &$state, int $cols, string $initialText = '', array $editorContext = []): string
    {
        if (($state['rows'] ?? 0) >= 15) {
            return $this->fullScreenEditor($conn, $state, $initialText, $editorContext);
        }

        if ($initialText !== '') {
            $this->writeLine($conn, $this->t('ui.terminalserver.editor.starting_text', 'Starting with quoted text. Enter your reply below.', [], $state['locale']));
            $this->writeLine($conn, '');
            foreach (explode("\n", $initialText) as $l) { $this->writeLine($conn, $l); }
            $this->writeLine($conn, '');
        }

        $this->writeLine($conn, $this->t('ui.terminalserver.editor.instructions', 'Enter message text. End with a single "." line. Type "/abort" to cancel.', [], $state['locale']));
        $lines = $initialText !== '' ? explode("\n", $initialText) : [];

        while (true) {
            $this->safeWrite($conn, '> ');
            $line = $this->readLineWithIdleCheck($conn, $state);
            if ($line === null)            { break; }
            if (trim($line) === '/abort')  { return ''; }
            if (trim($line) === '.')       { break; }
            $lines[] = $line;
        }

        $text = implode("\n", $lines);
        return $text;
    }

    /**
     * Full-screen vi-style message editor.
     */
    private function fullScreenEditor($conn, array &$state, string $initialText = '', array $editorContext = []): string
    {
        $rows = $state['rows'] ?? 24;
        $cols = $state['cols'] ?? 80;
        if ($this->debug) { $this->log("Editor: {$cols}x{$rows}"); }

        $this->safeWrite($conn, "\033[2J\033[H\033[?25h");
        $width     = min($cols - 2, 70);
        $separator = str_repeat('=', $width);
        $title     = $editorContext['title'] ?? $this->t('ui.terminalserver.editor.title', 'MESSAGE EDITOR - FULL SCREEN MODE', [], $state['locale']);
        $shortcuts = $editorContext['shortcuts'] ?? $this->t('ui.terminalserver.editor.shortcuts', 'Ctrl+K=Help  Ctrl+Z=Send  Ctrl+C=Cancel', [], $state['locale']);
        $saved     = $editorContext['saved'] ?? $this->t('ui.terminalserver.editor.saved', 'Message saved and ready to send.', [], $state['locale']);

        $headerLines = 0;
        $this->writeLine($conn, $this->colorize($separator, self::ANSI_CYAN . self::ANSI_BOLD)); $headerLines++;
        $this->writeLine($conn, $this->colorize($title, self::ANSI_CYAN . self::ANSI_BOLD)); $headerLines++;
        $this->writeLine($conn, $this->colorize($separator, self::ANSI_CYAN . self::ANSI_BOLD)); $headerLines++;
        $this->writeLine($conn, $this->colorize($shortcuts, self::ANSI_YELLOW)); $headerLines++;
        $this->writeLine($conn, $this->colorize($separator, self::ANSI_CYAN . self::ANSI_BOLD)); $headerLines++;

        $lines     = $initialText !== '' ? explode("\n", $initialText) ?: [''] : [''];
        $editorWidth = max(10, $cols - 2);
        $cursorRow = 0;
        $cursorCol = 0;
        [$lines, $cursorRow, $cursorCol] = $this->wrapEditorLines($lines, $cursorRow, $cursorCol, $editorWidth);
        $viewTop   = 0;
        $startRow  = $headerLines + 1;
        $maxRows   = max(10, $rows - $startRow - 2);

        $this->setEcho($conn, $state, false);
        $this->safeWrite($conn, "\033[?25h");

        while (true) {
            $this->safeWrite($conn, "\033[{$startRow};1H\033[J");

            $maxTop = max(0, count($lines) - $maxRows);
            if ($viewTop > $maxTop)                        { $viewTop = $maxTop; }
            if ($cursorRow < $viewTop)                     { $viewTop = $cursorRow; }
            elseif ($cursorRow >= $viewTop + $maxRows)     { $viewTop = $cursorRow - $maxRows + 1; }

            foreach (array_slice($lines, $viewTop, $maxRows) as $idx => $line) {
                $this->safeWrite($conn, "\033[" . ($startRow + $idx) . ";1H" . substr($line, 0, $cols - 1));
            }

            $displayRow = $startRow + ($cursorRow - $viewTop);
            $displayCol = $cursorCol + 1;
            $this->safeWrite($conn, "\033[{$displayRow};{$displayCol}H");

            $char = $this->readRawChar($conn, $state);
            if ($char === null) { $this->setEcho($conn, $state, true); return ''; }

            $ord = ord($char[0]);

            if ($ord === 26) { break; }  // Ctrl+Z — send
            if ($ord === 3)  { $this->setEcho($conn, $state, true); $this->writeLine($conn, ''); $this->writeLine($conn, $this->colorize($this->t('ui.terminalserver.editor.cancelled', 'Message cancelled.', [], $state['locale']), self::ANSI_RED)); return ''; }
            if ($ord === 25) { // Ctrl+Y — delete line
                if (count($lines) > 1) { array_splice($lines, $cursorRow, 1); if ($cursorRow >= count($lines)) { $cursorRow = count($lines) - 1; } $cursorCol = min($cursorCol, strlen($lines[$cursorRow])); }
                else { $lines[0] = ''; $cursorCol = 0; }
                continue;
            }
            if ($ord === 11) { $this->showEditorHelp($conn, $state, $editorContext); continue; }  // Ctrl+K
            if ($ord === 1)  { $cursorCol = 0; continue; }  // Ctrl+A
            if ($ord === 5)  { $cursorCol = strlen($lines[$cursorRow]); continue; }  // Ctrl+E

            if ($char === self::KEY_UP)    { if ($cursorRow > 0)                    { $cursorRow--; $cursorCol = min($cursorCol, strlen($lines[$cursorRow])); } continue; }
            if ($char === self::KEY_DOWN)  { if ($cursorRow < count($lines) - 1)    { $cursorRow++; $cursorCol = min($cursorCol, strlen($lines[$cursorRow])); } continue; }
            if ($char === self::KEY_LEFT)  { if ($cursorCol > 0) { $cursorCol--; } elseif ($cursorRow > 0) { $cursorRow--; $cursorCol = strlen($lines[$cursorRow]); } continue; }
            if ($char === self::KEY_RIGHT) { if ($cursorCol < strlen($lines[$cursorRow])) { $cursorCol++; } elseif ($cursorRow < count($lines) - 1) { $cursorRow++; $cursorCol = 0; } continue; }
            if ($char === self::KEY_HOME)  { $cursorCol = 0; continue; }
            if ($char === self::KEY_END)   { $cursorCol = strlen($lines[$cursorRow]); continue; }

            if ($ord === 13 || $ord === 10) {
                if ($ord === 13) {
                    $next = $this->readRawChar($conn, $state);
                    if ($next !== null && ord($next[0]) !== 10) { $state['pushback'] = ($state['pushback'] ?? '') . $next; }
                }
                $before = substr($lines[$cursorRow], 0, $cursorCol);
                $after  = substr($lines[$cursorRow], $cursorCol);
                $lines[$cursorRow] = $before;
                array_splice($lines, $cursorRow + 1, 0, [$after]);
                $cursorRow++; $cursorCol = 0;
                continue;
            }

            if ($ord === 8 || $ord === 127) {
                if ($cursorCol > 0) {
                    $lines[$cursorRow] = substr($lines[$cursorRow], 0, $cursorCol - 1) . substr($lines[$cursorRow], $cursorCol);
                    $cursorCol--;
                } elseif ($cursorRow > 0) {
                    $prev = $lines[$cursorRow - 1];
                    $cursorCol = strlen($prev);
                    $lines[$cursorRow - 1] = $prev . $lines[$cursorRow];
                    array_splice($lines, $cursorRow, 1);
                    $cursorRow--;
                }
                [$lines, $cursorRow, $cursorCol] = $this->wrapEditorLines($lines, $cursorRow, $cursorCol, $editorWidth);
                continue;
            }
            if ($char === self::KEY_DELETE) {
                if ($cursorCol < strlen($lines[$cursorRow])) {
                    $lines[$cursorRow] = substr($lines[$cursorRow], 0, $cursorCol) . substr($lines[$cursorRow], $cursorCol + 1);
                } elseif ($cursorRow < count($lines) - 1) {
                    $lines[$cursorRow] .= $lines[$cursorRow + 1];
                    array_splice($lines, $cursorRow + 1, 1);
                }
                [$lines, $cursorRow, $cursorCol] = $this->wrapEditorLines($lines, $cursorRow, $cursorCol, $editorWidth);
                continue;
            }
            if ($ord >= 32 && $ord < 127) {
                $lines[$cursorRow] = substr($lines[$cursorRow], 0, $cursorCol) . $char . substr($lines[$cursorRow], $cursorCol);
                $cursorCol++;
                [$lines, $cursorRow, $cursorCol] = $this->wrapEditorLines($lines, $cursorRow, $cursorCol, $editorWidth);
            }
        }

        $this->setEcho($conn, $state, true);
        $this->safeWrite($conn, "\033[" . ($startRow + $maxRows + 1) . ";1H");
        $this->writeLine($conn, '');
        $this->writeLine($conn, $this->colorize($saved, self::ANSI_GREEN));
        $this->writeLine($conn, '');

        while (count($lines) > 0 && trim($lines[count($lines) - 1]) === '') { array_pop($lines); }
        return implode("\n", $lines);
    }

    /**
     * Hard-wrap editor buffer lines to the current terminal width.
     *
     * @param string[] $lines
     * @return array{0: string[], 1: int, 2: int}
     */
    private function wrapEditorLines(array $lines, int $cursorRow, int $cursorCol, int $width): array
    {
        $width = max(10, $width);

        for ($row = 0; $row < count($lines); $row++) {
            while (strlen($lines[$row]) > $width) {
                $breakAt = $this->findEditorWrapColumn($lines[$row], $width);
                $removeSpace = ($breakAt < strlen($lines[$row]) && $lines[$row][$breakAt] === ' ');
                $left = substr($lines[$row], 0, $breakAt);
                $right = substr($lines[$row], $breakAt + ($removeSpace ? 1 : 0));

                $lines[$row] = rtrim($left, ' ');
                array_splice($lines, $row + 1, 0, [$right]);

                if ($cursorRow > $row) {
                    $cursorRow++;
                } elseif ($cursorRow === $row && $cursorCol > $breakAt) {
                    $cursorRow++;
                    $cursorCol -= $breakAt + ($removeSpace ? 1 : 0);
                }
            }
        }

        $cursorRow = min(max(0, $cursorRow), count($lines) - 1);
        $cursorCol = min(max(0, $cursorCol), strlen($lines[$cursorRow]));

        return [$lines, $cursorRow, $cursorCol];
    }

    /**
     * Choose a wrap point at or before width, preferring word boundaries.
     */
    private function findEditorWrapColumn(string $line, int $width): int
    {
        $candidate = substr($line, 0, $width + 1);
        $spaceAt = strrpos($candidate, ' ');

        if ($spaceAt !== false && $spaceAt > 0) {
            return $spaceAt;
        }

        return $width;
    }

    /**
     * Display editor help overlay and wait for a keypress.
     */
    private function showEditorHelp($conn, array &$state, array $editorContext = []): void
    {
        $this->safeWrite($conn, "\033[2J\033[H");
        $helpTitle = $editorContext['help_title'] ?? $this->t('ui.terminalserver.editor.help.title', 'MESSAGE EDITOR HELP', [], $state['locale']);
        $helpSave = $editorContext['help_save'] ?? $this->t('ui.terminalserver.editor.help.save', 'Ctrl+Z = Save message and send', [], $state['locale']);
        $helpCancel = $editorContext['help_cancel'] ?? $this->t('ui.terminalserver.editor.help.cancel', 'Ctrl+C = Cancel and discard message', [], $state['locale']);
        $this->writeLine($conn, $this->colorize($helpTitle, self::ANSI_CYAN . self::ANSI_BOLD));
        $this->writeLine($conn, $this->colorize($this->t('ui.terminalserver.editor.help.separator',   '-------------------',                  [], $state['locale']), self::ANSI_CYAN));
        $this->writeLine($conn, $this->colorize($this->t('ui.terminalserver.editor.help.navigate',    'Arrow Keys = Navigate cursor',         [], $state['locale']), self::ANSI_YELLOW));
        $this->writeLine($conn, $this->colorize($this->t('ui.terminalserver.editor.help.edit',        'Backspace/Delete = Edit text',         [], $state['locale']), self::ANSI_YELLOW));
        $this->writeLine($conn, $this->colorize($this->t('ui.terminalserver.editor.help.help',        'Ctrl+K = Help',                       [], $state['locale']), self::ANSI_YELLOW));
        $this->writeLine($conn, $this->colorize($this->t('ui.terminalserver.editor.help.start_of_line','Ctrl+A = Start of line',             [], $state['locale']), self::ANSI_YELLOW));
        $this->writeLine($conn, $this->colorize($this->t('ui.terminalserver.editor.help.end_of_line', 'Ctrl+E = End of line',                [], $state['locale']), self::ANSI_YELLOW));
        $this->writeLine($conn, $this->colorize($this->t('ui.terminalserver.editor.help.delete_line', 'Ctrl+Y = Delete entire line',         [], $state['locale']), self::ANSI_YELLOW));
        $this->writeLine($conn, $this->colorize($helpSave, self::ANSI_GREEN));
        $this->writeLine($conn, $this->colorize($helpCancel, self::ANSI_RED));
        $this->writeLine($conn, '');
        $this->writeLine($conn, $this->colorize($this->t('ui.terminalserver.server.press_any_key', 'Press any key to return...', [], $state['locale']), self::ANSI_YELLOW));
        $this->readRawChar($conn, $state);
        $this->safeWrite($conn, "\033[?25h");
    }

    // ===== RAW CHARACTER READ =====

    /**
     * Read one character from the socket, handling TELNET IAC and ANSI escape sequences.
     * Returns null on disconnect.
     */
    public function readRawChar($conn, array &$state): ?string
    {
        if (!is_resource($conn) || feof($conn)) { return null; }

        if (!empty($state['pushback'])) {
            $char = $state['pushback'][0];
            $state['pushback'] = substr($state['pushback'], 1);
            return $char;
        }

        $char = fread($conn, 1);
        if ($char === false || $char === '') { return null; }

        $byte = ord($char);

        // Consume the LF or NUL that follows a CR in non-binary telnet mode.
        if (!empty($state['skip_lf_once'])) {
            $state['skip_lf_once'] = false;
            if ($byte === 10 || $byte === 0) {
                return $this->readRawChar($conn, $state);
            }
        }

        // Handle TELNET IAC sequences
        if ($byte === self::IAC) {
            $cmd = fread($conn, 1);
            if ($cmd === false) { return null; }
            $cmdByte = ord($cmd);
            if ($cmdByte === self::IAC) { return chr(self::IAC); }
            if (in_array($cmdByte, [self::TELNET_DO, self::DONT, self::WILL, self::WONT], true)) {
                $opt = fread($conn, 1); // consume option byte
                if ($opt !== false && $cmdByte === self::WILL && ord($opt) === self::OPT_TTYPE) {
                    $this->requestTerminalType($conn);
                }
                return $this->readRawChar($conn, $state); // skip negotiation; return next real char
            }
            if ($cmdByte === self::SB) {
                $optByte = fread($conn, 1);
                $sbOpt = $optByte === false ? null : ord($optByte);
                $sbData = '';
                // Consume subnegotiation until IAC SE
                while (true) {
                    $b = fread($conn, 1);
                    if ($b === false) { break; }
                    if (ord($b) === self::IAC) {
                        $next = fread($conn, 1);
                        if ($next !== false && ord($next) === self::SE) { break; }
                        if ($next !== false && ord($next) === self::IAC) {
                            $sbData .= chr(self::IAC);
                            continue;
                        }
                        continue;
                    }
                    $sbData .= $b;
                }
                if ($sbOpt === self::OPT_NAWS && strlen($sbData) >= 4) {
                    $w = (ord($sbData[0]) << 8) + ord($sbData[1]);
                    $h = (ord($sbData[2]) << 8) + ord($sbData[3]);
                    if ($w > 0) { $state['cols'] = $w; }
                    if ($h > 0) { $state['rows'] = $h; }
                    if ($this->debug) { $this->log("NAWS (rawchar): {$w}x{$h}"); }
                }
                if ($sbOpt === self::OPT_TTYPE && strlen($sbData) >= 2 && ord($sbData[0]) === 0) {
                    $ttype = trim(substr($sbData, 1));
                    $this->recordTerminalType($state, $ttype);
                }
                // If no more data is waiting right now, return immediately rather than
                // blocking on the next fread. Key-wait loops that check for state changes
                // (cols/rows) will detect the update on the next iteration without needing
                // the user to press a key.
                $rr = [$conn]; $rw = $rex = null;
                if (@stream_select($rr, $rw, $rex, 0, 0) < 1) {
                    return "\x00";
                }
                return $this->readRawChar($conn, $state); // more data available — read next char
            }
            // Unknown IAC command — skip and read next real char
            return $this->readRawChar($conn, $state);
        }

        // ANSI escape sequences (arrow keys, etc.)
        if ($byte === 27) {
            // Use a non-blocking peek (50 ms) before each follow-up fread so that a
            // standalone ESC keypress (0x1B with no following bytes) does not block the
            // session indefinitely on a blocking socket.
            $r = [$conn]; $w = $ex = null;
            if (@stream_select($r, $w, $ex, 0, 50000) < 1) {
                return chr(27);  // standalone ESC — nothing followed within 50 ms
            }
            $next1 = fread($conn, 1);
            if ($next1 === false || $next1 === '') { return chr(27); }
            if ($next1 === '[') {
                $r = [$conn]; $w = $ex = null;
                if (@stream_select($r, $w, $ex, 0, 50000) < 1) {
                    return chr(27);
                }
                $next2 = fread($conn, 1);
                if ($next2 === false) { return chr(27); }
                if (ord($next2) >= ord('0') && ord($next2) <= ord('9')) {
                    $r = [$conn]; $w = $ex = null;
                    if (@stream_select($r, $w, $ex, 0, 50000) > 0) {
                        $tilde = fread($conn, 1);
                        if ($tilde === '~') { return chr(27) . '[' . $next2 . '~'; }
                        // Not a tilde — push the byte back rather than silently discarding it,
                        // so multi-byte sequences like CPR (ESC[row;colR) are not corrupted.
                        if ($tilde !== false && $tilde !== '') {
                            $state['pushback'] = ($state['pushback'] ?? '') . $tilde;
                        }
                    }
                }
                return chr(27) . '[' . $next2;
            }
            $state['pushback'] = ($state['pushback'] ?? '') . $next1;
            return chr(27);
        }

        return chr($byte);
    }

    // ===== TERMINAL TITLE =====

    /**
     * Set the terminal window title via ANSI OSC escape.
     */
    private function setTerminalTitle($conn, string $title): void
    {
        $this->safeWrite($conn, "\033]0;{$title}\007");
    }

    // ===== API =====

    /**
     * Make an HTTP API request with exponential-backoff retry.
     *
     * @return array ['status'=>int, 'data'=>array|null, 'cookie'=>string|null, 'error'=>string|null]
     */
    public function apiRequest(string $method, string $path, ?array $payload, ?string $session, int $maxRetries = 3): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP curl extension is required.');
        }

        $url     = $this->apiBase . $path;
        $attempt = 0;

        while ($attempt <= $maxRetries) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HEADER         => false,
            ]);

            $headers = ['Accept: application/json'];
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                $headers[] = 'Content-Type: application/json';
            }
            if ($path === '/api/register') {
                $terminalSource = $this->isSsh ? 'ssh' : 'telnet';
                $headers[] = 'X-Binkterm-Registration-Source: ' . $terminalSource;
                if ($this->peerIp !== null && filter_var($this->peerIp, FILTER_VALIDATE_IP) !== false) {
                    $headers[] = 'X-Binkterm-Client-IP: ' . $this->peerIp;
                }

                $registrationSecret = trim((string) Config::env(
                    'TERMINAL_REGISTRATION_SECRET',
                    'Chang3Me'
                ));
                if ($registrationSecret !== '') {
                    $headers[] = 'X-Binkterm-Registration-Token: ' . $registrationSecret;
                }
            }
            if ($session) {
                curl_setopt($ch, CURLOPT_COOKIE, 'binktermphp_session=' . $session);
            }

            $cookie = null;
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$cookie) {
                $prefix = 'Set-Cookie: binktermphp_session=';
                if (stripos($header, $prefix) === 0) {
                    $cookie = strtok(trim(substr($header, strlen($prefix))), ';');
                }
                return strlen($header);
            });
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            if ($this->insecure) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            }

            $response  = curl_exec($ch);
            $status    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);
            curl_close($ch);

            $data = (is_string($response) && $response !== '') ? json_decode($response, true) : null;

            $shouldRetry = ($curlErrno !== 0 || $status >= 500 || $status === 0);
            if (!$shouldRetry || $attempt >= $maxRetries) {
                return ['status' => $status, 'data' => $data, 'cookie' => $cookie, 'error' => $curlError ?: null, 'errno' => $curlErrno ?: null, 'url' => $url, 'attempts' => $attempt + 1];
            }

            usleep((int)(0.5 * pow(2, $attempt) * 1000000));
            $attempt++;
        }

        return ['status' => 0, 'data' => null, 'cookie' => null, 'error' => 'Max retries exceeded', 'errno' => 0, 'url' => $url, 'attempts' => $maxRetries + 1];
    }

    // ===== LOGGING =====

    /**
     * Log a session-level message.
     */
    private function log(string $message): void
    {
        $this->logger?->info($message);
    }

    /**
     * Public wrapper for INFO-level session logging from handler classes.
     */
    public function logInfo(string $message): void
    {
        $this->log($message);
    }

    /**
     * Log a user action from a handler class.
     *
     * Handlers receive a BbsSession reference and call this to emit
     * INFO-level action entries to the session log file.
     *
     * @param string $username The authenticated username
     * @param string $action   A short action description, e.g. "Echomail: read area FIDONET.NODE"
     */
    public function logAction(string $username, string $action): void
    {
        $this->logger?->info("Action [{$username}]: {$action}");
    }

    /**
     * Store and log the detected TELNET terminal type.
     * Logs once per distinct value per session.
     */
    private function recordTerminalType(array &$state, string $ttype): void
    {
        $normalized = strtoupper(trim($ttype));
        if ($normalized === '') {
            return;
        }
        if (($state['terminal_type'] ?? '') === $normalized) {
            return;
        }
        $state['terminal_type'] = $normalized;
        $this->asciiTextMode = $this->shouldUseAsciiFallback($state);
        // Keep terminalCharset in sync: if it was set to 'utf8' (e.g. by saved
        // settings from a previous session) but TTYPE now reveals the terminal
        // can't handle UTF-8, downgrade to ascii. cp437 is only set via the
        // interactive detection wizard, never inferred from TTYPE alone.
        if ($this->terminalCharset === 'utf8' && $this->asciiTextMode) {
            $this->terminalCharset = 'ascii';
        }
        $this->log("TTYPE detected: {$normalized}");
    }

    /**
     * Log terminal capability summary once per session.
     */
    private function logTerminalInfoOnce(array &$state): void
    {
        if (!empty($state['terminal_info_logged'])) {
            return;
        }
        $state['terminal_info_logged'] = true;

        $sixel = $this->sixelSupported ? 'yes' : 'no';
        $ttype = strtoupper((string)($state['terminal_type'] ?? ''));
        if ($ttype !== '') {
            $ascii = $this->shouldUseAsciiFallback($state) ? 'yes' : 'no';
            $this->asciiTextMode = ($ascii === 'yes');
            $this->log("Client terminal profile: TTYPE={$ttype}, ascii_fallback={$ascii}, sixel={$sixel}");
            return;
        }

        if ($this->isSsh) {
            $this->asciiTextMode = false;
            $this->log("Client terminal profile: SSH session, assumed utf8-capable, sixel={$sixel}");
            return;
        }

        $this->asciiTextMode = true;
        $this->log("Client terminal profile: TTYPE not received, ascii_fallback=yes, sixel={$sixel}");
    }

    /**
     * Log a session duration event (logout / disconnect / idle timeout).
     */
    private function logDuration(string $event, string $username, int $loginTime): void
    {
        $duration = time() - $loginTime;
        $this->log("{$event}: {$username} (session duration: " . floor($duration / 60) . "m " . ($duration % 60) . "s)");
    }

    /**
     * Explicitly invalidate the authenticated session.
     */
    private function logoutSession(string $sessionId): void
    {
        if ($sessionId === '') {
            return;
        }

        try {
            $auth = new \BinktermPHP\Auth();
            $auth->logout($sessionId);
        } catch (\Throwable $e) {
            $this->log('Logout failed for terminal session: ' . $e->getMessage());
        }
    }
}

