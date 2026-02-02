<?php

// Web routes
use BinktermPHP\Auth;
use BinktermPHP\Advertising;
use BinktermPHP\BbsConfig;
use BinktermPHP\Config;
use BinktermPHP\MessageHandler;
use BinktermPHP\RouteHelper;
use BinktermPHP\Template;
use BinktermPHP\UserCredit;
use Pecee\SimpleRouter\SimpleRouter;

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

    $template->renderResponse('dashboard.twig', [
        'system_news_content' => $systemNewsContent,
        'dashboard_ad' => $ad
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

    $template = new Template();
    $template->renderResponse('login.twig', [
        'welcome_message' => $welcomeMessage
    ]);
});

SimpleRouter::get('/register', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if ($user) {
        return SimpleRouter::response()->redirect('/');
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
    } catch (\Exception $e) {
        $systemAddress = 'Unknown';
    }

    $template = new Template();
    $template->renderResponse('netmail.twig', ['system_address' => $systemAddress]);
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

    $template = new Template();
    $template->renderResponse('echomail.twig', [
        'echoarea' => $echoarea,
        'domain' => $domainParam
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
    $template = new Template();
    $template->renderResponse('echomail.twig', ['echoarea' => $echoarea]);
})->where(['echoarea' => '[A-Za-z0-9@._-]+']);

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
    // Build share URL using centralized method
    $shareUrl = \BinktermPHP\Config::getSiteUrl() . '/shared/' . $shareKey;

    $template = new Template();
    $template->renderResponse('shared_message.twig', [
        'shareKey' => $shareKey,
        'message' => $messageData,
        'share_info' => $shareInfo,
        'share_url' => $shareUrl
    ]);
})->where(['shareKey' => '[a-f0-9]{32}']);

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
            'error_title' => 'Access Denied',
            'error' => 'Only administrators can access BinkP functionality.'
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
        $systemName = 'BinktermPHP System';
        $systemAddress = 'Not configured';
        $sysopName = 'Unknown';
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
        return SimpleRouter::response()->httpCode(404)->html('User not found');
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

SimpleRouter::get('/development-history', function() {
    // Get system configuration for display
    try {
        $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
        $systemName = $binkpConfig->getSystemName();
        $systemAddress = $binkpConfig->getSystemAddress();
        $sysopName = $binkpConfig->getSystemSysop();
    } catch (\Exception $e) {
        $systemName = 'BinktermPHP System';
        $systemAddress = 'Not configured';
        $sysopName = 'Unknown';
    }

    $templateVars = [
        'system_name' => $systemName,
        'fidonet_origin' => $systemAddress,
        'sysop_name' => $sysopName,
        'app_version' => \BinktermPHP\Version::getVersion(),
        'app_full_version' => \BinktermPHP\Version::getFullVersion()
    ];

    $template = new Template();
    $template->renderResponse('development_history.twig', $templateVars);
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
        $systemName = 'BinktermPHP System';
        $systemAddress = 'Not configured';
        $sysopName = 'Unknown';
    }

    $templateVars = [
        'system_name_display' => $systemName,
        'system_address_display' => $systemAddress,
        'system_sysop' => $sysopName
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
            'error' => 'Sorry, chat is not enabled.'
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

// BBSLink Gateway redirect - generates token and redirects to external gateway
SimpleRouter::get('/bbslink', function() {
    /*
Uses settings in .ennv:

#  BBSLink Gateway Configuration
# BBSLINK_GATEWAY_URL=https://gateway.example.com/
# BBSLINK_API_KEY=your-secret-api-key
*/

    // This method is disabled because the bbslinkgateway doesn't work correctly.  Keep this function until someone
    // sorts out the gateway (if it's possible) and this can be used as an example for redirecting to a third party
    // service that does a call back verification to verify a gateway token
    throw new Exception("Disabled");

    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }

    $gatewayUrl = \BinktermPHP\Config::env('BBSLINK_GATEWAY_URL');
    if (empty($gatewayUrl)) {
        http_response_code(503);
        echo "BBSLink gateway not configured";
        return;
    }

    // Generate gateway token
    $token = $auth->generateGatewayToken($user['user_id'], 'menu', 300);

    // Build redirect URL with userid and token
    $separator = (strpos($gatewayUrl, '?') !== false) ? '&' : '?';
    $redirectUrl = $gatewayUrl . $separator . 'userid=' . $user['user_id'] . '&token=' . $token;

    return SimpleRouter::response()->redirect($redirectUrl);
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
            'error_title' => 'Access Denied',
            'error' => 'Only administrators can access user management.'
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
        return SimpleRouter::response()->httpCode(403);
    }

    $template = new Template();
    $template->renderResponse('echoareas.twig');
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
        return SimpleRouter::response()->httpCode(403);
    }

    $template = new Template();
    $template->renderResponse('fileareas.twig');
});

SimpleRouter::get('/files', function() {
    $user = RouteHelper::requireAuth();

    if (!\BinktermPHP\BbsConfig::isFeatureEnabled('file_areas')) {
        return SimpleRouter::response()->httpCode(404);
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
        return SimpleRouter::response()->httpCode(404);
    }

    // Handle reply and echoarea parameters
    $replyId = $_GET['reply'] ?? null;
    $echoarea = $_GET['echoarea'] ?? null;
    $domainParam = $_GET['domain'] ?? null;

    // Handle new message parameters (from nodelist)
    $toAddress = $_GET['to'] ?? null;
    $toName = $_GET['to_name'] ?? null;
    $subject = $_GET['subject'] ?? null;
    // Get system configuration for display
    try {
        $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
        $systemName = $binkpConfig->getSystemName();
        $systemAddress = $binkpConfig->getSystemAddress();
        $crashmailEnabled = $binkpConfig->getCrashmailEnabled();
    } catch (\Exception $e) {
        $systemName = 'BinktermPHP System';
        $systemAddress = 'Not configured';
        $crashmailEnabled = false;
    }

    // Get credit costs for display
    $netmailCost = \BinktermPHP\UserCredit::getCreditCost('netmail', 1);
    $crashmailCost = \BinktermPHP\UserCredit::getCreditCost('crashmail', 10);
    $currencySymbol = \BinktermPHP\UserCredit::getCurrencySymbol();
    $creditsEnabled = \BinktermPHP\UserCredit::isEnabled();

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
        'credits_enabled' => $creditsEnabled
    ];

    if ($replyId) {
        $handler = new MessageHandler();
        $userId = $user['user_id'] ?? $user['id'] ?? null;
        $originalMessage = $handler->getMessage($replyId, $type, $userId);

        if ($originalMessage) {
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
        $templateVars['reply_to_name'] = ($type === 'echomail') ? 'All' : '';
    }

    // Add a safe processed version for template display
    $templateVars['to_name_value'] = $templateVars['reply_to_name'] ?: (($type === 'echomail') ? 'All' : '');

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

// Include local/custom routes if they exist
$localRoutes = __DIR__ . '/web-routes.local.php';
if (file_exists($localRoutes)) {
    require_once $localRoutes;
}
