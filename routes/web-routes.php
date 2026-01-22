<?php

// Web routes
use BinktermPHP\Auth;
use BinktermPHP\MessageHandler;
use BinktermPHP\Template;
use Pecee\SimpleRouter\SimpleRouter;

SimpleRouter::get('/', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }

    $template = new Template();

    // Generate system news content
    $systemNewsContent = $template->renderSystemNews();

    $template->renderResponse('dashboard.twig', [
        'system_news_content' => $systemNewsContent
    ]);
});

// Web routes
SimpleRouter::get('/appmanifestjson', function() {
    $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
    //$systemName = $binkpConfig->getSystemSysop() . "'s System";
    $systemName = $binkpConfig->getSystemName();
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
      "src": "/favicon.svg",
      "sizes": "192x192",
      "type": "image/svg+xml"
    },
    {
      "src": "/favicon.svg",
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

    $template = new Template();
    $template->renderResponse('echomail.twig', ['echoarea' => null]);
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
})->where(['echoarea' => '[A-Za-z0-9._-]+']);

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
    // Use SITE_URL env variable first (important for apps behind HTTPS proxies)
    $siteUrl = \BinktermPHP\Config::env('SITE_URL');

    if ($siteUrl) {
        // Use configured SITE_URL (handles proxies correctly)
        $shareUrl = rtrim($siteUrl, '/') . '/shared/' . $shareKey;
    } else {
        // Fallback to protocol detection method if SITE_URL not configured
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $shareUrl = $protocol . '://' . $host . '/shared/' . $shareKey;
    }

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
            'error_message' => 'Only administrators can access BinkP functionality.'
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

    $templateVars = [
        'user_username' => $user['username'],
        'user_real_name' => $user['real_name'] ?? '',
        'user_email' => $user['email'] ?? '',
        'user_created_at' => $user['created_at'],
        'user_last_login' => $user['last_login'],
        'user_is_admin' => (bool)$user['is_admin'],
        'system_name_display' => $systemName,
        'system_address_display' => $systemAddress,
        'system_sysop' => $sysopName
    ];

    $template = new Template();
    $template->renderResponse('profile.twig', $templateVars);
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
            'error_message' => 'Only administrators can access user management.'
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
    } catch (\Exception $e) {
        $systemName = 'BinktermPHP System';
        $systemAddress = 'Not configured';
    }

    $templateVars = [
        'type' => $type,
        'current_user' => $user,
        'user_name' => $user['real_name'] ?: $user['username'],
        'system_name_display' => $systemName,
        'system_address_display' => $systemAddress
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
    $auth = new Auth();
    $user = $auth->requireAuth();

    $controller = new BinktermPHP\SubscriptionController();
    $data = $controller->renderUserSubscriptionPage();

    // Only render template if we got data back (not redirected)
    if ($data !== null) {
        $template = new Template();
        $template->renderResponse('user_subscriptions.twig', $data);
    }
});

// Include local/custom routes if they exist
$localRoutes = __DIR__ . '/web-routes.local.php';
if (file_exists($localRoutes)) {
    require_once $localRoutes;
}
