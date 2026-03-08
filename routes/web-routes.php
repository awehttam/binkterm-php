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

SimpleRouter::get('/', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }

    $template = new Template();
    $ads = new Advertising();
    $ad = $ads->getRandomAd();

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

    $template->renderResponse('dashboard.twig', [
        'system_news_content' => $systemNewsContent,
        'dashboard_ad' => $ad,
        'shell_art_content' => $shellArtContent,
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
        "name": "Compose new Netmail",
        "short_name": "New Netmail",
        "description":"Post a new Netmail Message",
        "url":"/compose/netmail"
    },
    {  
        "name": "Compose new Echomail",
        "short_name": "New Echomail",
        "description":"Post a new Echomail Message",
        "url":"/compose/echomail"
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

    $template = new Template();
    $template->renderResponse('login.twig', [
        'welcome_message'  => $welcomeMessage,
        'pubterm_enabled'  => $pubTermEnabled,
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

    $template = new Template();
    $template->renderResponse('register.twig');
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
    $echoDateOrder = in_array($echoDateOrderRaw, ['written', 'date_written'], true) ? 'written' : 'received';

    $template = new Template();
    $template->renderResponse('echomail.twig', [
        'echoarea' => $echoarea,
        'domain' => $domainParam,
        'echomail_date_field' => $echoDateOrder,
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
    $echoDateOrder = in_array($echoDateOrderRaw, ['written', 'date_written'], true) ? 'written' : 'received';
    $template = new Template();
    $template->renderResponse('echomail.twig', [
        'echoarea' => $echoarea,
        'echomail_date_field' => $echoDateOrder,
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
        SELECT id, username, real_name, location, fidonet_address, created_at, last_login, is_admin, is_active
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

    // Get transaction history for admins
    $transactions = [];
    if ($viewerIsAdmin && $creditsEnabled) {
        try {
            $transactions = \BinktermPHP\UserCredit::getTransactionHistory((int)$targetUser['id'], 10);
        } catch (\Throwable $e) {
            $transactions = [];
        }
    }

    // Get transfer fee percentage
    $transferFeePercent = isset($creditsConfig['transfer_fee_percent']) ? (float)$creditsConfig['transfer_fee_percent'] : 0.05;
    $transferFeePercent = max(0, min(1, $transferFeePercent));

    $templateVars = [
        'profile_username' => $targetUser['username'],
        'profile_real_name' => $targetUser['real_name'] ?? '',
        'profile_location' => $targetUser['location'] ?? '',
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
        'transfer_fee_percent' => $transferFeePercent
    ];

    $template = new Template();
    $template->renderResponse('user_profile.twig', $templateVars);
});

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

    $templateVars = [
        'system_name_display' => $systemName,
        'system_address_display' => $systemAddress,
        'system_sysop' => $sysopName,
        'taglines' => $taglines,
        'default_tagline' => $defaultTagline
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

    $onlineUsers = $auth->getOnlineUsers(15);

    $template = new Template();
    $template->renderResponse('whos_online.twig', [
        'online_users' => $onlineUsers,
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
    $template->renderResponse('files.twig');
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
    } catch (\Exception $e) {
        $systemName = webLocalizedText('ui.web.fallback.system_name', 'BinktermPHP System', $user);
        $systemAddress = webLocalizedText('ui.common.not_configured', 'Not configured', $user);
        $crashmailEnabled = false;
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

    $templateVars = [
        'type' => $type,
        'current_user' => $user,
        'user_name' => $user['real_name'] ?: $user['username'],
        'system_name_display' => $systemName,
        'system_address_display' => $systemAddress,
        'crashmail_enabled' => $crashmailEnabled,
        'netmail_cost' => $netmailCost,
        'crashmail_cost' => $crashmailCost,
        'currency_symbol' => $currencySymbol,
        'credits_enabled' => $creditsEnabled,
        'taglines' => $taglines,
        'default_tagline' => $defaultTagline,
        'max_cross_post_areas' => $maxCrossPost,
        'prefill_crashmail' => $prefillCrashmail,
    ];

      if ($replyId) {
          $handler = new MessageHandler();
          $userId = $user['user_id'] ?? $user['id'] ?? null;
          $originalMessage = $handler->getMessage($replyId, $type, $userId);
  
          if ($originalMessage) {
              $templateVars['reply_markup_type'] = getMessageMarkupType($originalMessage) ?? '';
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

                // Filter out kludge lines from the quoted message
                $cleanMessageText = filterKludgeLines($originalMessage['message_text']);
                $replyToAddress = $templateVars['reply_to_address']; // Use the address we determined above
                $templateVars['reply_text'] = "\n\n--- Original Message ---\n" .
                    "From: {$originalMessage['from_name']} <{$replyToAddress}>\n" .
                    "Date: {$originalMessage['date_written']}\n" .
                    "Subject: {$originalMessage['subject']}\n\n" .
                    "> " . str_replace("\n", "\n> ", $cleanMessageText);
              } else {
                  $templateVars['reply_to_id'] = $replyId;
                  $templateVars['reply_to_name'] = $originalMessage['from_name'];
                $subject = $originalMessage['subject'] ?? '';
                // Remove "Re: " prefix if it exists (case insensitive)
                $cleanSubject = preg_replace('/^Re:\s*/i', '', $subject);
                $templateVars['reply_subject'] = 'Re: ' . $cleanSubject;
                // Set echoarea with domain for proper select matching (format: tag@domain)
                $echoarea = $originalMessage['echoarea'] . '@' . $originalMessage['domain'];
                $templateVars['domain'] = $originalMessage['domain'];
                // Filter out kludge lines from the quoted message
                $cleanMessageText = filterKludgeLines($originalMessage['message_text']);

                // Generate initials from the original poster's name
                $initials = generateInitials($originalMessage['from_name']);



                // Quote the message intelligently - only quote original lines, not existing quotes
                $quotedText = quoteMessageText($cleanMessageText, $initials);


                $templateVars['reply_text'] = "\n\n--- Original Message ---\n" .
                    "From: {$originalMessage['from_name']}\n" .
                    "Date: {$originalMessage['date_written']}\n" .
                    "Subject: {$originalMessage['subject']}\n\n" .
                    $quotedText;
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

// Include local/custom routes if they exist
$localRoutes = __DIR__ . '/web-routes.local.php';
if (file_exists($localRoutes)) {
    require_once $localRoutes;
}
