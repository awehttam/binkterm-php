<?php

// Web routes
use BinktermPHP\AppearanceConfig;
use BinktermPHP\Auth;
use BinktermPHP\Advertising;
use BinktermPHP\BbsConfig;
use BinktermPHP\Config;
use BinktermPHP\I18n\LocaleResolver;
use BinktermPHP\I18n\Translator;
use BinktermPHP\MessageHandler;
use BinktermPHP\RouteHelper;
use BinktermPHP\Template;
use BinktermPHP\UserCredit;
use Pecee\SimpleRouter\SimpleRouter;

if (!function_exists('webLocalizedText')) {
    function webLocalizedText(string $key, string $fallback, ?array $user = null, array $params = [], string $namespace = 'common'): string
    {
        static $translator = null;
        static $resolver = null;
        if ($translator === null || $resolver === null) {
            $translator = new Translator();
            $resolver = new LocaleResolver($translator);
        }

        if ($user === null) {
            try {
                $auth = new Auth();
                $resolvedUser = $auth->getCurrentUser();
                if (is_array($resolvedUser)) {
                    $user = $resolvedUser;
                }
            } catch (\Throwable $e) {
                // Fall back to default locale when no user context is available.
            }
        }

        $resolvedLocale = $resolver->resolveLocale((string)($user['locale'] ?? ''), $user);
        $translated = $translator->translate($key, $params, $resolvedLocale, [$namespace]);
        return $translated === $key ? $fallback : $translated;
    }
}

if (!function_exists('getHttpBasicCredentials')) {
    /**
     * Return HTTP Basic credentials from the current request when present.
     *
     * @return array{username:string,password:string}|null
     */
    function getHttpBasicCredentials(): ?array
    {
        $username = $_SERVER['PHP_AUTH_USER'] ?? null;
        $password = $_SERVER['PHP_AUTH_PW'] ?? null;

        if ($username !== null) {
            return [
                'username' => (string)$username,
                'password' => (string)($password ?? ''),
            ];
        }

        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if (!is_string($header) || stripos($header, 'Basic ') !== 0) {
            return null;
        }

        $decoded = base64_decode(substr($header, 6), true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return null;
        }

        [$basicUser, $basicPass] = explode(':', $decoded, 2);
        return [
            'username' => $basicUser,
            'password' => $basicPass,
        ];
    }
}

if (!function_exists('requireBasicAuthUser')) {
    /**
     * Require HTTP Basic auth and return the authenticated user.
     *
     * @return array
     */
    function requireBasicAuthUser(string $realm = 'BinktermPHP QWK'): array
    {
        $credentials = getHttpBasicCredentials();
        if ($credentials === null) {
            header('WWW-Authenticate: Basic realm="' . addslashes($realm) . '"');
            http_response_code(401);
            echo 'HTTP Basic authentication required.';
            exit;
        }

        $auth = new Auth();
        $user = $auth->authenticateCredentials($credentials['username'], $credentials['password']);
        if ($user === false) {
            header('WWW-Authenticate: Basic realm="' . addslashes($realm) . '"');
            http_response_code(401);
            echo 'Invalid username or password.';
            exit;
        }

        return $user;
    }
}

SimpleRouter::get('/', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }

    $template = new Template();
    $ads = new Advertising();
    $dashboardAds = $ads->getDashboardAds(5);
    $ad = $dashboardAds[0] ?? null;
    $bbsConfig = \BinktermPHP\BbsConfig::getConfig();
    $dashboardAdRotateInterval = (int)($bbsConfig['dashboard_ad_rotate_interval_seconds'] ?? 20);
    if ($dashboardAdRotateInterval < 5 || $dashboardAdRotateInterval > 300) {
        $dashboardAdRotateInterval = 20;
    }

    // Generate system news content
    $systemNewsContent = $template->renderSystemNews();

    // Load shell art content for the bbs-menu ANSI variant
    $shellArtContent = null;
    $bbsMenu = \BinktermPHP\AppearanceConfig::getBbsMenuConfig();
    if (($bbsMenu['variant'] ?? '') === 'ansi' && !empty($bbsMenu['ansi_file'])) {
        $artPath = dirname(__DIR__) . '/data/shell_art/' . basename($bbsMenu['ansi_file']);
        if (is_file($artPath)) {
            $raw = file_get_contents($artPath);
            // Strip SAUCE record: \x1A is the traditional EOF/SAUCE delimiter
            $saucePos = strpos($raw, "\x1A");
            if ($saucePos !== false) {
                $raw = substr($raw, 0, $saucePos);
            }
            // Convert CP437 (DOS encoding) to UTF-8 so block drawing characters render correctly
            $shellArtContent = @iconv('CP437', 'UTF-8//TRANSLIT//IGNORE', $raw)
                ?: mb_convert_encoding($raw, 'UTF-8', 'CP437');
        }
    }

    $onlineCount = $auth->getOnlineUserCount(15);
    $activeTodayCount = $auth->getActiveTodayCount();
    $adminTimezone = 'UTC';
    $handler = new \BinktermPHP\MessageHandler();
    $userId = (int)($user['user_id'] ?? $user['id']);
    $userSettings = $handler->getUserSettings($userId);
    if (!empty($user['is_admin'])) {
        $tz = $userSettings['timezone'] ?? '';
        if ($tz !== '') {
            try {
                new \DateTimeZone($tz); // validate
                $adminTimezone = $tz;
            } catch (\Throwable $e) {
                // fall back to UTC
            }
        }
    }
    $todaysCallers = !empty($user['is_admin']) ? $auth->getTodaysCallers($adminTimezone) : null;

    // Build dashboard card registry and layout
    $creditsConfig = $bbsConfig['credits'] ?? [];
    $referralEnabled = !empty($creditsConfig['enabled']) && !empty($creditsConfig['referral_enabled']);
    $cardConditions = ['referral_enabled' => $referralEnabled];
    $availableCards = \BinktermPHP\DashboardCardRegistry::getAvailableCards($user, $cardConditions);
    $savedLayoutRaw = $userSettings['dashboard_layout'] ?? null;
    if ($savedLayoutRaw) {
        $savedLayout = is_string($savedLayoutRaw) ? json_decode($savedLayoutRaw, true) : $savedLayoutRaw;
        $dashboardLayout = \BinktermPHP\DashboardCardRegistry::mergeLayout(
            is_array($savedLayout) ? $savedLayout : [],
            $availableCards
        );
    } else {
        $dashboardLayout = \BinktermPHP\DashboardCardRegistry::getDefaultLayout($availableCards);
    }

    $template->renderResponse('dashboard.twig', [
        'system_news_content' => $systemNewsContent,
        'dashboard_ad' => $ad,
        'dashboard_ads' => $dashboardAds,
        'dashboard_ad_rotate_interval_seconds' => $dashboardAdRotateInterval,
        'shell_art_content' => $shellArtContent,
        'online_user_count' => $onlineCount,
        'active_today_count' => $activeTodayCount,
        'todays_callers' => $todaysCallers,
        'dashboard_layout' => $dashboardLayout,
        'dashboard_available_cards' => $availableCards,
    ]);
});

SimpleRouter::get('/ads/random', function() {
    $user = RouteHelper::requireAuth();

    $ads = new Advertising();
    $ad = $ads->getRandomAd();

    $template = new Template();
    $template->renderResponse('ads/ad_full.twig', [
        'ad' => $ad
    ]);
});

SimpleRouter::get('/ads/{name}', function($name) {
    $user = RouteHelper::requireAuth();

    $ads = new Advertising();
    $ad = $ads->getAdByName($name);

    $template = new Template();
    $template->renderResponse('ads/ad_full.twig', [
        'ad' => $ad
    ]);
})->where(['name' => '[A-Za-z0-9._-]+']);

// Web routes
SimpleRouter::get('/appmanifestjson', function() {
    $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
    //$systemName = $binkpConfig->getSystemSysop() . "'s System";
    $systemName = $binkpConfig->getSystemName();
    $favicon = Config::env("FAVICONSVG")??"/favicon.svg";

    $ret=<<<_EOT_
{
  "name": "{$systemName}",
  "short_name": "{$systemName}",
  "description": "Binkley Fido Terminal",
  "start_url": "/",
  "display": "standalone",
  "background_color": "#ffffff",
  "theme_color": "#0000ff",
  "icons": [
    {
      "src": "{$favicon}",
      "sizes": "192x192",
      "type": "image/svg+xml"
    },
    {
      "src": "{$favicon}",
      "sizes": "512x512",
      "type": "image/svg+xml"
    }
  ],
  "shortcuts": [
    {
        "name": "Compose Netmail",
        "short_name": "New Netmail",
        "description": "Compose a new netmail message",
        "url": "/compose/netmail"
    },
    {
        "name": "Netmail",
        "short_name": "Netmail",
        "description": "Read your netmail",
        "url": "/netmail"
    },
    {
        "name": "Echomail",
        "short_name": "Echomail",
        "description": "Browse echomail areas",
        "url": "/echomail"
    },
    {  
        "name": "Nodelist",
        "short_name": "Nodelist",
        "description":"Browse the nodelist",
        "url":"/nodelist"
    },
    {
        "name": "Doors",
        "short_name": "Doors",
        "description":"Browse doors and games",
        "url":"/games"
    },
    {
        "name": "Files",
        "short_name": "Files",
        "description":"Browse files",
        "url":"/files"
    }
  ]
}

_EOT_;
    header("Content-type: application/json");
    echo $ret;
    exit;

});

SimpleRouter::get('/login', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if ($user) {
        return SimpleRouter::response()->redirect('/');
    }

    // Read welcome message if it exists
    $welcomeMessage = '';
    $welcomeFile = __DIR__ . '/../config/welcome.txt';
    if (file_exists($welcomeFile)) {
        $welcomeMessage = file_get_contents($welcomeFile);
    }

    // Check if PubTerm door is enabled and allows anonymous access
    $pubTermEnabled = false;
    try {
        $nativeDoorManager = new \BinktermPHP\NativeDoorManager();
        $pubTermEnabled = $nativeDoorManager->isDoorAvailable('pubterm')
            && \BinktermPHP\NativeDoorConfig::isAnonymousAllowed('pubterm');
    } catch (\Throwable $e) {}

    // Splash takes precedence over the legacy welcome.txt
    $loginSplashHtml = null;
    if (\BinktermPHP\License::isValid()) {
        $loginSplashMd = \BinktermPHP\AppearanceConfig::getLoginSplashMarkdown();
        if ($loginSplashMd !== null && trim($loginSplashMd) !== '') {
            $loginSplashHtml = \BinktermPHP\MarkdownRenderer::toHtml($loginSplashMd);
            $welcomeMessage = ''; // suppress legacy fallback
        }
    }

    $loginScreen = \BinktermPHP\AppearanceConfig::getLoginScreenConfig();
    $loginAnsiArt = \BinktermPHP\AppearanceConfig::getLoginScreenAnsi();

    $template = new Template();
    $template->renderResponse('login.twig', [
        'welcome_message'  => $welcomeMessage,
        'pubterm_enabled'  => $pubTermEnabled,
        'login_splash'     => $loginSplashHtml,
        'login_screen'     => $loginScreen,
        'login_ansi_art'   => $loginAnsiArt,
    ]);
});

SimpleRouter::get('/register', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if ($user) {
        return SimpleRouter::response()->redirect('/');
    }

    // Generate anti-spam timestamp
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['registration_time'] = time();

    // Capture referral code from URL parameter
    if (isset($_GET['ref']) && !empty($_GET['ref'])) {
        $sanitized = preg_replace('/[^A-Za-z0-9_]/', '', $_GET['ref']);
        if (!empty($sanitized)) {
            $_SESSION['referral_code'] = $sanitized;
        }
    }

    $registerSplashHtml = null;
    if (\BinktermPHP\License::isValid()) {
        $registerSplashMd = \BinktermPHP\AppearanceConfig::getRegisterSplashMarkdown();
        if ($registerSplashMd !== null && trim($registerSplashMd) !== '') {
            $registerSplashHtml = \BinktermPHP\MarkdownRenderer::toHtml($registerSplashMd);
        }
    }

    $template = new Template();
    $template->renderResponse('register.twig', [
        'register_splash' => $registerSplashHtml,
    ]);
});

SimpleRouter::get('/forgot-password', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if ($user) {
        return SimpleRouter::response()->redirect('/');
    }

    $template = new Template();
    $template->renderResponse('forgot_password.twig');
});

SimpleRouter::get('/reset-password', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if ($user) {
        return SimpleRouter::response()->redirect('/');
    }

    $token = $_GET['token'] ?? null;

    $template = new Template();
    $template->renderResponse('reset_password.twig', ['token' => $token]);
});

SimpleRouter::get('/netmail', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }

    // Get system address for message filtering
    try {
        $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
        $systemAddress = $binkpConfig->getSystemAddress();
        $crashmailEnabled = $binkpConfig->getCrashmailEnabled();
    } catch (\Exception $e) {
        $systemAddress = webLocalizedText('ui.common.unknown', 'Unknown', $user);
        $crashmailEnabled = false;
    }

    $template = new Template();
    $template->renderResponse('netmail.twig', [
        'system_address'   => $systemAddress,
        'crashmail_enabled' => $crashmailEnabled,
    ]);
});

SimpleRouter::get('/echomail', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }

    // Check for echoarea and domain query parameters
    $echoareaParam = $_GET['echoarea'] ?? null;
    $domainParam = $_GET['domain'] ?? null;

    $echoarea = null;
    if ($echoareaParam) {
        // Combine echoarea with domain if both provided
        if ($domainParam) {
            $echoarea = $echoareaParam . '@' . $domainParam;
        } else {
            $echoarea = $echoareaParam;
        }
    }

    $echoDateOrderRaw = strtolower(trim((string)Config::env('ECHOMAIL_ORDER_DATE', 'received')));
    $isAdmin = !empty($user['is_admin']);
    $echoDateOrder = ($isAdmin && in_array($echoDateOrderRaw, ['written', 'date_written'], true)) ? 'written' : 'received';

    $hasInterests = false;
    if (\BinktermPHP\Config::env('ENABLE_INTERESTS', 'true') === 'true') {
        $im = new \BinktermPHP\InterestManager();
        $activeInterests = $im->getInterests(true);
        $hasInterests = count($activeInterests) > 0;

        // First-visit onboarding: redirect to the guide the first time a user
        // visits echomail, regardless of their subscription state.
        if ($hasInterests && !$echoarea) {
            $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
            $meta = new \BinktermPHP\UserMeta();
            if (!$meta->getValue($userId, 'interests_onboarded')) {
                $meta->setValue($userId, 'interests_onboarded', '1');
                return SimpleRouter::response()->redirect('/echo-onboarding?from=echomail');
            }
        }
    }

    $template = new Template();
    $template->renderResponse('echomail.twig', [
        'echoarea' => $echoarea,
        'domain' => $domainParam,
        'echomail_date_field' => $echoDateOrder,
        'has_interests' => $hasInterests,
    ]);
});

SimpleRouter::get('/echomail/{echoarea}', function($echoarea) {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }

    // URL decode the echoarea parameter to handle dots and special characters
    $echoarea = urldecode($echoarea);
    $echoDateOrderRaw = strtolower(trim((string)Config::env('ECHOMAIL_ORDER_DATE', 'received')));
    $isAdmin = !empty($user['is_admin']);
    $echoDateOrder = ($isAdmin && in_array($echoDateOrderRaw, ['written', 'date_written'], true)) ? 'written' : 'received';

    $hasInterests = false;
    if (\BinktermPHP\Config::env('ENABLE_INTERESTS', 'true') === 'true') {
        $im = new \BinktermPHP\InterestManager();
        $hasInterests = count($im->getInterests(true)) > 0;
    }

    $template = new Template();
    $template->renderResponse('echomail.twig', [
        'echoarea' => $echoarea,
        'echomail_date_field' => $echoDateOrder,
        'has_interests' => $hasInterests,
    ]);
})->where(['echoarea' => '[A-Za-z0-9@._-]+']);

SimpleRouter::get('/shared/{area}/{slug}', function($area, $slug) {
    $auth   = new Auth();
    $user   = $auth->getCurrentUser();
    $userId = $user ? ($user['user_id'] ?? $user['id'] ?? null) : null;

    $messageData = null;
    $shareInfo   = null;
    $shareKey    = null;

    try {
        $handler = new MessageHandler();
        $result  = $handler->getSharedMessageBySlug($area, $slug, $userId);

        if ($result['success']) {
            $messageData = $result['message'];
            $shareInfo   = $result['share_info'];
            $shareKey    = $result['share_key'] ?? null;
        }
    } catch (Exception $e) {
        // JavaScript will handle showing the error to the user
    }

    $shareUrl = \BinktermPHP\Config::getSiteUrl() . '/shared/' . rawurlencode($area) . '/' . rawurlencode($slug);

    $template = new Template();
    $template->renderResponse('shared_message.twig', [
        'shareKey'   => $shareKey,
        'shareArea'  => $area,
        'shareSlug'  => $slug,
        'message'    => $messageData,
        'share_info' => $shareInfo,
        'share_url'  => $shareUrl
    ]);
})->where(['area' => '[A-Za-z0-9@._-]+', 'slug' => '[A-Za-z0-9_-]+']);

SimpleRouter::get('/shared/{shareKey}', function($shareKey) {
    // Don't require authentication for shared messages - the API will handle access control
    // But we need to fetch the message data for SEO meta tags
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    $userId = $user ? ($user['user_id'] ?? $user['id'] ?? null) : null;

    $messageData = null;
    $shareInfo = null;

    try {
        $handler = new MessageHandler();
        $result = $handler->getSharedMessage($shareKey, $userId);

        if ($result['success']) {
            $messageData = $result['message'];
            $shareInfo = $result['share_info'];
        }
    } catch (Exception $e) {
        // If there's an error fetching the message, we'll still render the page
        // The JavaScript will handle showing the error to the user
    }

    // Build the full share URL for meta tags
    $shareUrl = \BinktermPHP\Config::getSiteUrl() . '/shared/' . $shareKey;

    $template = new Template();
    $template->renderResponse('shared_message.twig', [
        'shareKey' => $shareKey,
        'message' => $messageData,
        'share_info' => $shareInfo,
        'share_url' => $shareUrl
    ]);
})->where(['shareKey' => '[a-f0-9]{32}']);

SimpleRouter::get('/shared/file/{area}/{filename}', function($area, $filename) {
    // No auth required — file info is public; download requires login (handled in template)
    $auth = new Auth();
    $user = $auth->getCurrentUser();
    $userId = $user ? ($user['user_id'] ?? $user['id'] ?? null) : null;

    $fileData  = null;
    $shareInfo = null;

    try {
        $manager = new \BinktermPHP\FileAreaManager();
        $result  = $manager->getSharedFile($area, $filename, $userId);

        if ($result['success']) {
            $fileData  = $result['file'];
            $shareInfo = $result['share_info'];
        }
    } catch (\Exception $e) {
        // Render error state below
    }

    $shareUrl = \BinktermPHP\Config::getSiteUrl()
        . '/shared/file/'
        . rawurlencode($area)
        . '/'
        . rawurlencode($filename);

    $template = new Template();
    $template->renderResponse('shared_file.twig', [
        'file'        => $fileData,
        'share_info'  => $shareInfo,
        'share_url'   => $shareUrl,
        'is_logged_in'=> $userId !== null,
    ]);
})->where(['area' => '[A-Za-z0-9@._-]+', 'filename' => '[A-Za-z0-9._-]+']);

SimpleRouter::get('/binkp', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }

    // Check if user is admin
    if (!$user['is_admin']) {
        http_response_code(403);
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error_title_code' => 'ui.error.access_error',
            'error_code' => 'ui.web.errors.binkp_admin_only'
        ]);
        return;
    }

    $template = new Template();
    $template->renderResponse('binkp.twig');
});

SimpleRouter::get('/profile', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }

    // Get system configuration for display
    try {
        $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
        $systemName = $binkpConfig->getSystemName();
        $systemAddress = $binkpConfig->getSystemAddress();
        $sysopName = $binkpConfig->getSystemSysop();
    } catch (\Exception $e) {
        $systemName = webLocalizedText('ui.web.fallback.system_name', 'BinktermPHP System', $user);
        $systemAddress = webLocalizedText('ui.common.not_configured', 'Not configured', $user);
        $sysopName = webLocalizedText('ui.common.unknown', 'Unknown', $user);
    }

    $creditsConfig = \BinktermPHP\BbsConfig::getConfig()['credits'] ?? [];
    $creditsEnabled = $creditsConfig['enabled'] ?? true;
    $creditsSymbol = $creditsConfig['symbol'] ?? '$';
    $creditBalance = 0;
    if ($creditsEnabled) {
        try {
            $creditBalance = \BinktermPHP\UserCredit::getBalance((int)($user['user_id'] ?? $user['id']));
        } catch (\Throwable $e) {
            $creditBalance = 0;
        }
    }

    $templateVars = [
        'user_username' => $user['username'],
        'user_real_name' => $user['real_name'] ?? '',
        'user_email' => $user['email'] ?? '',
        'user_location' => $user['location'] ?? '',
        'user_about_me' => $user['about_me'] ?? '',
        'user_created_at' => $user['created_at'],
        'user_last_login' => $user['last_login'],
        'user_is_admin' => (bool)$user['is_admin'],
        'system_name_display' => $systemName,
        'system_address_display' => $systemAddress,
        'system_sysop' => $sysopName,
        'credits_enabled' => !empty($creditsEnabled),
        'credits_symbol' => $creditsSymbol,
        'credit_balance' => $creditBalance,
        'credits_config' => $creditsConfig
    ];

    $template = new Template();
    $template->renderResponse('profile.twig', $templateVars);
});

SimpleRouter::get('/profile/{username}', function($username) {
    $auth = new Auth();
    $currentUser = $auth->getCurrentUser();

    if (!$currentUser) {
        return SimpleRouter::response()->redirect('/login');
    }

    // Get the target user's information
    $db = \BinktermPHP\Database::getInstance()->getPdo();
    $stmt = $db->prepare('
        SELECT id, username, real_name, location, about_me, fidonet_address, created_at, last_login, is_admin, is_active
        FROM users
        WHERE username = ? AND is_active = TRUE
    ');
    $stmt->execute([$username]);
    $targetUser = $stmt->fetch();

    if (!$targetUser) {
        // User not found or inactive
        http_response_code(404);
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error_title_code' => 'ui.error.not_found',
            'error_code' => 'ui.web.errors.profile_user_not_found'
        ]);
        return;
    }

    // Get credits configuration
    $creditsConfig = \BinktermPHP\BbsConfig::getConfig()['credits'] ?? [];
    $creditsEnabled = $creditsConfig['enabled'] ?? true;
    $creditsSymbol = $creditsConfig['symbol'] ?? '$';

    // Get credit balance (public information)
    $creditBalance = 0;
    if ($creditsEnabled) {
        try {
            $creditBalance = \BinktermPHP\UserCredit::getBalance((int)$targetUser['id']);
        } catch (\Throwable $e) {
            $creditBalance = 0;
        }
    }

    // Check if viewing own profile
    $isOwnProfile = ($currentUser['username'] === $username);
    $canViewSensitive = $isOwnProfile || !empty($currentUser['is_admin']);
    $viewerIsAdmin = !empty($currentUser['is_admin']);

    // Get transaction history and activity log for admins
    $transactions = [];
    if ($viewerIsAdmin && $creditsEnabled) {
        try {
            $transactions = \BinktermPHP\UserCredit::getTransactionHistory((int)$targetUser['id'], 10);
        } catch (\Throwable $e) {
            $transactions = [];
        }
    }

    $activityLog = [];
    if ($viewerIsAdmin) {
        try {
            $actStmt = $db->prepare('
                SELECT
                    ual.id,
                    ual.created_at,
                    ac.name  AS category,
                    at.label AS activity,
                    ual.object_name,
                    ual.meta
                FROM user_activity_log ual
                JOIN activity_types      at ON at.id = ual.activity_type_id
                JOIN activity_categories ac ON ac.id = at.category_id
                WHERE ual.user_id = ?
                ORDER BY ual.created_at DESC
                LIMIT 25
            ');
            $actStmt->execute([(int)$targetUser['id']]);
            $activityLog = $actStmt->fetchAll();
        } catch (\Throwable $e) {
            $activityLog = [];
        }
    }

    // Get transfer fee percentage
    $transferFeePercent = isset($creditsConfig['transfer_fee_percent']) ? (float)$creditsConfig['transfer_fee_percent'] : 0.05;
    $transferFeePercent = max(0, min(1, $transferFeePercent));

    $templateVars = [
        'profile_username' => $targetUser['username'],
        'profile_real_name' => $targetUser['real_name'] ?? '',
        'profile_location' => $targetUser['location'] ?? '',
        'profile_about_me_html' => $targetUser['about_me']
            ? \BinktermPHP\MarkdownRenderer::toHtml($targetUser['about_me'])
            : '',
        'profile_fidonet_address' => $targetUser['fidonet_address'] ?? '',
        'profile_created_at' => $targetUser['created_at'],
        'profile_last_login' => $targetUser['last_login'],
        'profile_is_admin' => (bool)$targetUser['is_admin'],
        'profile_user_id' => $targetUser['id'],
        'credits_enabled' => !empty($creditsEnabled),
        'credits_symbol' => $creditsSymbol,
        'credit_balance' => $creditBalance,
        'is_own_profile' => $isOwnProfile,
        'can_view_sensitive' => $canViewSensitive,
        'viewer_is_admin' => $viewerIsAdmin,
        'transactions' => $transactions,
        'activity_log' => $activityLog,
        'transfer_fee_percent' => $transferFeePercent
    ];

    $template = new Template();
    $template->renderResponse('user_profile.twig', $templateVars);
})->where(['username' => '[\w ]+']); // [\w ]+ allows spaces: default [\w-]+ rejects decoded spaces in path

SimpleRouter::get('/settings', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }

    // Get system configuration for display
    try {
        $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
        $systemName = $binkpConfig->getSystemName();
        $systemAddress = $binkpConfig->getSystemAddress();
        $sysopName = $binkpConfig->getSystemSysop();
    } catch (\Exception $e) {
        $systemName = webLocalizedText('ui.web.fallback.system_name', 'BinktermPHP System', $user);
        $systemAddress = webLocalizedText('ui.common.not_configured', 'Not configured', $user);
        $sysopName = webLocalizedText('ui.common.unknown', 'Unknown', $user);
    }

    $taglines = [];
    $defaultTagline = '';
    try {
        $taglinesPath = __DIR__ . '/../config/taglines.txt';
        $raw = file_exists($taglinesPath) ? file_get_contents($taglinesPath) : '';
        $lines = preg_split('/\r\n|\r|\n/', (string)$raw) ?: [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $taglines[] = $trimmed;
            }
        }
    } catch (\Exception $e) {
        $taglines = [];
    }

    try {
        $handler = new MessageHandler();
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        if ($userId) {
            $settings = $handler->getUserSettings($userId);
            $defaultTagline = (string)($settings['default_tagline'] ?? '');
        }
    } catch (\Exception $e) {
        $defaultTagline = '';
    }

    $notificationSounds = [];
    try {
        $notificationSounds = ['disabled'];
        $soundDir = __DIR__ . '/../public_html/sounds';
        $soundFiles = glob($soundDir . '/notify*.mp3') ?: [];
        natsort($soundFiles);
        foreach ($soundFiles as $soundFile) {
            $notificationSounds[] = pathinfo($soundFile, PATHINFO_FILENAME);
        }
    } catch (\Exception $e) {
        $notificationSounds = [];
    }

    if (empty($notificationSounds)) {
        $notificationSounds = ['disabled', 'notify1', 'notify2', 'notify3', 'notify4', 'notify5'];
    }

    $templateVars = [
        'system_name_display' => $systemName,
        'system_address_display' => $systemAddress,
        'system_sysop' => $sysopName,
        'taglines' => $taglines,
        'default_tagline' => $defaultTagline,
        'notification_sounds' => $notificationSounds,
        'license_valid' => \BinktermPHP\License::isValid(),
        'mcp_server_url' => \BinktermPHP\Config::env('MCP_SERVER_URL', ''),
        'mcp_service_running' => (bool)((\BinktermPHP\SystemStatus::getDaemonStatus()['mcp_server']['running'] ?? false)),
    ];

    $template = new Template();
    $template->renderResponse('settings.twig', $templateVars);
});

SimpleRouter::get('/chat', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }

    if (!BbsConfig::isFeatureEnabled('chat')) {
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error_code' => 'ui.web.errors.chat_disabled'
        ]);
        exit;
    }

    $template = new Template();
    $template->renderResponse('chat.twig');
});

SimpleRouter::get('/whosonline', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }

    $onlineUsers = $auth->getOnlineSessions(15);
    $onlineUserCount = $auth->getOnlineUserCount(15);

    $template = new Template();
    $template->renderResponse('whos_online.twig', [
        'online_users' => $onlineUsers,
        'online_user_count' => $onlineUserCount,
        'online_minutes' => 15
    ]);
});

SimpleRouter::get('/admin/users', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }

    // Check if user is admin
    if (!$user['is_admin']) {
        http_response_code(403);
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error_title_code' => 'ui.error.access_error',
            'error_code' => 'ui.web.errors.user_management_admin_only'
        ]);
        return;
    }

    $template = new Template();
    $template->renderResponse('admin_users.twig');
});

SimpleRouter::get('/echoareas', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }

    // Check if user is admin (echoareas management is admin only)
    if (!$user['is_admin']) {
        http_response_code(403);
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error_title_code' => 'ui.error.access_error',
            'error_code' => 'ui.web.errors.echoareas_admin_only'
        ]);
        return;
    }

    $template = new Template();
    $template->renderResponse('echoareas.twig');
});

SimpleRouter::get('/echoareas/import', function() {
    $user = RouteHelper::requireAdmin();

    $template = new Template();
    $template->renderResponse('echoareas_import.twig');
});

SimpleRouter::post('/echoareas/import', function() {
    $user = RouteHelper::requireAdmin();

    $summary = null;
    $error = null;
    $errorCode = null;

    try {
        if (!isset($_FILES['echoareas_csv']) || !is_array($_FILES['echoareas_csv'])) {
            $errorCode = 'ui.echoareas_import.error_choose_csv';
            throw new \RuntimeException('');
        }

        $upload = $_FILES['echoareas_csv'];
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errorCode = 'ui.echoareas_import.error_upload_failed';
            throw new \RuntimeException('');
        }

        $tmpName = $upload['tmp_name'] ?? '';
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            $errorCode = 'ui.echoareas_import.error_invalid_upload';
            throw new \RuntimeException('');
        }

        $importer = new \BinktermPHP\EchoareaImporter();
        $summary = $importer->importCsv($tmpName);
    } catch (\Throwable $e) {
        $error = $e->getMessage();
        if ($error === '') {
            $error = null;
        }
    }

    if (is_array($summary) && isset($summary['errors']) && is_array($summary['errors'])) {
        $localizedErrors = [];
        foreach ($summary['errors'] as $item) {
            if (is_array($item) && !empty($item['error_code'])) {
                $message = webLocalizedText(
                    (string)$item['error_code'],
                    (string)($item['error_fallback'] ?? ''),
                    $user,
                    is_array($item['error_params'] ?? null) ? $item['error_params'] : []
                );
                $lineNumber = isset($item['line']) ? (int)$item['line'] : 0;
                if ($lineNumber > 0) {
                    $message = webLocalizedText(
                        'ui.echoareas_import.error_line_prefix',
                        'Line {line}: {message}',
                        $user,
                        ['line' => $lineNumber, 'message' => $message]
                    );
                }
                $localizedErrors[] = $message;
            } else {
                $localizedErrors[] = (string)$item;
            }
        }
        $summary['errors'] = $localizedErrors;
    }

    $template = new Template();
    $template->renderResponse('echoareas_import.twig', [
        'import_summary' => $summary,
        'import_error' => $error,
        'import_error_code' => $errorCode,
    ]);
});

SimpleRouter::get('/echolist', function() {
    $user = RouteHelper::requireAuth();

    // First-visit onboarding: same guard as /echomail
    if (\BinktermPHP\Config::env('ENABLE_INTERESTS', 'true') === 'true') {
        $im = new \BinktermPHP\InterestManager();
        if (count($im->getInterests(true)) > 0) {
            $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
            $meta = new \BinktermPHP\UserMeta();
            if (!$meta->getValue($userId, 'interests_onboarded')) {
                $meta->setValue($userId, 'interests_onboarded', '1');
                return SimpleRouter::response()->redirect('/echo-onboarding?from=echolist');
            }
        }
    }

    $template = new Template();
    $template->renderResponse('echolist.twig');
});

SimpleRouter::get('/fileareas', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }

    // Check if user is admin (file areas management is admin only)
    if (!$user['is_admin']) {
        http_response_code(403);
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error_title_code' => 'ui.error.access_error',
            'error_code' => 'ui.web.errors.fileareas_admin_only'
        ]);
        return;
    }

    $template = new Template();
    $template->renderResponse('fileareas.twig');
});

SimpleRouter::get('/files', function() {
    $user = RouteHelper::requireAuth();

    if (!\BinktermPHP\BbsConfig::isFeatureEnabled('file_areas')) {
        http_response_code(404);
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error_title_code' => 'ui.error.not_found',
            'error_code' => 'ui.web.errors.files_feature_disabled'
        ]);
        return;
    }

    $template = new Template();
    $template->renderResponse('files.twig', [
        'virus_scan_disabled'   => \BinktermPHP\Config::env('VIRUS_SCAN_DISABLED', 'false') === 'true',
        'file_areas_appearance' => \BinktermPHP\AppearanceConfig::getFileAreasConfig(),
    ]);
});

SimpleRouter::get('/files/{tag}', function($tag) {
    if (!\BinktermPHP\BbsConfig::isFeatureEnabled('file_areas')) {
        http_response_code(404);
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error_title_code' => 'ui.error.not_found',
            'error_code' => 'ui.web.errors.files_feature_disabled'
        ]);
        return;
    }

    // Check if this area is public — if so, auth is optional
    $manager  = new \BinktermPHP\FileAreaManager();
    $area     = $manager->getFileAreaByTag(strtoupper($tag));
    $isPublic = !empty($area['is_public']) && empty($area['is_private']);

    if ($isPublic) {
        $auth = new \BinktermPHP\Auth();
        $user = $auth->getCurrentUser(); // may be null for guests
    } else {
        $user = RouteHelper::requireAuth();
    }

    $template = new Template();
    $template->renderResponse('files.twig', [
        'virus_scan_disabled'   => \BinktermPHP\Config::env('VIRUS_SCAN_DISABLED', 'false') === 'true',
        'initial_area_tag'      => strtoupper($tag),
        'is_public_area'        => $isPublic,
        'initial_area'          => $area ?: null,
        'file_areas_appearance' => \BinktermPHP\AppearanceConfig::getFileAreasConfig(),
    ]);
});

SimpleRouter::get('/public-files', function() {
    if (!\BinktermPHP\BbsConfig::isFeatureEnabled('file_areas') ||
        !\BinktermPHP\BbsConfig::isFeatureEnabled('public_files_index')) {
        http_response_code(404);
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error_title_code' => 'ui.error.not_found',
            'error_code' => 'ui.web.errors.not_found'
        ]);
        return;
    }

    $template = new Template();
    $template->renderResponse('files.twig', [
        'virus_scan_disabled'   => \BinktermPHP\Config::env('VIRUS_SCAN_DISABLED', 'false') === 'true',
        'is_public_index'       => true,
        'file_areas_appearance' => \BinktermPHP\AppearanceConfig::getFileAreasConfig(),
    ]);
});

SimpleRouter::get('/compose/{type}', function($type) {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }

    if (!in_array($type, ['netmail', 'echomail'])) {
        http_response_code(404);
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error_title_code' => 'ui.error.not_found',
            'error_code' => 'ui.web.errors.compose_type_invalid'
        ]);
        return;
    }

    // Handle reply and echoarea parameters
    $replyId = $_GET['reply'] ?? null;
    $echoarea = $_GET['echoarea'] ?? null;
    $domainParam = $_GET['domain'] ?? null;

    // Interest context: restrict echo area list and cross-post list to interest areas
    $interestSlug = $_GET['interest'] ?? null;
    $interestData = null;
    $interestEchoareas = [];
    if ($interestSlug && \BinktermPHP\Config::env('ENABLE_INTERESTS', 'true') === 'true') {
        $im = new \BinktermPHP\InterestManager();
        $interestData = $im->getInterestBySlug($interestSlug);
        if ($interestData) {
            // Fetch the interest's echo areas with tag/domain for the compose selects
            $db = \BinktermPHP\Database::getInstance()->getPdo();
            $stmt = $db->prepare("
                SELECT e.tag, e.domain, e.description, e.color,
                       COUNT(em.id) AS message_count
                FROM echoareas e
                INNER JOIN interest_echoareas ie ON ie.echoarea_id = e.id
                LEFT JOIN echomail em ON em.echoarea_id = e.id
                WHERE ie.interest_id = ? AND e.is_active = TRUE
                GROUP BY e.id, e.tag, e.domain, e.description, e.color
                ORDER BY message_count DESC, e.tag ASC
            ");
            $stmt->execute([(int)$interestData['id']]);
            $interestEchoareas = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($interestEchoareas as &$row) {
                $row['message_count'] = (int)$row['message_count'];
            }
            unset($row);
        } else {
            $interestSlug = null; // slug not found — fall back to normal compose
        }
    }

    // Handle new message parameters (from nodelist or address book)
    $toAddress = $_GET['to'] ?? null;
    $toName = $_GET['to_name'] ?? null;
    $subject = $_GET['subject'] ?? null;
    $prefillCrashmail = !empty($_GET['crashmail']);
    // Get system configuration for display
    try {
        $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
        $systemName = $binkpConfig->getSystemName();
        $systemAddress = $binkpConfig->getSystemAddress();
        $crashmailEnabled = $binkpConfig->getCrashmailEnabled();
        $sysopName = $binkpConfig->getSystemSysop();
    } catch (\Exception $e) {
        $systemName = webLocalizedText('ui.web.fallback.system_name', 'BinktermPHP System', $user);
        $systemAddress = webLocalizedText('ui.common.not_configured', 'Not configured', $user);
        $crashmailEnabled = false;
        $sysopName = '';
    }

    // Get credit costs for display
    $netmailCost = \BinktermPHP\UserCredit::getCreditCost('netmail', 1);
    $crashmailCost = \BinktermPHP\UserCredit::getCreditCost('crashmail', 10);
    $currencySymbol = \BinktermPHP\UserCredit::getCurrencySymbol();
    $creditsEnabled = \BinktermPHP\UserCredit::isEnabled();

    $taglines = [];
    $defaultTagline = '';
    try {
        $taglinesPath = __DIR__ . '/../config/taglines.txt';
        $raw = file_exists($taglinesPath) ? file_get_contents($taglinesPath) : '';
        $lines = preg_split('/\r\n|\r|\n/', (string)$raw) ?: [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $taglines[] = $trimmed;
            }
        }
    } catch (\Exception $e) {
        $taglines = [];
    }

    $bbsConfig = \BinktermPHP\BbsConfig::getConfig();
    $maxCrossPost = (int)($bbsConfig['max_cross_post_areas'] ?? 5);

    // Determine the default charset for new messages (may be overridden by reply_charset for replies).
    // Build a domain→charset map so the compose page can update the selector when the echo area changes.
    $defaultCharset = \BinktermPHP\BbsConfig::getOutgoingCharset();
    $domainCharsets = [];  // domain => charset override (only entries that differ from BBS default)
    try {
        $binkpCfg = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
        foreach ($binkpCfg->getUplinks() as $uplink) {
            $uplinkDomain = strtolower($uplink['domain'] ?? '');
            if ($uplinkDomain !== '' && !empty($uplink['default_charset'])) {
                $domainCharsets[$uplinkDomain] = strtoupper($uplink['default_charset']);
            }
        }
        // Apply override for initial charset if domain is already known from GET param (echomail)
        if ($domainParam && isset($domainCharsets[strtolower($domainParam)])) {
            $defaultCharset = $domainCharsets[strtolower($domainParam)];
        }
        // For netmail with a pre-filled destination address, resolve the uplink at render time
        // so the charset selector is correct on page load without waiting for the JS to call
        // the markdown-support API.
        if ($type === 'netmail' && !empty($toAddress) && !$replyId) {
            if ($binkpCfg->isMyAddress(trim((string)$toAddress))) {
                $defaultCharset = 'UTF-8';
            } else {
                $destUplink = $binkpCfg->getUplinkForDestination((string)$toAddress);
                if ($destUplink && !empty($destUplink['default_charset'])) {
                    $defaultCharset = strtoupper($destUplink['default_charset']);
                }
            }
        }
    } catch (\Exception $e) {
        // Config unavailable; fall back to BBS default
    }

    $templateVars = [
        'type' => $type,
        'current_user' => $user,
        'user_name' => $user['real_name'] ?: $user['username'],
        'system_name_display' => $systemName,
        'system_address_display' => $systemAddress,
        'system_sysop' => $sysopName,
        'crashmail_enabled' => $crashmailEnabled,
        'netmail_cost' => $netmailCost,
        'crashmail_cost' => $crashmailCost,
        'currency_symbol' => $currencySymbol,
        'credits_enabled' => $creditsEnabled,
        'taglines' => $taglines,
        'default_tagline' => $defaultTagline,
        'max_cross_post_areas' => $maxCrossPost,
        'prefill_crashmail' => $prefillCrashmail,
        'interest_slug' => $interestSlug,
        'interest_name' => $interestData ? $interestData['name'] : null,
        'interest_echoareas' => $interestEchoareas,
        'default_charset' => $defaultCharset,
        'domain_charsets' => $domainCharsets,
    ];

      if ($replyId) {
          $handler = new MessageHandler();
          $userId = $user['user_id'] ?? $user['id'] ?? null;
          $originalMessage = $handler->getMessage($replyId, $type, $userId);
  
          if ($originalMessage) {
              $templateVars['reply_markup_type'] = getMessageMarkupType($originalMessage) ?? '';
              $rawCharset = (string)($originalMessage['message_charset'] ?? 'UTF-8');
              $templateVars['reply_charset'] = \BinktermPHP\Binkp\Config\BinkpConfig::normalizeCharset($rawCharset) ?: 'UTF-8';
              if ($type === 'netmail') {
                  $templateVars['reply_to_id'] = $replyId;

                // Try to parse REPLYTO kludge from the original message text
                $replyToData = parseReplyToKludge($originalMessage['message_text']);

                if ($replyToData) {
                    // Use REPLYTO address and name if valid FidoNet address found
                    $templateVars['reply_to_address'] = $replyToData['address'];
                    $templateVars['reply_to_name'] = $replyToData['name'] ?: $originalMessage['from_name'];
                } else {
                    // Fallback to existing logic if no valid REPLYTO found
                    $templateVars['reply_to_address'] = $originalMessage['reply_address'] ?: ($originalMessage['original_author_address'] ?: $originalMessage['from_address']);
                    $templateVars['reply_to_name'] = $originalMessage['from_name'];
                }

                $subject = $originalMessage['subject'] ?? '';
                // Remove "Re: " prefix if it exists (case insensitive)
                $cleanSubject = preg_replace('/^Re:\s*/i', '', $subject);
                $templateVars['reply_subject'] = 'Re: ' . $cleanSubject;

                // Filter out kludge lines but preserve blank lines so quoted structure is intact
                $cleanMessageText = filterKludgeLinesPreserveEmptyLines($originalMessage['message_text']);
                $replyToAddress = $templateVars['reply_to_address']; // Use the address we determined above

                if (($templateVars['reply_markup_type'] ?? '') === 'markdown') {
                    $quotedText = quoteMarkdownMessage($cleanMessageText);
                } else {
                    // Quote using FSC-0032 style for plain/stylecoded messages
                    $initials = generateInitials($originalMessage['from_name']);
                    $quotedText = quoteMessageText($cleanMessageText, $initials);
                }

                $replyDate = date('F j Y', strtotime($originalMessage['date_written']));
                $attribution = webLocalizedText('ui.compose.reply_attribution', 'On {date}, {name} wrote:', $user, [
                    'date' => $replyDate,
                    'name' => $originalMessage['from_name'],
                ]);
                $separator = (($templateVars['reply_markup_type'] ?? '') === 'markdown') ? "\n\n" : "\n";
                $templateVars['reply_text'] = "\n" . $attribution . $separator . $quotedText;
              } else {
                  $templateVars['reply_to_id'] = $replyId;
                  $templateVars['reply_to_name'] = $originalMessage['from_name'];
                $subject = $originalMessage['subject'] ?? '';
                // Remove "Re: " prefix if it exists (case insensitive)
                $cleanSubject = preg_replace('/^Re:\s*/i', '', $subject);
                $templateVars['reply_subject'] = 'Re: ' . $cleanSubject;
                // Set echoarea for proper select matching — only append @domain when
                // domain is non-empty, matching the JS option format: tag@domain or tag
                $echoarea = $originalMessage['echoarea'];
                if (!empty($originalMessage['domain'])) {
                    $echoarea .= '@' . $originalMessage['domain'];
                }
                $templateVars['domain'] = $originalMessage['domain'];
                // Filter out kludge lines but preserve blank lines so quoted structure is intact
                $cleanMessageText = filterKludgeLinesPreserveEmptyLines($originalMessage['message_text']);

                if (($templateVars['reply_markup_type'] ?? '') === 'markdown') {
                    $quotedText = quoteMarkdownMessage($cleanMessageText);
                } else {
                    // Quote the message intelligently - only quote original lines, not existing quotes
                    $initials = generateInitials($originalMessage['from_name']);
                    $quotedText = quoteMessageText($cleanMessageText, $initials);
                }

                $replyDate = date('F j Y', strtotime($originalMessage['date_written']));
                $attribution = webLocalizedText('ui.compose.reply_attribution', 'On {date}, {name} wrote:', $user, [
                    'date' => $replyDate,
                    'name' => $originalMessage['from_name'],
                ]);
                $separator = (($templateVars['reply_markup_type'] ?? '') === 'markdown') ? "\n\n" : "\n";
                $templateVars['reply_text'] = "\n" . $attribution . $separator . $quotedText;
              }
          }
      }

    if ($echoarea) {
        // Combine echoarea with domain if provided separately and not already in tag@domain format
        if ($domainParam && strpos($echoarea, '@') === false) {
            $echoarea = $echoarea . '@' . $domainParam;
        }
        $templateVars['echoarea'] = $echoarea;
    }

    // Handle new message parameters (from nodelist)
    if ($toAddress && $type === 'netmail' && !$replyId) {
        $templateVars['reply_to_address'] = $toAddress;
        if ($toName) {
            $templateVars['reply_to_name'] = $toName;
        }
    }

    // Handle subject parameter independently (for user-click-to-compose functionality)
    if ($subject && $type === 'netmail' && !$replyId) {
        $templateVars['reply_subject'] = $subject;
    }

    // Ensure reply_to_name has a safe default value and add a processed version
    if (!isset($templateVars['reply_to_name']) || $templateVars['reply_to_name'] === '') {
        $templateVars['reply_to_name'] = ($type === 'echomail')
            ? webLocalizedText('ui.common.all', 'All', $user)
            : '';
    }

    // Add a safe processed version for template display
    $templateVars['to_name_value'] = $templateVars['reply_to_name']
        ?: (($type === 'echomail') ? webLocalizedText('ui.common.all', 'All', $user) : '');

    // Apply user signature to compose text (server-side to avoid late AJAX overwrites)
    try {
        $handler = new MessageHandler();
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        if ($userId) {
            $settings = $handler->getUserSettings($userId);
            $signatureText = trim((string)($settings['signature_text'] ?? ''));
            $defaultTagline = (string)($settings['default_tagline'] ?? '');
            // Resolve random tagline selection at compose time
            if ($defaultTagline === '__random__' && !empty($templateVars['taglines'])) {
                $defaultTagline = $templateVars['taglines'][array_rand($templateVars['taglines'])];
            }
            $templateVars['default_tagline'] = $defaultTagline;
            if ($signatureText !== '') {
                $sigLines = preg_split('/\r\n|\r|\n/', $signatureText) ?: [];
                $sigLines = array_slice($sigLines, 0, 4);
                $sigLines = array_map('rtrim', $sigLines);
                $signaturePlain = implode("\n", $sigLines);

                $replyText = (string)($templateVars['reply_text'] ?? '');
                $replyLines = preg_split('/\r\n|\r|\n/', rtrim($replyText, "\r\n")) ?: [];
                while (!empty($replyLines) && trim((string)end($replyLines)) === '') {
                    array_pop($replyLines);
                }
                $tail = array_slice($replyLines, -count($sigLines));
                $alreadyHasSignature = ($sigLines !== [] && $tail === $sigLines);

                if (!$alreadyHasSignature) {
                    $base = rtrim($replyText);
                    $templateVars['reply_text'] = $base === ''
                        ? "\n\n\n" . $signaturePlain
                        : $base . "\n\n\n" . $signaturePlain;
                }
            }
        }
    } catch (\Exception $e) {
        // Ignore signature errors to keep compose functional
    }

    $template = new Template();
    $template->renderResponse('compose.twig', $templateVars);
});




// Subscription management routes
SimpleRouter::get('/subscriptions', function() {
    $user = RouteHelper::requireAuth();

    $controller = new BinktermPHP\SubscriptionController();
    $data = $controller->renderUserSubscriptionPage();

    // Only render template if we got data back (not redirected)
    if ($data !== null) {
        $template = new Template();
        $template->renderResponse('user_subscriptions.twig', $data);
    }
});

SimpleRouter::get('/polls/create', function() {
    $user = RouteHelper::requireAuth();

    // Get poll creation cost
    $pollCost = UserCredit::getCreditCost('poll_creation', 15);

    // Get user's credit balance
    $balance = UserCredit::getBalance($user['user_id'] ?? $user['id']);

    $template = new Template();
    $template->renderResponse('create_poll.twig', [
        'poll_cost' => $pollCost,
        'credit_balance' => $balance
    ]);
});

SimpleRouter::get('/polls', function() {
    $user = RouteHelper::requireAuth();

    if (!BbsConfig::isFeatureEnabled('voting_booth')) {
        http_response_code(404);
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error_title_code' => 'ui.error.not_found',
            'error_code' => 'ui.web.errors.polls_disabled'
        ]);
        return;
    }

    $template = new Template();
    $template->renderResponse('polls.twig');
});

SimpleRouter::get('/shoutbox', function() {
    $user = RouteHelper::requireAuth();

    if (!BbsConfig::isFeatureEnabled('shoutbox')) {
        http_response_code(404);
        $template = new Template();
        $template->renderResponse('error.twig', [
            'error_title_code' => 'ui.error.not_found',
            'error_code' => 'ui.web.errors.shoutbox_disabled'
        ]);
        return;
    }

    $template = new Template();
    $template->renderResponse('shoutbox.twig');
});

// Serve shell art files from data/shell_art/ (public read, admin-only write)
SimpleRouter::get('/shell-art/{name}', function(string $name) {
    // Sanitize: only allow safe filenames, no path traversal
    $name = basename($name);
    if (!preg_match('/^[a-zA-Z0-9_\-]+\.(ans|asc|txt)$/i', $name)) {
        http_response_code(404);
        return;
    }

    $dir = dirname(__DIR__) . '/data/shell_art';
    $path = $dir . '/' . $name;

    if (!file_exists($path) || !is_file($path)) {
        http_response_code(404);
        return;
    }

    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: public, max-age=3600');
    readfile($path);
});

// Public BBS Directory page
SimpleRouter::get('/bbs-directory', function() {
    if (!\BinktermPHP\BbsConfig::isFeatureEnabled('bbs_directory')) {
        http_response_code(404);
        (new Template())->renderResponse('404.twig');
        return;
    }

    $db        = \BinktermPHP\Database::getInstance()->getPdo();
    $directory = new \BinktermPHP\BbsDirectory($db);
    $entries   = $directory->getActiveEntries();

    $template = new Template();
    $template->renderResponse('bbs_directory.twig', ['entries' => $entries]);
});

// Individual BBS detail page
SimpleRouter::get('/bbs-directory/{id}', function($id) {
    if (!\BinktermPHP\BbsConfig::isFeatureEnabled('bbs_directory')) {
        http_response_code(404);
        (new Template())->renderResponse('404.twig');
        return;
    }

    $id = (int)$id;
    if ($id <= 0) {
        http_response_code(404);
        (new Template())->renderResponse('404.twig');
        return;
    }

    $db        = \BinktermPHP\Database::getInstance()->getPdo();
    $directory = new \BinktermPHP\BbsDirectory($db);
    $entry     = $directory->getActiveEntryById($id);

    if (!$entry) {
        http_response_code(404);
        (new Template())->renderResponse('404.twig');
        return;
    }

    $template = new Template();
    $template->renderResponse('bbs_directory_entry.twig', ['entry' => $entry]);
});

// Submit a BBS listing (authenticated users only — creates pending entry)
SimpleRouter::post('/api/bbs-directory/submit', function() {
    if (!\BinktermPHP\BbsConfig::isFeatureEnabled('bbs_directory')) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not found']);
        return;
    }

    $user  = RouteHelper::requireAuth();
    $db    = \BinktermPHP\Database::getInstance()->getPdo();
    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['name'])) {
        http_response_code(400);
        apiError('errors.bbs_directory.name_required', apiLocalizedText('errors.bbs_directory.name_required', 'BBS name is required'));
        return;
    }

    $directory = new \BinktermPHP\BbsDirectory($db);
    $userId    = (int)($user['user_id'] ?? $user['id'] ?? 0);

    try {
        $id = $directory->createPendingEntry($input, $userId);
        echo json_encode(['success' => true, 'id' => $id]);

        // Notify the sysop that a new BBS listing is awaiting approval
        $bbsName    = $input['name'] ?? 'Unknown';
        $approvalUrl = \BinktermPHP\Config::getSiteUrl() . '/admin/bbs-directory';
        \BinktermPHP\SysopNotificationService::sendNoticeToSysop(
            'New BBS listing pending approval',
            "A new BBS listing has been submitted and is awaiting your review.\n\n" .
            "BBS Name: {$bbsName}\n\n" .
            "Approve or reject it at:\n{$approvalUrl}"
        );
    } catch (\PDOException $e) {
        http_response_code(400);
        if (strpos($e->getMessage(), 'duplicate key') !== false || strpos($e->getMessage(), 'unique') !== false) {
            apiError('errors.bbs_directory.duplicate_name', apiLocalizedText('errors.bbs_directory.duplicate_name', 'A BBS with that name already exists'));
        } else {
            apiError('errors.bbs_directory.submit_failed', apiLocalizedText('errors.bbs_directory.submit_failed', 'Submission failed'));
        }
    }
});

// Public /about page (only when enabled in appearance settings)
SimpleRouter::get('/about', function() {
    if (!\BinktermPHP\AppearanceConfig::isAboutPageEnabled()) {
        http_response_code(404);
        $template = new Template();
        $template->renderResponse('404.twig');
        return;
    }

    $template = new Template();
    $template->renderResponse('about.twig');
});

// Echomail onboarding guide
SimpleRouter::get('/echo-onboarding', function() {
    RouteHelper::requireAuth();
    $from = $_GET['from'] ?? 'echomail';
    // Only allow known destinations
    $skipUrl = $from === 'echolist' ? '/echolist' : '/echomail';

    // Count distinct domains across enabled uplinks so the template can
    // provide multi-network context when more than one network is connected.
    $networkCount = 0;
    try {
        $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
        $domains = [];
        foreach ($binkpConfig->getEnabledUplinks() as $uplink) {
            $domain = trim((string)($uplink['domain'] ?? ''));
            if ($domain !== '') {
                $domains[$domain] = true;
            }
        }
        $networkCount = count($domains);
    } catch (\Throwable $e) {
        // Config unavailable — leave count at 0; template falls back gracefully
    }

    $template = new Template();
    $template->renderResponse('echo-onboarding.twig', [
        'skip_url'      => $skipUrl,
        'network_count' => $networkCount,
    ]);
});

// Interests page
SimpleRouter::get('/interests', function() {
    $user = RouteHelper::requireAuth();

    if (\BinktermPHP\Config::env('ENABLE_INTERESTS', 'true') !== 'true') {
        http_response_code(404);
        $template = new Template();
        $template->renderResponse('404.twig');
        return;
    }

    $template = new Template();
    $template->renderResponse('interests.twig');
});

// QWK Offline Mail page
SimpleRouter::get('/qwk', function() {
    $user = RouteHelper::requireAuth();

    if (!\BinktermPHP\BbsConfig::isFeatureEnabled('qwk')) {
        http_response_code(404);
        $template = new Template();
        $template->renderResponse('404.twig');
        return;
    }

    $template = new Template();
    $template->renderResponse('qwk.twig');
});

SimpleRouter::match([\Pecee\Http\Request::REQUEST_TYPE_GET, \Pecee\Http\Request::REQUEST_TYPE_HEAD], '/qwk/download', function() {
    $user   = requireBasicAuthUser();
    $userId = (int)($user['user_id'] ?? $user['id']);

    try {
        $controller = new \BinktermPHP\Qwk\QwkHttpController();
        $metadata = $controller->getDownloadMetadata($userId);

        $filename = (string)$metadata['filename'];
        $safeFilename = str_replace(['\\', '"', "\r", "\n"], ['_', '_', '', ''], $filename);
        $encodedFilename = rawurlencode($filename);

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $safeFilename . '"; filename*=UTF-8\'\'' . $encodedFilename);
        header('X-QWK-BBS-ID: ' . $metadata['bbs_id']);
        header('X-QWK-Reply-Filename: ' . $metadata['reply_filename']);
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            exit;
        }

        $download = $controller->buildDownloadPacket($userId);
        header('Content-Length: ' . $download['filesize']);

        readfile($download['path']);
        @unlink($download['path']);
        exit;
    } catch (\DomainException $e) {
        http_response_code(403);
        echo htmlspecialchars($e->getMessage());
    } catch (\Throwable $e) {
        getServerLogger()->error('[QWK] basic-auth download failed for user ' . $userId . ': ' . $e->getMessage());
        http_response_code(500);
        echo 'Failed to build QWK packet: ' . htmlspecialchars($e->getMessage());
    }
});

SimpleRouter::post('/qwk/upload', function() {
    $user   = requireBasicAuthUser();
    $userId = (int)($user['user_id'] ?? $user['id']);

    header('Content-Type: application/json');

    try {
        $controller = new \BinktermPHP\Qwk\QwkHttpController();
        $file = $controller->getUploadedRepFromRequest();
        echo json_encode($controller->processUploadedRep($file, $userId));
    } catch (\InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
    } catch (\DomainException $e) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
    } catch (\Throwable $e) {
        getServerLogger()->error('[QWK] basic-auth upload failed for user ' . $userId . ': ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to process REP packet: ' . $e->getMessage(),
        ]);
    }
});

/**
 * Serve a markdown post image file to the browser.
 * @param array $file File row from FileAreaManager (must include storage_path, filename)
 */
function serveMarkdownImage(array $file): void
{
    if (!file_exists($file['storage_path'])) {
        http_response_code(404);
        echo 'Image not found';
        return;
    }

    $ext   = strtolower(pathinfo((string)$file['filename'], PATHINFO_EXTENSION));
    $mimes = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ];
    $contentType = $mimes[$ext] ?? (mime_content_type($file['storage_path']) ?: 'application/octet-stream');
    $safeName    = addslashes(basename((string)$file['filename']));

    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . filesize($file['storage_path']));
    header('Content-Disposition: inline; filename="' . $safeName . '"');
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($file['storage_path']);
    exit;
}

// Serve a markdown post image by human-readable slug: /echomail-images/{username}/{slug}
// No authentication required — images are embedded in public posts.
SimpleRouter::get('/echomail-images/{username}/{slug}', function(string $username, string $slug) {
    $manager = new \BinktermPHP\FileAreaManager();
    $file    = $manager->getMarkdownImageBySlug($username, $slug);

    if (!$file) {
        http_response_code(404);
        echo 'Image not found';
        return;
    }

    serveMarkdownImage($file);
})->where(['username' => '[\w ]+']); // [\w ]+ allows spaces: default [\w-]+ rejects decoded spaces in path

// Legacy route: serve by SHA-256 hash for URLs embedded in older posts.
SimpleRouter::get('/echomail-images/{hash}', function(string $hash) {
    $manager = new \BinktermPHP\FileAreaManager();
    $file    = $manager->getMarkdownImageByHash($hash);

    if (!$file) {
        http_response_code(404);
        echo 'Image not found';
        return;
    }

    serveMarkdownImage($file);
});

// User guide
SimpleRouter::get('/user-guide', function() {
    $path = __DIR__ . '/../docs/userguide/index.md';
    $content = null;
    if (file_exists($path)) {
        $content = \BinktermPHP\MarkdownRenderer::toHtml(file_get_contents($path), 0, true);
    }
    $template = new Template();
    $template->renderResponse('userguide.twig', ['content' => $content]);
});

// Include local/custom routes if they exist
$localRoutes = __DIR__ . '/web-routes.local.php';
if (file_exists($localRoutes)) {
    require_once $localRoutes;
}
