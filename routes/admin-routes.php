<?php

use BinktermPHP\AdminActionLogger;
use BinktermPHP\AdminController;
use BinktermPHP\Auth;
use BinktermPHP\DoorConfig;
use BinktermPHP\DoorManager;
use BinktermPHP\RouteHelper;
use BinktermPHP\Template;
use BinktermPHP\UserMeta;
use BinktermPHP\WebDoorManifest;
use Pecee\SimpleRouter\SimpleRouter;

if (!function_exists('extractUploadedLicenseData')) {
    /**
     * @param array<string,mixed> $file
     * @return array<string,mixed>
     */
    function extractUploadedLicenseData(array $file): array
    {
        $tmpName = (string)($file['tmp_name'] ?? '');
        $originalName = (string)($file['name'] ?? '');
        $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));

        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new \RuntimeException('No uploaded file was received.');
        }

        if ($ext === 'json') {
            $content = @file_get_contents($tmpName);
            if ($content === false) {
                throw new \RuntimeException('Failed to read uploaded license file.');
            }
        } elseif ($ext === 'zip') {
            if (!class_exists('ZipArchive')) {
                throw new \RuntimeException('ZIP uploads require the PHP zip extension.');
            }

            $zip = new \ZipArchive();
            if ($zip->open($tmpName) !== true) {
                throw new \RuntimeException('Failed to open uploaded ZIP file.');
            }

            $content = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = (string)$zip->getNameIndex($i);
                $normalized = str_replace('\\', '/', $entryName);
                if (strcasecmp(basename($normalized), 'license.json') !== 0) {
                    continue;
                }

                $content = $zip->getFromIndex($i);
                if ($content !== false) {
                    break;
                }
            }
            $zip->close();

            if ($content === false) {
                throw new \RuntimeException('ZIP file does not contain a readable license.json.');
            }
        } else {
            throw new \RuntimeException('Please upload a license.json or a ZIP containing license.json.');
        }

        $licenseData = json_decode((string)$content, true);
        if (!is_array($licenseData) || !isset($licenseData['payload'], $licenseData['signature'])) {
            throw new \RuntimeException('Invalid license format. Expected {payload: {...}, signature: "..."}.' );
        }

        return $licenseData;
    }
}

if (!function_exists('apiError')) {
    function apiError(string $errorCode, string $message, ?int $status = null, array $extra = []): void
    {
        if ($status !== null) {
            http_response_code($status);
        }
        echo json_encode(array_merge([
            'success' => false,
            'error_code' => $errorCode,
            'error' => $message,
        ], $extra));
        exit;
    }
}

if (!function_exists('apiLocalizedText')) {
    function apiLocalizedText(string $key, string $fallback, ?array $user = null, array $params = [], string $namespace = 'errors'): string
    {
        static $translator = null;
        static $resolver = null;
        if ($translator === null || $resolver === null) {
            $translator = new \BinktermPHP\I18n\Translator();
            $resolver = new \BinktermPHP\I18n\LocaleResolver($translator);
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

if (!function_exists('apiLocalizeErrorPayload')) {
    function apiLocalizeErrorPayload(array $payload, ?array $user = null): array
    {
        if (!empty($payload['error_code'])) {
            $payload['error'] = apiLocalizedText((string)$payload['error_code'], (string)($payload['error'] ?? ''), $user);
        }
        return $payload;
    }
}

SimpleRouter::group(['prefix' => '/admin'], function() {

    // Admin dashboard
    SimpleRouter::get('/', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $adminController = new AdminController();
        $stats = $adminController->getSystemStats();
        $dbVersion = $adminController->getDatabaseVersion();
        $config = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
        $systemAddresses = [$config->getSystemAddress()];
        foreach ($config->getUplinks() as $uplink) {
            if (!empty($uplink['me'])) {
                $systemAddresses[] = $uplink['me'];
            }
        }
        $systemAddresses = array_values(array_unique(array_filter($systemAddresses)));
        $dbStats = new \BinktermPHP\DatabaseStats(\BinktermPHP\Database::getInstance()->getPdo());
        $template->renderResponse('admin/dashboard.twig', [
            'stats' => $stats,
            'db_version' => $dbVersion,
            'daemon_status' => \BinktermPHP\SystemStatus::getDaemonStatus(),
            'git_commit' => \BinktermPHP\SystemStatus::getGitCommitHash(),
            'git_branch' => \BinktermPHP\SystemStatus::getGitBranch(),
            'system_addresses' => $systemAddresses,
            'db_summary' => $dbStats->getDashboardSummary(),
        ]);
    });

    // Licensing management page
    SimpleRouter::get('/licensing', function() {
        RouteHelper::requireAdmin();
        $template = new Template();
        $template->renderResponse('admin/licensing.twig');
    });

    // License registration info — serves REGISTER.md as HTML
    SimpleRouter::get('/api/register-info', function() {
        RouteHelper::requireAdmin();
        header('Content-Type: application/json');
        $path = __DIR__ . '/../REGISTER.md';
        if (!file_exists($path)) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            return;
        }
        $html = \BinktermPHP\MarkdownRenderer::toHtml(file_get_contents($path));
        echo json_encode(['html' => $html]);
    });

    SimpleRouter::get('/api/ram-usage', function() {
        $user = RouteHelper::requireAdmin();
        header('Content-Type: application/json');

        $adminController = new AdminController();
        $output = $adminController->getRamUsageDetails();
        if ($output === null) {
            http_response_code(404);
            apiError(
                'errors.admin.dashboard.ram_usage_unavailable',
                apiLocalizedText('errors.admin.dashboard.ram_usage_unavailable', 'RAM usage details are not available on this system.', $user)
            );
            return;
        }

        echo json_encode([
            'success' => true,
            'output' => $output,
        ]);
    });

    // License API — GET: current status
    SimpleRouter::get('/api/license', function() {
        RouteHelper::requireAdmin();
        header('Content-Type: application/json');
        echo json_encode(['status' => \BinktermPHP\License::getStatus()]);
    });

    // License API — POST: install a new license
    SimpleRouter::post('/api/license', function() {
        RouteHelper::requireAdmin();
        header('Content-Type: application/json');

        try {
            if (!empty($_FILES['license_file'])) {
                $upload = $_FILES['license_file'];
                if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new \RuntimeException('Failed to receive uploaded license file.');
                }
                $licenseData = extractUploadedLicenseData($upload);
            } else {
                $input = json_decode(file_get_contents('php://input'), true);
                $licenseData = $input['license'] ?? null;

                if (!is_array($licenseData) || !isset($licenseData['payload'], $licenseData['signature'])) {
                    throw new \RuntimeException('Invalid license format. Expected {payload: {...}, signature: "..."}.' );
                }
            }
        } catch (\RuntimeException $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            return;
        }

        // Verify signature before passing to the daemon for installation.
        // Write to a temp file so License::getStatus() can parse and verify it.
        $tmpPath = tempnam(sys_get_temp_dir(), 'binklic_');
        file_put_contents($tmpPath, json_encode($licenseData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        \BinktermPHP\License::clearCache();
        $originalEnv = $_ENV['LICENSE_FILE'] ?? null;
        $_ENV['LICENSE_FILE'] = $tmpPath;
        putenv('LICENSE_FILE=' . $tmpPath);

        $status = \BinktermPHP\License::getStatus();

        if ($originalEnv !== null) {
            $_ENV['LICENSE_FILE'] = $originalEnv;
            putenv('LICENSE_FILE=' . $originalEnv);
        } else {
            unset($_ENV['LICENSE_FILE']);
            putenv('LICENSE_FILE');
        }
        \BinktermPHP\License::clearCache();
        @unlink($tmpPath);

        if (!$status['valid']) {
            $reason = $status['reason'] ?? 'unknown';
            $reasonMessages = [
                'invalid_signature' => 'Signature verification failed. This license was not issued by the BinktermPHP project.',
                'expired'           => 'This license has expired.',
                'malformed'         => 'License file is malformed or missing required fields.',
                'invalid_key'       => 'License key data is invalid.',
            ];
            $msg = $reasonMessages[$reason] ?? "License is not valid (reason: {$reason}).";
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $msg]);
            return;
        }

        // Delegate the actual file write to the admin daemon (web process has no write access).
        try {
            $daemon = new \BinktermPHP\Admin\AdminDaemonClient();
            $daemon->setLicense($licenseData);
            $daemon->close();
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Admin daemon error: ' . $e->getMessage()]);
            return;
        }

        \BinktermPHP\License::clearCache();
        echo json_encode([
            'success' => true,
            'message' => 'License installed successfully. ' . ucfirst($status['tier']) . ' edition activated.',
            'status'  => \BinktermPHP\License::getStatus(),
        ]);
    });

    // License API — DELETE: remove license file
    SimpleRouter::delete('/api/license', function() {
        RouteHelper::requireAdmin();
        header('Content-Type: application/json');

        try {
            $daemon = new \BinktermPHP\Admin\AdminDaemonClient();
            $daemon->deleteLicense();
            $daemon->close();
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Admin daemon error: ' . $e->getMessage()]);
            return;
        }

        \BinktermPHP\License::clearCache();
        echo json_encode(['success' => true, 'message' => 'License removed. Running Community Edition.']);
    });

    // Database statistics page
    SimpleRouter::get('/database-stats', function() {
        $user = RouteHelper::requireAdmin();

        $db = \BinktermPHP\Database::getInstance()->getPdo();
        $dbStats = new \BinktermPHP\DatabaseStats($db);

        $template = new Template();
        $template->renderResponse('admin/database_stats.twig', [
            'size'        => $dbStats->getSizeAndGrowth(),
            'activity'    => $dbStats->getActivity(),
            'queries'     => $dbStats->getQueryPerformance(),
            'replication' => $dbStats->getReplication(),
            'maintenance' => $dbStats->getMaintenanceHealth(),
            'indexes'     => $dbStats->getIndexHealth(),
            'i18n_catalogs' => $dbStats->getI18nCatalogStats(),
        ]);
    });

    // Users management page
    SimpleRouter::get('/users', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/users.twig');
    });

    // Chat rooms management page
    SimpleRouter::get('/chat-rooms', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/chat_rooms.twig');
    });

    // Polls management page
    SimpleRouter::get('/polls', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/polls.twig');
    });

    // Shoutbox moderation page
    SimpleRouter::get('/shoutbox', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/shoutbox.twig');
    });

    // Binkp configuration page
    SimpleRouter::get('/binkp-config', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/binkp_config.twig', [
            'timezone_list' => \DateTimeZone::listIdentifiers()
        ]);
    });

    // Webdoors config page
    SimpleRouter::get('/webdoors', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/webdoors_config.twig');
    });

    // DOSDoors config page
    SimpleRouter::get('/dosdoors', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/dosdoors_config.twig');
    });

    // Native Doors config page
    SimpleRouter::get('/native-doors', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/nativedoors_config.twig');
    });

    // File area rules page
    SimpleRouter::get('/filearea-rules', function() {
        $user = RouteHelper::requireAdmin();

        $fileAreaManager = new \BinktermPHP\FileAreaManager();
        $fileAreas = $fileAreaManager->getFileAreas('all', (int)($user['user_id'] ?? $user['id'] ?? 0), true);
        $fileAreaOptions = [];
        foreach ($fileAreas as $area) {
            $tag = strtoupper(trim((string)($area['tag'] ?? '')));
            $domain = trim((string)($area['domain'] ?? ''));
            if ($tag === '') {
                continue;
            }

            $value = $domain !== ''
                ? $tag . '@' . $domain
                : $tag;

            $fileAreaOptions[$value] = [
                'value' => $value,
                'label' => $value,
            ];
        }
        ksort($fileAreaOptions, SORT_NATURAL | SORT_FLAG_CASE);

        $template = new Template();
        $template->renderResponse('admin/filearea_rules.twig', [
            'filearea_rule_options' => array_values($fileAreaOptions),
        ]);
    });

    // File upload approval queue
    SimpleRouter::get('/file-approvals', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/file_approvals.twig', [
            'virus_scan_disabled' => \BinktermPHP\Config::env('VIRUS_SCAN_DISABLED', 'false') === 'true',
        ]);
    });

    // Advertisements management page
    SimpleRouter::get('/ads', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/ads.twig', [
            'weather_configured' => file_exists(__DIR__ . '/../config/weather.json'),
        ]);
    });

    SimpleRouter::get('/ad-campaigns', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/ad_campaigns.twig', [
            'weather_configured' => file_exists(__DIR__ . '/../config/weather.json'),
        ]);
    });

    // BBS settings page
    SimpleRouter::get('/bbs-settings', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/bbs_settings.twig', [
            'timezone_list' => \DateTimeZone::listIdentifiers(),
        ]);
    });

    // Appearance & Content settings page
    SimpleRouter::get('/appearance', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/appearance.twig', [
            'available_themes' => \BinktermPHP\Config::getThemes(),
        ]);
    });

    // MRC Chat settings page
    SimpleRouter::get('/mrc-settings', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/mrc_settings.twig');
    });

    // Custom template editor page
    SimpleRouter::get('/template-editor', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/template_editor.twig');
    });

    // Language overlay editor page
    SimpleRouter::get('/i18n-overrides', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/i18n_overrides.twig');
    });

    // Upgrade notes viewer
    SimpleRouter::get('/register', function() {
        RouteHelper::requireAdmin();

        $docPath = __DIR__ . '/../REGISTER.md';
        $raw  = file_exists($docPath) ? file_get_contents($docPath) : null;
        $html = $raw !== null ? \BinktermPHP\MarkdownRenderer::toHtml($raw) : null;

        $template = new Template();
        $template->renderResponse('admin/register_info.twig', [
            'content' => $html,
        ]);
    });

    SimpleRouter::get('/upgrade-notes', function() {
        RouteHelper::requireAdmin();

        $version = \BinktermPHP\Version::getVersion();
        $docPath = __DIR__ . '/../docs/UPGRADING_' . $version . '.md';

        if (!file_exists($docPath)) {
            http_response_code(404);
            $template = new Template();
            $template->renderResponse('admin/upgrade_notes.twig', [
                'version'  => $version,
                'content'  => null,
            ]);
            return;
        }

        $raw = file_get_contents($docPath);
        $html = \BinktermPHP\MarkdownRenderer::toHtml($raw);

        $template = new Template();
        $template->renderResponse('admin/upgrade_notes.twig', [
            'version' => $version,
            'content' => $html,
        ]);
    });

    // Documentation browser
    SimpleRouter::get('/docs', function() {
        RouteHelper::requireAdmin();
        $controller = new \BinktermPHP\Web\DocsController();
        $controller->index();
    });

    SimpleRouter::get('/docs/view/{name}', function(string $name) {
        RouteHelper::requireAdmin();
        $controller = new \BinktermPHP\Web\DocsController();
        $controller->view($name);
    })->where(['name' => '[A-Za-z0-9_.\-]+']);

    SimpleRouter::get('/docs/asset/{path}', function(string $path) {
        RouteHelper::requireAdmin();
        $controller = new \BinktermPHP\Web\DocsController();
        $controller->asset($path);
    })->where(['path' => '[A-Za-z0-9_.\-\/]+']);

    // Ad analytics page (license required)
    SimpleRouter::get('/ad-analytics', function() {
        RouteHelper::requireAdmin();

        if (!\BinktermPHP\License::isValid()) {
            http_response_code(403);
            $template = new Template();
            $template->renderResponse('errors/403.twig');
            return;
        }

        $template = new Template();
        $template->renderResponse('admin/ad_analytics.twig');
    });

    // SSE diagnostics page
    SimpleRouter::get('/sse-test', function() {
        RouteHelper::requireAdmin();
        $template = new Template();
        $template->renderResponse('admin/sse_test.twig');
    });

    // SSE diagnostics stream — emits events at a configurable interval
    SimpleRouter::get('/sse-test/stream', function() {
        RouteHelper::requireAdmin();

        $intervalMs  = max(50,  min(5000, (int)($_GET['interval']  ?? 500)));
        $durationSec = max(5,   min(120,  (int)($_GET['duration']  ?? 30)));
        $payloadSize = max(0,   min(65536, (int)($_GET['payload']  ?? 0)));

        // Disable all output buffering
        while (ob_get_level()) {
            ob_end_clean();
        }
        ob_implicit_flush(true);

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');  // tell nginx not to buffer
        header('Connection: keep-alive');

        set_time_limit(0);
        ignore_user_abort(true);

        $padding  = $payloadSize > 0 ? str_repeat('x', $payloadSize) : '';
        $seq      = 0;
        $start    = microtime(true);
        $intervalSec = $intervalMs / 1000.0;

        // Send an initial event so the client knows the stream is open
        $initTs = round(microtime(true) * 1000);
        echo "event: open\n";
        echo "data: " . json_encode(['server_ts' => $initTs, 'interval_ms' => $intervalMs, 'duration_sec' => $durationSec, 'payload_size' => $payloadSize]) . "\n\n";
        flush();

        while (!connection_aborted() && (microtime(true) - $start) < $durationSec) {
            $serverTs = round(microtime(true) * 1000);
            $data = json_encode(['seq' => $seq, 'server_ts' => $serverTs, 'padding' => $padding]);
            echo "id: {$seq}\n";
            echo "data: {$data}\n\n";
            flush();
            $seq++;

            // Sleep until the next scheduled event time, accounting for drift
            $nextEvent = $start + ($seq * $intervalSec);
            $sleepSec  = $nextEvent - microtime(true);
            if ($sleepSec > 0) {
                usleep((int)($sleepSec * 1_000_000));
            }
        }

        $finalTs = round(microtime(true) * 1000);
        echo "event: done\n";
        echo "data: " . json_encode(['seq' => $seq, 'total' => $seq, 'server_ts' => $finalTs]) . "\n\n";
        flush();
    });

    // SSE real-path inject — inserts a single sse_test event into sse_events
    // so the real /api/stream polling path can be benchmarked end-to-end.
    SimpleRouter::post('/sse-test/inject', function() {
        $user   = RouteHelper::requireAdmin();
        $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);

        header('Content-Type: application/json');

        $input    = json_decode(file_get_contents('php://input'), true);
        $seq      = (int)($input['seq'] ?? 0);
        $serverTs = (int)round(microtime(true) * 1000);

        $db = \BinktermPHP\Database::getInstance()->getPdo();
        $eventId = \BinktermPHP\Realtime\BinkStream::emit($db, 'sse_test', [
            'seq' => $seq,
            'server_ts' => $serverTs,
        ], $userId);

        echo json_encode(['ok' => true, 'seq' => $seq, 'server_ts' => $serverTs, 'sse_id' => (int)$eventId]);
    });

    // Buffering diagnostics page
    SimpleRouter::get('/buffer-test', function() {
        RouteHelper::requireAdmin();
        $template = new Template();
        $template->renderResponse('admin/buffer_test.twig');
    });

    // Buffering diagnostics stream — sends one event per second for N seconds.
    // No SharedWorker, no sse_events — raw EventSource to the browser.
    // If events arrive one-by-one the chain is not buffering; if they all arrive
    // at the end something upstream is holding the response body.
    SimpleRouter::get('/buffer-test/stream', function() {
        RouteHelper::requireAdmin();

        if (ob_get_level()) {
            ob_end_clean();
        }
        ob_implicit_flush(true);
        // Do NOT set ignore_user_abort — we want the loop to exit when the
        // client closes the connection so Stop actually stops the stream.

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');

        $count = max(3, min(20, (int)($_GET['count'] ?? 8)));

        // Padding comment: force upstream proxy buffers to flush immediately.
        // Some proxies hold the first chunk until they accumulate enough bytes
        // to decide on Transfer-Encoding; 2 KB of SSE comment fills those buffers.
        echo ':' . str_repeat(' ', 2048) . "\n\n";
        flush();

        for ($i = 0; $i < $count; $i++) {
            if (connection_aborted()) {
                break;
            }
            $ts = (int)round(microtime(true) * 1000);
            echo "event: tick\n";
            echo "data: " . json_encode(['seq' => $i, 'total' => $count, 'server_ts' => $ts]) . "\n\n";
            flush();
            if ($i < $count - 1) {
                sleep(1);
                if (connection_aborted()) {
                    break;
                }
            }
        }

        if (!connection_aborted()) {
            echo "event: done\ndata: {}\n\n";
            flush();
        }
    });

    // Activity statistics page
    SimpleRouter::get('/activity-stats', function() {
        $user = RouteHelper::requireAdmin();
        $userId = $user['user_id'] ?? $user['id'] ?? null;

        $timezone = 'UTC';
        if ($userId) {
            $handler = new \BinktermPHP\MessageHandler();
            $settings = $handler->getUserSettings((int)$userId);
            $timezone = $settings['timezone'] ?? 'UTC';
        }

        $template = new Template();
        $template->renderResponse('admin/activity_stats.twig', ['user_timezone' => $timezone]);
    });

    SimpleRouter::get('/ai-usage', function() {
        $user = RouteHelper::requireAdmin();
        $userId = $user['user_id'] ?? $user['id'] ?? null;

        $timezone = 'UTC';
        if ($userId) {
            $handler = new \BinktermPHP\MessageHandler();
            $settings = $handler->getUserSettings((int)$userId);
            $timezone = $settings['timezone'] ?? 'UTC';
        }

        $period = (string)($_GET['period'] ?? '7d');
        $report = (new \BinktermPHP\AI\AiUsageReport())->getReport($period, $timezone);

        $template = new Template();
        $template->renderResponse('admin/ai_usage.twig', [
            'report' => $report,
            'user_timezone' => $timezone,
        ]);
    });

    SimpleRouter::get('/sharing', function() {
        RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/sharing.twig');
    });

    SimpleRouter::get('/referrals', function() {
        RouteHelper::requireAdmin();

        if (!\BinktermPHP\License::isValid()) {
            http_response_code(403);
            $template = new Template();
            $template->renderResponse('errors/403.twig');
            return;
        }

        $template = new Template();
        $template->renderResponse('admin/referrals.twig');
    });

    SimpleRouter::get('/economy', function() {
        RouteHelper::requireAdmin();

        if (!\BinktermPHP\License::isValid()) {
            http_response_code(403);
            $template = new Template();
            $template->renderResponse('errors/403.twig');
            return;
        }

        $template = new Template();
        $template->renderResponse('admin/economy.twig');
    });

    // API routes for admin
    SimpleRouter::group(['prefix' => '/api'], function() {

        // Get all users with pagination and search
        SimpleRouter::get('/users', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            $page = intval($_GET['page'] ?? 1);
            $limit = intval($_GET['limit'] ?? 25);
            $search = $_GET['search'] ?? '';

            header('Content-Type: application/json');
            $result = $adminController->getAllUsers($page, $limit, $search);
            $result = apiLocalizeErrorPayload($result, $user);
            echo json_encode($result);
        });

        SimpleRouter::get('/admin-users', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            $db = \BinktermPHP\Database::getInstance()->getPdo();
            $stmt = $db->prepare("SELECT real_name FROM users WHERE is_admin = TRUE AND real_name IS NOT NULL ORDER BY real_name");
            $stmt->execute();
            $admins = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            echo json_encode(['admins' => $admins]);
        });

        // Get specific user
        SimpleRouter::get('/users/{id}', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');
            $userData = $adminController->getUser($id);
            if ($userData) {
                $stats = $adminController->getUserStats($id);
                $userData['stats'] = $stats;
                echo json_encode(['user' => $userData]);
            } else {
                http_response_code(404);
                apiError('errors.admin.users.not_found', apiLocalizedText('errors.admin.users.not_found', 'User not found'));
            }
        });

        // Finger a user by username — used by the admin terminal
        SimpleRouter::get('/finger/{username}', function($username) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            $db = \BinktermPHP\Database::getInstance()->getPdo();
            $stmt = $db->prepare("
                SELECT id, username, real_name, location, fidonet_address,
                       is_active, is_admin, created_at, last_login
                FROM users
                WHERE LOWER(username) = LOWER(?)
                LIMIT 1
            ");
            $stmt->execute([$username]);
            $target = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$target) {
                http_response_code(404);
                echo json_encode(['error' => 'User not found']);
                return;
            }

            // Fetch any active sessions for this user
            $sessions = $auth->getOnlineSessions(15);
            $userSessions = array_values(array_filter($sessions, function($s) use ($target) {
                return (int)$s['user_id'] === (int)$target['id'];
            }));

            $online = array_map(function($s) {
                return [
                    'service'       => $s['service'] ?? 'web',
                    'activity'      => $s['activity'] ?? '',
                    'last_activity' => $s['last_activity'] ?? null,
                    'ip_address'    => $s['ip_address'] ?? null,
                ];
            }, $userSessions);

            echo json_encode([
                'username'       => $target['username'],
                'real_name'      => $target['real_name'],
                'location'       => $target['location'],
                'fidonet_address'=> $target['fidonet_address'],
                'is_active'      => (bool)$target['is_active'],
                'is_admin'       => (bool)$target['is_admin'],
                'created_at'     => $target['created_at'],
                'last_login'     => $target['last_login'],
                'online'         => $online,
            ]);
        });

        // Create new user
        SimpleRouter::post('/users', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $input = json_decode(file_get_contents('php://input'), true);
                $userId = $adminController->createUser($input);
                echo json_encode([
                    'success' => true,
                    'user_id' => $userId,
                    'message_code' => 'ui.admin.users.created_success'
                ]);
            } catch (Exception $e) {
                http_response_code(400);
                apiError('errors.admin.users.create_failed', apiLocalizedText('errors.admin.users.create_failed', 'Failed to create user'));
            }
        });

        // Update user
        SimpleRouter::put('/users/{id}', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $input = json_decode(file_get_contents('php://input'), true);
                $result = $adminController->updateUser($id, $input);
                if ($result) {
                    echo json_encode([
                        'success' => true,
                        'message_code' => 'ui.admin.users.updated_success'
                    ]);
                } else {
                    echo json_encode(['success' => false]);
                }
            } catch (Exception $e) {
                http_response_code(400);
                apiError('errors.admin.users.update_failed', apiLocalizedText('errors.admin.users.update_failed', 'Failed to update user'));
            }
        });

        // Delete user
        SimpleRouter::delete('/users/{id}', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $result = $adminController->deleteUser($id);
                if ($result) {
                    echo json_encode([
                        'success' => true,
                        'message_code' => 'ui.admin.users.deleted_success'
                    ]);
                } else {
                    echo json_encode(['success' => false]);
                }
            } catch (Exception $e) {
                http_response_code(400);
                apiError('errors.admin.users.delete_failed', apiLocalizedText('errors.admin.users.delete_failed', 'Failed to delete user'));
            }
        });

        // Referral analytics (premium)
        SimpleRouter::get('/referrals', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();
            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            if (!\BinktermPHP\License::isValid()) {
                http_response_code(403);
                header('Content-Type: application/json');
                apiError('errors.referrals.not_licensed', apiLocalizedText('errors.referrals.not_licensed', 'Referral analytics require a registered license', $user));
                return;
            }

            header('Content-Type: application/json');
            $db = \BinktermPHP\Database::getInstance()->getPdo();

            // Top referrers: users who have referred others
            $referrersStmt = $db->query("
                SELECT
                    u.id,
                    u.username,
                    u.real_name,
                    u.referral_code,
                    COUNT(r.id) AS referral_count,
                    COALESCE(SUM(ct.amount), 0) AS bonus_earned
                FROM users u
                LEFT JOIN users r ON r.referred_by = u.id
                LEFT JOIN user_transactions ct
                    ON ct.user_id = u.id AND ct.transaction_type = 'referral_bonus'
                GROUP BY u.id, u.username, u.real_name, u.referral_code
                HAVING COUNT(r.id) > 0
                ORDER BY referral_count DESC, bonus_earned DESC
                LIMIT 100
            ");
            $referrers = $referrersStmt->fetchAll(\PDO::FETCH_ASSOC);

            // Recent referral signups
            $recentStmt = $db->query("
                SELECT
                    u.id,
                    u.username,
                    u.real_name,
                    u.created_at,
                    ref.username AS referred_by_username,
                    ref.real_name AS referred_by_real_name
                FROM users u
                JOIN users ref ON ref.id = u.referred_by
                ORDER BY u.created_at DESC
                LIMIT 50
            ");
            $recent = $recentStmt->fetchAll(\PDO::FETCH_ASSOC);

            // Summary totals
            $totalsStmt = $db->query("
                SELECT
                    COUNT(*) AS total_referred_users,
                    COUNT(DISTINCT referred_by) AS total_referrers
                FROM users
                WHERE referred_by IS NOT NULL
            ");
            $totals = $totalsStmt->fetch(\PDO::FETCH_ASSOC);

            echo json_encode([
                'referrers' => $referrers,
                'recent'    => $recent,
                'totals'    => $totals,
            ]);
        });

        // Get system stats
        SimpleRouter::get('/stats', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');
            $stats = $adminController->getSystemStats();
            echo json_encode($stats);
        });

        SimpleRouter::get('/economy', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            if (!\BinktermPHP\License::isValid()) {
                http_response_code(403);
                header('Content-Type: application/json');
                apiError('errors.economy.not_licensed', apiLocalizedText('errors.economy.not_licensed', 'Economy viewer requires a registered license', $user));
                return;
            }

            $period = $_GET['period'] ?? '30d';

            header('Content-Type: application/json');
            echo json_encode($adminController->getEconomyStats($period));
        });

        // Chat rooms
        SimpleRouter::get('/chat-rooms', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            $db = \BinktermPHP\Database::getInstance()->getPdo();
            $stmt = $db->prepare("
                SELECT id, name, description, is_active, created_at
                FROM chat_rooms
                ORDER BY name
            ");
            $stmt->execute();
            $rooms = $stmt->fetchAll();

            echo json_encode(['rooms' => $rooms]);
        });

        // Polls
        SimpleRouter::get('/polls', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');
            $db = \BinktermPHP\Database::getInstance()->getPdo();

            $pollStmt = $db->prepare("
                SELECT p.id, p.question, p.is_active, p.created_at, p.updated_at,
                       u.username as created_by_username,
                       COUNT(v.id) as vote_count
                FROM polls p
                LEFT JOIN users u ON u.id = p.created_by
                LEFT JOIN poll_votes v ON v.poll_id = p.id
                GROUP BY p.id, u.username
                ORDER BY p.created_at DESC
            ");
            $pollStmt->execute();
            $polls = $pollStmt->fetchAll();

            $optionsStmt = $db->prepare("
                SELECT id, poll_id, option_text, sort_order
                FROM poll_options
                ORDER BY sort_order, id
            ");
            $optionsStmt->execute();
            $options = $optionsStmt->fetchAll();
            $optionsByPoll = [];
            foreach ($options as $opt) {
                $optionsByPoll[$opt['poll_id']][] = [
                    'id' => (int)$opt['id'],
                    'option_text' => $opt['option_text'],
                    'sort_order' => (int)$opt['sort_order']
                ];
            }

            $payload = [];
            foreach ($polls as $poll) {
                $payload[] = [
                    'id' => (int)$poll['id'],
                    'question' => $poll['question'],
                    'is_active' => (bool)$poll['is_active'],
                    'created_at' => $poll['created_at'],
                    'updated_at' => $poll['updated_at'],
                    'created_by_username' => $poll['created_by_username'] ?? 'Unknown',
                    'vote_count' => (int)$poll['vote_count'],
                    'options' => $optionsByPoll[$poll['id']] ?? []
                ];
            }

            echo json_encode(['polls' => $payload]);
        });

        SimpleRouter::post('/polls', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $input = json_decode(file_get_contents('php://input'), true);
                $question = trim($input['question'] ?? '');
                $options = $input['options'] ?? [];
                $isActive = !empty($input['is_active']);

                if ($question === '') {
                    throw new Exception('Question is required');
                }
                if (!is_array($options) || count($options) < 2) {
                    throw new Exception('At least two options are required');
                }

                $db = \BinktermPHP\Database::getInstance()->getPdo();
                $db->beginTransaction();

                $pollStmt = $db->prepare("
                    INSERT INTO polls (question, is_active, created_by)
                    VALUES (?, ?, ?)
                    RETURNING id
                ");
                $pollStmt->execute([$question, $isActive ? 1 : 0, $user['id'] ?? $user['user_id']]);
                $pollId = $pollStmt->fetchColumn();

                $optStmt = $db->prepare("
                    INSERT INTO poll_options (poll_id, option_text, sort_order)
                    VALUES (?, ?, ?)
                ");
                $order = 0;
                foreach ($options as $optionText) {
                    $optionText = trim($optionText);
                    if ($optionText === '') {
                        continue;
                    }
                    $optStmt->execute([$pollId, $optionText, $order++]);
                }
                if ($order < 2) {
                    throw new Exception('At least two valid options are required');
                }

                $db->commit();
                echo json_encode([
                    'success' => true,
                    'id' => (int)$pollId,
                    'message_code' => 'ui.admin.polls.created_success'
                ]);
            } catch (Exception $e) {
                if (isset($db) && $db->inTransaction()) {
                    $db->rollBack();
                }
                http_response_code(400);
                $message = $e->getMessage();
                if ($message === 'Question is required') {
                    apiError('errors.admin.polls.question_required', apiLocalizedText('errors.admin.polls.question_required', 'Question is required'));
                } elseif ($message === 'At least two options are required' || $message === 'At least two valid options are required') {
                    apiError('errors.admin.polls.options_required', apiLocalizedText('errors.admin.polls.options_required', 'At least two options are required'));
                } else {
                    apiError('errors.admin.polls.create_failed', apiLocalizedText('errors.admin.polls.create_failed', 'Failed to create poll'));
                }
            }
        });

        SimpleRouter::put('/polls/{id}', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $input = json_decode(file_get_contents('php://input'), true);
                $question = trim($input['question'] ?? '');
                $options = $input['options'] ?? [];
                $isActive = !empty($input['is_active']);

                if ($question === '') {
                    throw new Exception('Question is required');
                }
                if (!is_array($options) || count($options) < 2) {
                    throw new Exception('At least two options are required');
                }

                $db = \BinktermPHP\Database::getInstance()->getPdo();
                $db->beginTransaction();

                $existingStmt = $db->prepare("SELECT id FROM polls WHERE id = ?");
                $existingStmt->execute([$id]);
                if (!$existingStmt->fetch()) {
                    throw new Exception('Poll not found');
                }

                $updateStmt = $db->prepare("
                    UPDATE polls
                    SET question = ?, is_active = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([$question, $isActive ? 1 : 0, $id]);

                $db->prepare("DELETE FROM poll_votes WHERE poll_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM poll_options WHERE poll_id = ?")->execute([$id]);

                $optStmt = $db->prepare("
                    INSERT INTO poll_options (poll_id, option_text, sort_order)
                    VALUES (?, ?, ?)
                ");
                $order = 0;
                foreach ($options as $optionText) {
                    $optionText = trim($optionText);
                    if ($optionText === '') {
                        continue;
                    }
                    $optStmt->execute([$id, $optionText, $order++]);
                }
                if ($order < 2) {
                    throw new Exception('At least two valid options are required');
                }

                $db->commit();
                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.admin.polls.updated_success'
                ]);
            } catch (Exception $e) {
                if (isset($db) && $db->inTransaction()) {
                    $db->rollBack();
                }
                http_response_code(400);
                $message = $e->getMessage();
                if ($message === 'Question is required') {
                    apiError('errors.admin.polls.question_required', apiLocalizedText('errors.admin.polls.question_required', 'Question is required'));
                } elseif ($message === 'At least two options are required' || $message === 'At least two valid options are required') {
                    apiError('errors.admin.polls.options_required', apiLocalizedText('errors.admin.polls.options_required', 'At least two options are required'));
                } elseif ($message === 'Poll not found') {
                    apiError('errors.admin.polls.not_found', apiLocalizedText('errors.admin.polls.not_found', 'Poll not found'));
                } else {
                    apiError('errors.admin.polls.update_failed', apiLocalizedText('errors.admin.polls.update_failed', 'Failed to update poll'));
                }
            }
        });

        SimpleRouter::delete('/polls/{id}', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $db = \BinktermPHP\Database::getInstance()->getPdo();
                $stmt = $db->prepare("DELETE FROM polls WHERE id = ?");
                $stmt->execute([$id]);
                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.admin.polls.deleted_success'
                ]);
            } catch (Exception $e) {
                http_response_code(400);
                apiError('errors.admin.polls.delete_failed', apiLocalizedText('errors.admin.polls.delete_failed', 'Failed to delete poll'));
            }
        });

        // Shoutbox moderation
        SimpleRouter::get('/shoutbox', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');
            $limit = intval($_GET['limit'] ?? 100);
            $messages = $adminController->getShoutboxMessages($limit);
            echo json_encode(['messages' => $messages]);
        });

        SimpleRouter::post('/shoutbox/{id}/hide', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');
            $result = $adminController->setShoutboxHidden((int)$id, true);
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.admin.shoutbox.hidden_success'
                ]);
            } else {
                echo json_encode(['success' => false]);
            }
        });

        SimpleRouter::post('/shoutbox/{id}/unhide', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');
            $result = $adminController->setShoutboxHidden((int)$id, false);
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.admin.shoutbox.unhidden_success'
                ]);
            } else {
                echo json_encode(['success' => false]);
            }
        });

        SimpleRouter::delete('/shoutbox/{id}', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');
            $result = $adminController->deleteShoutboxMessage((int)$id);
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.admin.shoutbox.deleted_success'
                ]);
            } else {
                echo json_encode(['success' => false]);
            }
        });

        // BBS settings
        SimpleRouter::get('/bbs-settings', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $config = $client->getBbsConfig();
                echo json_encode(['success' => true, 'config' => $config]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.bbs_settings.load_failed', apiLocalizedText('errors.admin.bbs_settings.load_failed', 'Failed to load BBS settings'));
            }
        });

        SimpleRouter::post('/bbs-settings', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);

                $payload = json_decode(file_get_contents('php://input'), true);
                $config = $payload['config'] ?? [];
                if (!is_array($config)) {
                    throw new Exception('Invalid configuration payload');
                }

                if (array_key_exists('credits', $config)) {
                    if (!is_array($config['credits'])) {
                        throw new Exception('Invalid credits configuration');
                    }
                    $credits = $config['credits'];
                    $symbol = trim((string)($credits['symbol'] ?? ''));
                    if (mb_strlen($symbol) > 5) {
                        throw new Exception('Currency symbol must be 0-5 characters');
                    }
                    if (!is_numeric($credits['daily_amount'] ?? null) || (int)$credits['daily_amount'] < 0) {
                        throw new Exception('Daily login amount must be a non-negative integer');
                    }
                    if (!is_numeric($credits['daily_login_delay_minutes'] ?? null) || (int)$credits['daily_login_delay_minutes'] < 0) {
                        throw new Exception('Daily login delay must be a non-negative integer');
                    }
                    if (!is_numeric($credits['approval_bonus'] ?? null) || (int)$credits['approval_bonus'] < 0) {
                        throw new Exception('Approval bonus must be a non-negative integer');
                    }
                    if (!is_numeric($credits['netmail_cost'] ?? null) || (int)$credits['netmail_cost'] < 0) {
                        throw new Exception('Netmail cost must be a non-negative integer');
                    }
                    if (!is_numeric($credits['echomail_reward'] ?? null) || (int)$credits['echomail_reward'] < 0) {
                        throw new Exception('Echomail reward must be a non-negative integer');
                    }
                    if (!is_numeric($credits['crashmail_cost'] ?? null) || (int)$credits['crashmail_cost'] < 0) {
                        throw new Exception('Crashmail cost must be a non-negative integer');
                    }
                    if (!is_numeric($credits['poll_creation_cost'] ?? null) || (int)$credits['poll_creation_cost'] < 0) {
                        throw new Exception('Poll creation cost must be a non-negative integer');
                    }
                    if (!is_numeric($credits['file_upload_cost'] ?? 0) || (int)($credits['file_upload_cost'] ?? 0) < 0) {
                        throw new Exception('File upload cost must be a non-negative integer');
                    }
                    if (!is_numeric($credits['file_upload_reward'] ?? 0) || (int)($credits['file_upload_reward'] ?? 0) < 0) {
                        throw new Exception('File upload reward must be a non-negative integer');
                    }
                    if (!is_numeric($credits['file_download_cost'] ?? 0) || (int)($credits['file_download_cost'] ?? 0) < 0) {
                        throw new Exception('File download cost must be a non-negative integer');
                    }
                    if (!is_numeric($credits['file_download_reward'] ?? 0) || (int)($credits['file_download_reward'] ?? 0) < 0) {
                        throw new Exception('File download reward must be a non-negative integer');
                    }
                    if (!is_numeric($credits['return_14days'] ?? null) || (int)$credits['return_14days'] < 0) {
                        throw new Exception('14-day return bonus must be a non-negative integer');
                    }
                    if (!is_numeric($credits['transfer_fee_percent'] ?? null) || (float)$credits['transfer_fee_percent'] < 0 || (float)$credits['transfer_fee_percent'] > 1) {
                        throw new Exception('Transfer fee must be between 0 and 1 (0% to 100%)');
                    }
                    if (isset($credits['referral_bonus']) && (!is_numeric($credits['referral_bonus']) || (int)$credits['referral_bonus'] < 0)) {
                        throw new Exception('Referral bonus must be a non-negative integer');
                    }
                    $config['credits'] = [
                        'enabled' => !empty($credits['enabled']),
                        'symbol' => $symbol,
                        'daily_amount' => (int)$credits['daily_amount'],
                        'daily_login_delay_minutes' => (int)$credits['daily_login_delay_minutes'],
                        'approval_bonus' => (int)$credits['approval_bonus'],
                        'netmail_cost' => (int)$credits['netmail_cost'],
                        'echomail_reward' => (int)$credits['echomail_reward'],
                        'crashmail_cost' => (int)$credits['crashmail_cost'],
                        'poll_creation_cost' => (int)$credits['poll_creation_cost'],
                        'file_upload_cost' => (int)($credits['file_upload_cost'] ?? 0),
                        'file_upload_reward' => (int)($credits['file_upload_reward'] ?? 0),
                        'file_download_cost' => (int)($credits['file_download_cost'] ?? 0),
                        'file_download_reward' => (int)($credits['file_download_reward'] ?? 0),
                        'return_14days' => (int)$credits['return_14days'],
                        'transfer_fee_percent' => (float)$credits['transfer_fee_percent'],
                        'referral_enabled' => !empty($credits['referral_enabled']),
                        'referral_bonus' => isset($credits['referral_bonus']) ? (int)$credits['referral_bonus'] : 25
                    ];
                }

                // Validate max_cross_post_areas if provided
                if (array_key_exists('max_cross_post_areas', $config)) {
                    $maxCrossPost = (int)$config['max_cross_post_areas'];
                    if ($maxCrossPost < 2 || $maxCrossPost > 20) {
                        throw new Exception('Max cross-post areas must be between 2 and 20');
                    }
                    $config['max_cross_post_areas'] = $maxCrossPost;
                }

                if (array_key_exists('dashboard_ad_rotate_interval_seconds', $config)) {
                    $dashboardAdRotateInterval = (int)$config['dashboard_ad_rotate_interval_seconds'];
                    if ($dashboardAdRotateInterval < 5 || $dashboardAdRotateInterval > 300) {
                        throw new Exception('Dashboard ad rotation interval must be between 5 and 300 seconds');
                    }
                    $config['dashboard_ad_rotate_interval_seconds'] = $dashboardAdRotateInterval;
                }

                if (isset($config['qwk']['bbs_id'])) {
                    $bbsId = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$config['qwk']['bbs_id']));
                    $bbsId = substr($bbsId, 0, 8);
                    $config['qwk']['bbs_id'] = $bbsId;
                }

                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $updated = $client->setBbsConfig($config);
                if ($userId) {
                    AdminActionLogger::logAction($userId, 'bbs_settings_updated', [
                        'credits' => $config['credits'] ?? null
                    ]);
                }
                echo json_encode([
                    'success' => true,
                    'config' => $updated,
                    'message_code' => 'ui.admin.bbs_settings.saved_success'
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                $message = $e->getMessage();
                if ($message === 'Invalid configuration payload') {
                    apiError('errors.admin.bbs_settings.invalid_payload', apiLocalizedText('errors.admin.bbs_settings.invalid_payload', 'Invalid configuration payload'));
                } elseif ($message === 'Invalid credits configuration') {
                    apiError('errors.admin.bbs_settings.invalid_credits_config', apiLocalizedText('errors.admin.bbs_settings.invalid_credits_config', 'Invalid credits configuration'));
                } else {
                    apiError('errors.admin.bbs_settings.save_failed', apiLocalizedText('errors.admin.bbs_settings.save_failed', 'Failed to save BBS settings'));
                }
            }
        });

        // ---------------------------------------------------------------
        // Appearance API
        // ---------------------------------------------------------------

        SimpleRouter::get('/appearance', function() {
            RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $data = $client->getAppearanceConfig();
                echo json_encode(['success' => true, 'data' => $data]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.appearance.load_failed', apiLocalizedText('errors.admin.appearance.load_failed', 'Failed to load appearance settings'));
            }
        });

        SimpleRouter::post('/appearance/branding', function() {
            RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true) ?? [];
                $branding = $payload['branding'] ?? [];

                $accentColor = trim((string)($branding['accent_color'] ?? ''));
                if ($accentColor !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $accentColor)) {
                    throw new Exception('Invalid accent color format');
                }

                $logoUrl = trim((string)($branding['logo_url'] ?? ''));
                $footerText = trim((string)($branding['footer_text'] ?? ''));
                if (mb_strlen($footerText) > 500) {
                    throw new Exception('Footer text must be 500 characters or less');
                }

                $config = \BinktermPHP\AppearanceConfig::getConfig();
                $config['branding']['accent_color'] = $accentColor;
                $config['branding']['default_theme'] = trim((string)($branding['default_theme'] ?? ''));
                $config['branding']['lock_theme'] = !empty($branding['lock_theme']);
                $config['branding']['logo_url'] = $logoUrl;
                $config['branding']['footer_text'] = $footerText;
                $config['branding']['hide_powered_by'] = !empty($branding['hide_powered_by']);
                $config['branding']['show_registration_badge'] = isset($branding['show_registration_badge']) ? (bool)$branding['show_registration_badge'] : true;

                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $client->setAppearanceConfig($config);
                \BinktermPHP\AppearanceConfig::reload();

                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.common.saved'
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                $message = $e->getMessage();
                if ($message === 'Invalid accent color format') {
                    apiError('errors.admin.appearance.branding.invalid_accent_color', apiLocalizedText('errors.admin.appearance.branding.invalid_accent_color', 'Invalid accent color format'));
                } elseif ($message === 'Footer text must be 500 characters or less') {
                    apiError('errors.admin.appearance.branding.footer_too_long', apiLocalizedText('errors.admin.appearance.branding.footer_too_long', 'Footer text must be 500 characters or less'));
                } else {
                    apiError('errors.admin.appearance.branding.save_failed', apiLocalizedText('errors.admin.appearance.branding.save_failed', 'Failed to save branding settings'));
                }
            }
        });

        SimpleRouter::post('/appearance/content', function() {
            RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true) ?? [];

                $client = new \BinktermPHP\Admin\AdminDaemonClient();

                // Save system news markdown
                if (array_key_exists('system_news', $payload)) {
                    $client->setSystemNews((string)$payload['system_news']);
                }

                // Save house rules markdown
                if (array_key_exists('house_rules', $payload)) {
                    $client->setHouseRules((string)$payload['house_rules']);
                }

                // Save announcement config
                if (array_key_exists('announcement', $payload)) {
                    $ann = $payload['announcement'];
                    $allowedTypes = ['info', 'warning', 'danger', 'success', 'primary'];
                    $annType = in_array($ann['type'] ?? '', $allowedTypes, true) ? $ann['type'] : 'info';

                    $config = \BinktermPHP\AppearanceConfig::getConfig();
                    $config['content']['announcement'] = [
                        'enabled' => !empty($ann['enabled']),
                        'text' => substr(strip_tags((string)($ann['text'] ?? '')), 0, 1000),
                        'type' => $annType,
                        'expires_at' => ($ann['expires_at'] ?? '') !== '' ? (string)$ann['expires_at'] : null,
                        'dismissible' => !empty($ann['dismissible']),
                    ];
                    $client->setAppearanceConfig($config);
                }

                \BinktermPHP\AppearanceConfig::reload();
                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.common.saved'
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.appearance.content.save_failed', apiLocalizedText('errors.admin.appearance.content.save_failed', 'Failed to save content settings'));
            }
        });

        SimpleRouter::post('/appearance/splash', function() {
            RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            if (!\BinktermPHP\License::isValid()) {
                http_response_code(403);
                apiError('errors.admin.appearance.splash.license_required', apiLocalizedText('errors.admin.appearance.splash.license_required', 'A valid license is required to configure splash pages'));
                return;
            }

            try {
                $payload = json_decode(file_get_contents('php://input'), true) ?? [];

                $client = new \BinktermPHP\Admin\AdminDaemonClient();

                if (array_key_exists('login_splash', $payload)) {
                    $text = (string)$payload['login_splash'];
                    if (mb_strlen($text) > 10000) {
                        throw new Exception('Splash content must be 10,000 characters or less');
                    }
                    $client->setLoginSplash($text);
                }

                if (array_key_exists('register_splash', $payload)) {
                    $text = (string)$payload['register_splash'];
                    if (mb_strlen($text) > 10000) {
                        throw new Exception('Splash content must be 10,000 characters or less');
                    }
                    $client->setRegisterSplash($text);
                }

                echo json_encode(['success' => true, 'message_code' => 'ui.common.saved']);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.appearance.splash.save_failed', apiLocalizedText('errors.admin.appearance.splash.save_failed', 'Failed to save splash settings'));
            }
        });

        SimpleRouter::post('/appearance/navigation', function() {
            RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true) ?? [];
                $links = $payload['custom_links'] ?? [];

                if (!is_array($links)) {
                    throw new Exception('Invalid custom_links payload');
                }

                $sanitized = [];
                foreach ($links as $link) {
                    $label = trim((string)($link['label'] ?? ''));
                    $url = trim((string)($link['url'] ?? ''));
                    if ($label === '' || $url === '') {
                        continue;
                    }
                    if (!preg_match('#^https?://#i', $url) && strpos($url, '/') !== 0) {
                        continue; // Only relative paths starting with / or absolute https? URLs
                    }
                    $sanitized[] = [
                        'label' => substr($label, 0, 100),
                        'url' => substr($url, 0, 500),
                        'new_tab' => !empty($link['new_tab']),
                    ];
                }

                $config = \BinktermPHP\AppearanceConfig::getConfig();
                $config['navigation']['custom_links'] = $sanitized;

                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $client->setAppearanceConfig($config);
                \BinktermPHP\AppearanceConfig::reload();

                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.common.saved'
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.appearance.navigation.save_failed', apiLocalizedText('errors.admin.appearance.navigation.save_failed', 'Failed to save navigation settings'));
            }
        });

        SimpleRouter::post('/appearance/seo', function() {
            RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true) ?? [];
                $seo = $payload['seo'] ?? [];

                $description = substr(trim((string)($seo['description'] ?? '')), 0, 300);
                $ogImage = trim((string)($seo['og_image_url'] ?? ''));

                $config = \BinktermPHP\AppearanceConfig::getConfig();
                $config['seo']['description'] = $description;
                $config['seo']['og_image_url'] = $ogImage;
                $config['seo']['about_page_enabled'] = !empty($seo['about_page_enabled']);

                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $client->setAppearanceConfig($config);
                \BinktermPHP\AppearanceConfig::reload();

                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.common.saved'
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.appearance.seo.save_failed', apiLocalizedText('errors.admin.appearance.seo.save_failed', 'Failed to save SEO settings'));
            }
        });

        SimpleRouter::post('/appearance/shell', function() {
            RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true) ?? [];
                $shell = $payload['shell'] ?? [];

                $activeShell = (string)($shell['active'] ?? 'web');
                if (!in_array($activeShell, ['web', 'bbs-menu'], true)) {
                    $activeShell = 'web';
                }

                $bbsMenu = $shell['bbs_menu'] ?? [];
                $variant = (string)($bbsMenu['variant'] ?? 'cards');
                if (!in_array($variant, ['cards', 'ansi', 'text'], true)) {
                    $variant = 'cards';
                }
                $ansiSize = (string)($bbsMenu['ansi_size'] ?? '80x25');
                if (!in_array($ansiSize, ['80x25', '132x24', '132x43', '132x50', 'full'], true)) {
                    $ansiSize = '80x25';
                }

                $menuItems = $bbsMenu['menu_items'] ?? [];
                $sanitizedItems = [];
                if (is_array($menuItems)) {
                    foreach ($menuItems as $item) {
                        $key = strtoupper(trim((string)($item['key'] ?? '')));
                        $label = trim((string)($item['label'] ?? ''));
                        $url = trim((string)($item['url'] ?? ''));
                        $icon = trim((string)($item['icon'] ?? 'circle'));
                        if (strlen($key) !== 1 || $label === '' || $url === '') {
                            continue;
                        }
                        $sanitizedItems[] = [
                            'key' => $key,
                            'label' => substr($label, 0, 100),
                            'icon' => preg_replace('/[^a-z0-9-]/', '', strtolower($icon)),
                            'url' => substr($url, 0, 500),
                        ];
                    }
                }

                $config = \BinktermPHP\AppearanceConfig::getConfig();
                $config['shell']['active'] = $activeShell;
                $config['shell']['lock_shell'] = !empty($shell['lock_shell']);
                $config['shell']['bbs_menu']['variant'] = $variant;
                $config['shell']['bbs_menu']['ansi_file'] = basename(trim((string)($bbsMenu['ansi_file'] ?? '')));
                $config['shell']['bbs_menu']['ansi_size'] = $ansiSize;
                if (!empty($sanitizedItems)) {
                    $config['shell']['bbs_menu']['menu_items'] = $sanitizedItems;
                }

                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $client->setAppearanceConfig($config);
                \BinktermPHP\AppearanceConfig::reload();

                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.admin.appearance.shell_saved_reload'
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.appearance.shell.save_failed', apiLocalizedText('errors.admin.appearance.shell.save_failed', 'Failed to save shell settings'));
            }
        });

        SimpleRouter::post('/appearance/message-reader', function() {
            RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true) ?? [];
                $mr = $payload['message_reader'] ?? [];

                $config = \BinktermPHP\AppearanceConfig::getConfig();
                $config['message_reader']['scrollable_body'] = !empty($mr['scrollable_body']);
                $config['message_reader']['email_link_url'] = trim((string)($mr['email_link_url'] ?? ''));

                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $client->setAppearanceConfig($config);
                \BinktermPHP\AppearanceConfig::reload();

                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.common.saved_short'
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.appearance.message_reader.save_failed', apiLocalizedText('errors.admin.appearance.message_reader.save_failed', 'Failed to save message reader settings'));
            }
        });

        SimpleRouter::post('/appearance/preview-markdown', function() {
            RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true) ?? [];
                $markdown = (string)($payload['markdown'] ?? '');
                $html = \BinktermPHP\MarkdownRenderer::toHtml($markdown);
                echo json_encode(['success' => true, 'html' => $html]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.appearance.markdown_preview.failed', apiLocalizedText('errors.admin.appearance.markdown_preview.failed', 'Failed to render markdown preview'));
            }
        });

        // Shell art management
        SimpleRouter::get('/shell-art', function() {
            RouteHelper::requireAdmin();
            header('Content-Type: application/json');
            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $files = $client->listShellArt();
                echo json_encode(['success' => true, 'files' => $files]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.shell_art.list_failed', apiLocalizedText('errors.admin.shell_art.list_failed', 'Failed to list shell art files'));
            }
        });

        SimpleRouter::post('/shell-art/upload', function() {
            RouteHelper::requireAdmin();
            header('Content-Type: application/json');
            try {
                if (empty($_FILES['file'])) {
                    http_response_code(400);
                    apiError('errors.admin.shell_art.upload.no_file', apiLocalizedText('errors.admin.shell_art.upload.no_file', 'No shell art file uploaded'));
                    return;
                }
                $file = $_FILES['file'];
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    http_response_code(400);
                    apiError('errors.admin.shell_art.upload.upload_error', apiLocalizedText('errors.admin.shell_art.upload.upload_error', 'Shell art upload failed'));
                    return;
                }
                // Max 512 KB for ANSI art
                if ($file['size'] > 524288) {
                    http_response_code(400);
                    apiError('errors.admin.shell_art.upload.file_too_large', apiLocalizedText('errors.admin.shell_art.upload.file_too_large', 'Shell art file exceeds size limit'));
                    return;
                }
                $originalName = basename($file['name']);
                $contentBase64 = base64_encode(file_get_contents($file['tmp_name']));
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $result = $client->uploadShellArt($contentBase64, '', $originalName);
                $savedName = $result['name'] ?? $originalName;
                echo json_encode([
                    'success' => true,
                    'name' => $savedName,
                    'message_code' => 'ui.admin.appearance.shell.uploaded_with_name',
                    'message_params' => [
                        'name' => $savedName
                    ]
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.shell_art.upload.failed', apiLocalizedText('errors.admin.shell_art.upload.failed', 'Failed to upload shell art'));
            }
        });

        SimpleRouter::delete('/shell-art/{name}', function(string $name) {
            RouteHelper::requireAdmin();
            header('Content-Type: application/json');
            try {
                $name = basename($name);
                if (!preg_match('/^[a-zA-Z0-9_\-]+\.(ans|asc|txt)$/i', $name)) {
                    http_response_code(400);
                    apiError('errors.admin.shell_art.delete.invalid_name', apiLocalizedText('errors.admin.shell_art.delete.invalid_name', 'Invalid shell art filename'));
                    return;
                }
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $client->deleteShellArt($name);
                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.admin.appearance.shell.deleted_with_name',
                    'message_params' => [
                        'name' => $name
                    ]
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.shell_art.delete.failed', apiLocalizedText('errors.admin.shell_art.delete.failed', 'Failed to delete shell art'));
            }
        });

        SimpleRouter::get('/appearance/terminal-screens', function() {
            RouteHelper::requireAdmin();
            header('Content-Type: application/json');
            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $screens = $client->listTerminalScreens();
                echo json_encode(['success' => true, 'screens' => $screens]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.appearance.term_server.list_failed', apiLocalizedText('errors.admin.appearance.term_server.list_failed', 'Failed to load terminal screens'));
            }
        });

        SimpleRouter::get('/appearance/terminal-screens/{key}', function(string $key) {
            RouteHelper::requireAdmin();
            header('Content-Type: application/json');
            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $screen = $client->getTerminalScreen($key);
                echo json_encode(['success' => true, 'screen' => $screen]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.appearance.term_server.load_failed', apiLocalizedText('errors.admin.appearance.term_server.load_failed', 'Failed to load terminal screen'));
            }
        });

        SimpleRouter::post('/appearance/terminal-screens/{key}', function(string $key) {
            RouteHelper::requireAdmin();
            header('Content-Type: application/json');
            try {
                $payload = json_decode(file_get_contents('php://input'), true) ?? [];
                $content = (string)($payload['content'] ?? '');
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $screen = $client->saveTerminalScreen($key, $content);
                echo json_encode([
                    'success' => true,
                    'screen' => $screen,
                    'message_code' => 'ui.common.saved',
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.appearance.term_server.save_failed', apiLocalizedText('errors.admin.appearance.term_server.save_failed', 'Failed to save terminal screen'));
            }
        });

        SimpleRouter::post('/appearance/terminal-screens/{key}/upload', function(string $key) {
            RouteHelper::requireAdmin();
            header('Content-Type: application/json');
            try {
                if (empty($_FILES['file'])) {
                    http_response_code(400);
                    apiError('errors.admin.appearance.term_server.upload.no_file', apiLocalizedText('errors.admin.appearance.term_server.upload.no_file', 'No terminal screen file uploaded'));
                    return;
                }
                $file = $_FILES['file'];
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    http_response_code(400);
                    apiError('errors.admin.appearance.term_server.upload.failed', apiLocalizedText('errors.admin.appearance.term_server.upload.failed', 'Terminal screen upload failed'));
                    return;
                }
                if ($file['size'] > 1048576) {
                    http_response_code(400);
                    apiError('errors.admin.appearance.term_server.upload.file_too_large', apiLocalizedText('errors.admin.appearance.term_server.upload.file_too_large', 'Terminal screen file exceeds size limit'));
                    return;
                }

                $contentBase64 = base64_encode(file_get_contents($file['tmp_name']));
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $screen = $client->uploadTerminalScreen($key, $contentBase64, basename((string)$file['name']));
                echo json_encode([
                    'success' => true,
                    'screen' => $screen,
                    'message_code' => 'ui.common.saved',
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.appearance.term_server.upload.failed', apiLocalizedText('errors.admin.appearance.term_server.upload.failed', 'Failed to upload terminal screen'));
            }
        });

        SimpleRouter::delete('/appearance/terminal-screens/{key}', function(string $key) {
            RouteHelper::requireAdmin();
            header('Content-Type: application/json');
            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $client->deleteTerminalScreen($key);
                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.common.saved',
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.appearance.term_server.delete.failed', apiLocalizedText('errors.admin.appearance.term_server.delete.failed', 'Failed to delete terminal screen'));
            }
        });

        SimpleRouter::get('/taglines', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $result = $client->getTaglines();
                echo json_encode(['success' => true, 'taglines' => $result['text'] ?? '']);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.taglines.load_failed', apiLocalizedText('errors.admin.taglines.load_failed', 'Failed to load taglines'));
            }
        });

        SimpleRouter::post('/taglines', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true);
                $text = (string)($payload['taglines'] ?? '');
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $result = $client->saveTaglines($text);
                echo json_encode([
                    'success' => true,
                    'taglines' => $result['text'] ?? '',
                    'message_code' => 'ui.admin.bbs_settings.taglines_saved_success'
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.taglines.save_failed', apiLocalizedText('errors.admin.taglines.save_failed', 'Failed to save taglines'));
            }
        });

        // MRC settings
        SimpleRouter::get('/mrc-settings', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $config = $client->getMrcConfig();
                echo json_encode(['success' => true, 'config' => $config]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.mrc_settings.load_failed', apiLocalizedText('errors.admin.mrc_settings.load_failed', 'Failed to load MRC settings'));
            }
        });

        SimpleRouter::post('/mrc-settings', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true);
                $config = $payload['config'] ?? [];

                if (!is_array($config)) {
                    throw new Exception('Invalid configuration payload');
                }

                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $savedConfig = $client->setMrcConfig($config);

                $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
                if ($userId) {
                    AdminActionLogger::logAction($userId, 'mrc_settings_updated', [
                        'enabled' => $config['enabled'] ?? null
                    ]);
                }

                echo json_encode([
                    'success' => true,
                    'config' => $savedConfig,
                    'message_code' => 'ui.admin.mrc_settings.saved_success'
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.mrc_settings.save_failed', apiLocalizedText('errors.admin.mrc_settings.save_failed', 'Failed to save MRC settings'));
            }
        });

        SimpleRouter::post('/mrc-restart', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $client->restartMrcDaemon();

                $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
                if ($userId) {
                    AdminActionLogger::logAction($userId, 'mrc_daemon_restarted');
                }

                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.api.admin.mrc_restart_initiated',
                    'message' => 'MRC daemon restart initiated'
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.mrc_settings.restart_failed', apiLocalizedText('errors.admin.mrc_settings.restart_failed', 'Failed to restart MRC daemon'));
            }
        });

        SimpleRouter::get('/bbs-system', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $config = $client->getSystemConfig();
                echo json_encode(['success' => true, 'config' => $config]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.bbs_system.load_failed', apiLocalizedText('errors.admin.bbs_system.load_failed', 'Failed to load system settings'));
            }
        });

        SimpleRouter::post('/bbs-system', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true);
                $config = $payload['config'] ?? [];
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $updated = $client->setSystemConfig($config);
                echo json_encode([
                    'success' => true,
                    'config' => $updated,
                    'message_code' => 'ui.admin.bbs_settings.system_saved_success'
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.bbs_system.save_failed', apiLocalizedText('errors.admin.bbs_system.save_failed', 'Failed to save system settings'));
            }
        });

        SimpleRouter::get('/binkp-config', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $config = $client->getFullBinkpConfig();
                echo json_encode(['success' => true, 'config' => $config]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.binkp_config.load_failed', apiLocalizedText('errors.admin.binkp_config.load_failed', 'Failed to load BinkP configuration'));
            }
        });

        SimpleRouter::post('/binkp-config', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true);
                $config = $payload['config'] ?? [];
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $updated = $client->setFullBinkpConfig($config);
                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.admin.binkp_config.configuration_saved',
                    'config' => $updated
                ]);
            } catch (Exception $e) {
                http_response_code(400);
                apiError('errors.admin.binkp_config.save_failed', apiLocalizedText('errors.admin.binkp_config.save_failed', 'Failed to save BinkP configuration'));
            }
        });

        SimpleRouter::post('/binkp-reload', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $result = $client->reloadBinkpConfig();
                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.api.admin.binkp_config_reloaded',
                    'message' => 'BinkP configuration reload requested',
                    'result' => $result
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.binkp_config.reload_failed', apiLocalizedText('errors.admin.binkp_config.reload_failed', 'Failed to reload BinkP configuration'), 500);
            }
        });

        SimpleRouter::get('/webdoors-config', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $config = $client->getWebdoorsConfig();
                echo json_encode(['success' => true, 'config' => $config]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.webdoors_config.load_failed', apiLocalizedText('errors.admin.webdoors_config.load_failed', 'Failed to load webdoors configuration'));
            }
        });

        SimpleRouter::get('/webdoors-available', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            $doors = [];
            foreach (WebDoorManifest::listManifests() as $entry) {
                $manifest = $entry['manifest'];
                $game = $manifest['game'] ?? [];
                $gameId = $entry['id'];
                $doors[] = [
                    'id' => $gameId,
                    'name' => $game['name'] ?? $gameId,
                    'path' => $entry['path'],
                    'config' => is_array($manifest['config'] ?? null) ? $manifest['config'] : null
                ];
            }

            echo json_encode(['doors' => $doors]);
        });

        SimpleRouter::post('/webdoors-config', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true);
                $json = $payload['json'] ?? '';
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $updated = $client->saveWebdoorsConfig((string)$json);
                echo json_encode([
                    'success' => true,
                    'config' => $updated,
                    'message_code' => 'ui.admin.webdoors_config.saved_success'
                ]);
            } catch (Exception $e) {
                http_response_code(400);
                apiError('errors.admin.webdoors_config.save_failed', apiLocalizedText('errors.admin.webdoors_config.save_failed', 'Failed to save webdoors configuration'));
            }
        });

        SimpleRouter::post('/webdoors-activate', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $updated = $client->activateWebdoorsConfig();
                echo json_encode([
                    'success' => true,
                    'config' => $updated,
                    'message_code' => 'ui.admin.webdoors_config.activated_success'
                ]);
            } catch (Exception $e) {
                http_response_code(400);
                apiError('errors.admin.webdoors_config.activate_failed', apiLocalizedText('errors.admin.webdoors_config.activate_failed', 'Failed to activate webdoors configuration'));
            }
        });

        // DOSDoors API endpoints
        SimpleRouter::get('/dosdoors-config', function() {
            $user = RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $result = $client->getDosdoorsConfig();

                $configData = null;
                if (!empty($result['config_json'])) {
                    $configData = json_decode($result['config_json'], true);
                }

                echo json_encode([
                    'success' => true,
                    'config' => $configData ?? [],
                    'exists' => $result['active'] ?? false
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.dosdoors_config.load_failed', apiLocalizedText('errors.admin.dosdoors_config.load_failed', 'Failed to load DOS doors configuration'), 500);
            }
        });

        SimpleRouter::get('/dosdoors-available', function() {
            $user = RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            $doorManager = new DoorManager();
            $allDoors = $doorManager->getAllDoors();

            $doors = [];
            foreach ($allDoors as $doorId => $door) {
                $doors[] = [
                    'id' => $doorId,
                    'name' => $door['name'],
                    'short_name' => $door['short_name'] ?? $door['name'],
                    'author' => $door['author'] ?? 'Unknown',
                    'description' => $door['description'] ?? '',
                    'config' => $door['config'] ?? []
                ];
            }

            echo json_encode(['success' => true, 'doors' => $doors]);
        });

        SimpleRouter::post('/dosdoors-config', function() {
            $user = RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true);
                $config = $payload['config'] ?? null;

                if (!is_array($config)) {
                    throw new Exception('Invalid config data');
                }

                $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if ($json === false) {
                    throw new Exception('Failed to encode config as JSON');
                }

                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $result = $client->saveDosdoorsConfig($json);

                // Reload config class cache
                DoorConfig::reload();

                // Sync enabled doors to database
                $doorManager = new DoorManager();
                $syncResult = $doorManager->syncDoorsToDatabase();

                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.admin.dosdoors_config.saved_success',
                    'config' => $config,
                    'synced' => $syncResult['synced'],
                    'sync_errors' => $syncResult['errors']
                ]);
            } catch (Exception $e) {
                http_response_code(400);
                apiError('errors.admin.dosdoors_config.save_failed', apiLocalizedText('errors.admin.dosdoors_config.save_failed', 'Failed to save DOS doors configuration'), 400);
            }
        });

        // Native Doors API endpoints
        SimpleRouter::get('/native-doors', function() {
            $user = RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            $nativeDoorManager = new \BinktermPHP\NativeDoorManager();
            $allDoors = $nativeDoorManager->getAllDoors();

            $doors = [];
            foreach ($allDoors as $doorId => $door) {
                $doors[] = [
                    'id' => $doorId,
                    'name' => $door['name'],
                    'short_name' => $door['short_name'] ?? $door['name'],
                    'author' => $door['author'] ?? 'Unknown',
                    'description' => $door['description'] ?? '',
                    'platform' => $door['platform'] ?? [],
                    'config' => $door['config'] ?? []
                ];
            }

            echo json_encode([
                'success' => true,
                'doors' => $doors,
                'server_platform' => strtolower(PHP_OS_FAMILY),
            ]);
        });

        SimpleRouter::get('/native-doors/config', function() {
            $user = RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $result = $client->getNativeDoorsConfig();

                $configData = null;
                if (!empty($result['config_json'])) {
                    $configData = json_decode($result['config_json'], true);
                }

                echo json_encode([
                    'success' => true,
                    'config' => $configData ?? [],
                    'exists' => $result['active'] ?? false
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.native_doors.load_failed', apiLocalizedText('errors.admin.native_doors.load_failed', 'Failed to load native doors configuration'), 500);
            }
        });

        SimpleRouter::post('/native-doors/config', function() {
            $user = RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true);
                $config = $payload['config'] ?? null;

                if (!is_array($config)) {
                    throw new Exception('Invalid config data');
                }

                $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if ($json === false) {
                    throw new Exception('Failed to encode config as JSON');
                }

                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $client->saveNativeDoorsConfig($json);

                // Reload config class cache and sync enabled doors to database
                \BinktermPHP\NativeDoorConfig::reload();

                $nativeDoorManager = new \BinktermPHP\NativeDoorManager();
                $syncResult = $nativeDoorManager->syncDoorsToDatabase();

                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.admin.nativedoors_config.saved_success',
                    'config' => $config,
                    'synced' => $syncResult['synced'],
                    'sync_errors' => $syncResult['errors']
                ]);
            } catch (Exception $e) {
                http_response_code(400);
                apiError('errors.admin.native_doors.save_failed', apiLocalizedText('errors.admin.native_doors.save_failed', 'Failed to save native doors configuration'), 400);
            }
        });

        SimpleRouter::post('/native-doors/sync', function() {
            $user = RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $nativeDoorManager = new \BinktermPHP\NativeDoorManager();
                $syncResult = $nativeDoorManager->syncDoorsToDatabase();

                echo json_encode([
                    'success' => true,
                    'synced' => $syncResult['synced'],
                    'errors' => $syncResult['errors']
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.native_doors.sync_failed', apiLocalizedText('errors.admin.native_doors.sync_failed', 'Failed to sync native doors'), 500);
            }
        });

        SimpleRouter::get('/filearea-rules', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $config = $client->getFileAreaRulesConfig();
                echo json_encode(['success' => true, 'config' => $config]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.filearea_rules.load_failed', apiLocalizedText('errors.admin.filearea_rules.load_failed', 'Failed to load file area rules'));
            }
        });

        SimpleRouter::post('/filearea-rules', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true);
                $json = $payload['json'] ?? '';
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $updated = $client->saveFileAreaRulesConfig((string)$json);
                echo json_encode([
                    'success' => true,
                    'config' => $updated,
                    'message_code' => 'ui.admin.filearea_rules.saved_success'
                ]);
            } catch (Exception $e) {
                http_response_code(400);
                apiError('errors.admin.filearea_rules.save_failed', apiLocalizedText('errors.admin.filearea_rules.save_failed', 'Failed to save file area rules'));
            }
        });

        // File area rules: filenames for pattern tester
        SimpleRouter::get('/filearea-rules/filenames', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            $tag    = strtoupper(trim((string)($_GET['tag'] ?? '')));
            $domain = trim((string)($_GET['domain'] ?? ''));

            if ($tag === '') {
                echo json_encode(['success' => true, 'filenames' => []]);
                return;
            }

            try {
                $manager = new \BinktermPHP\FileAreaManager();
                $area = $manager->getFileAreaByTag($tag, $domain);
                if (!$area) {
                    echo json_encode(['success' => true, 'filenames' => [], 'area_found' => false]);
                    return;
                }
                $files = $manager->getFiles((int)$area['id'], null, true);
                $filenames = array_values(array_map(fn($f) => $f['filename'], $files));
                echo json_encode(['success' => true, 'filenames' => $filenames, 'area_found' => true]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.filearea_rules.load_failed', 'Failed to load filenames');
            }
        });

        SimpleRouter::get('/files/pending', function() {
            $user = RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $manager = new \BinktermPHP\FileAreaManager();
                echo json_encode([
                    'success' => true,
                    'files' => $manager->listPendingUploads(),
                    'count' => $manager->countPendingUploads(),
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.file_approvals.load_failed', apiLocalizedText('errors.admin.file_approvals.load_failed', 'Failed to load pending file approvals'));
            }
        });

        SimpleRouter::post('/files/{id}/approve', function($id) {
            $user = RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $manager = new \BinktermPHP\FileAreaManager();
                $manager->approveFileUpload((int)$id, (int)($user['user_id'] ?? $user['id'] ?? 0));
                AdminActionLogger::logAction(
                    (int)($user['user_id'] ?? $user['id'] ?? 0),
                    'file_upload_approved',
                    ['file_id' => (int)$id]
                );

                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.admin.file_approvals.approved'
                ]);
            } catch (Exception $e) {
                $message = $e->getMessage();
                if ($message === 'File not found') {
                    http_response_code(404);
                    apiError('errors.admin.file_approvals.not_found', apiLocalizedText('errors.admin.file_approvals.not_found', 'Pending file not found'), 404);
                    return;
                }
                if ($message === 'File is not awaiting approval') {
                    http_response_code(400);
                    apiError('errors.admin.file_approvals.not_pending', apiLocalizedText('errors.admin.file_approvals.not_pending', 'File is not awaiting approval'));
                    return;
                }

                error_log('File approval failed: ' . $message);
                http_response_code(500);
                apiError('errors.admin.file_approvals.approve_failed', apiLocalizedText('errors.admin.file_approvals.approve_failed', 'Failed to approve file upload'));
            }
        })->where(['id' => '[0-9]+']);

        SimpleRouter::post('/files/{id}/reject', function($id) {
            $user = RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true) ?: [];
                $reason = trim((string)($payload['reason'] ?? ''));
                $manager = new \BinktermPHP\FileAreaManager();
                $manager->rejectFileUpload(
                    (int)$id,
                    (int)($user['user_id'] ?? $user['id'] ?? 0),
                    $reason !== '' ? $reason : null
                );

                AdminActionLogger::logAction(
                    (int)($user['user_id'] ?? $user['id'] ?? 0),
                    'file_upload_rejected',
                    ['file_id' => (int)$id, 'reason' => $reason !== '' ? $reason : null]
                );

                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.admin.file_approvals.rejected'
                ]);
            } catch (Exception $e) {
                $message = $e->getMessage();
                if ($message === 'File not found') {
                    http_response_code(404);
                    apiError('errors.admin.file_approvals.not_found', apiLocalizedText('errors.admin.file_approvals.not_found', 'Pending file not found'), 404);
                    return;
                }
                if ($message === 'File is not awaiting approval') {
                    http_response_code(400);
                    apiError('errors.admin.file_approvals.not_pending', apiLocalizedText('errors.admin.file_approvals.not_pending', 'File is not awaiting approval'));
                    return;
                }

                error_log('File rejection failed: ' . $message);
                http_response_code(500);
                apiError('errors.admin.file_approvals.reject_failed', apiLocalizedText('errors.admin.file_approvals.reject_failed', 'Failed to reject file upload'));
            }
        })->where(['id' => '[0-9]+']);

        SimpleRouter::get('/files/{id}/download', function($id) {
            RouteHelper::requireAdmin();

            try {
                $manager = new \BinktermPHP\FileAreaManager();
                $file = $manager->getFileById((int)$id);
                if (!$file || ($file['source_type'] ?? '') !== 'user_upload' || !in_array(($file['status'] ?? ''), ['pending', 'rejected', 'approved'], true)) {
                    http_response_code(404);
                    echo 'File not found';
                    return;
                }

                $storagePath = $manager->resolveFilePath($file);
                if (!file_exists($storagePath)) {
                    http_response_code(404);
                    echo 'File not found on disk';
                    return;
                }

                $filename = basename((string)$file['filename']);
                $encodedFilename = rawurlencode($filename);

                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"; filename*=UTF-8\'\'' . $encodedFilename);
                header('Content-Length: ' . filesize($storagePath));
                header('Cache-Control: no-cache, must-revalidate');
                header('Pragma: public');

                readfile($storagePath);
                exit;
            } catch (Exception $e) {
                http_response_code(500);
                echo 'Failed to download file';
            }
        })->where(['id' => '[0-9]+']);

        // Advertisements
        SimpleRouter::get('/ads', function() {
            $user = RouteHelper::requireAdmin();

            header('Content-Type: application/json');

            try {
                $ads = new \BinktermPHP\Advertising();
                $items = $ads->listAds();
                echo json_encode(['ads' => $items]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.ads.list_failed', apiLocalizedText('errors.admin.ads.list_failed', 'Failed to load advertisements'));
            }
        });

        SimpleRouter::get('/ads/content-commands', function() {
            RouteHelper::requireAdmin();
            header('Content-Type: application/json');
            echo json_encode(['commands' => \BinktermPHP\Advertising::getAvailableContentCommands()]);
        });

        SimpleRouter::get('/ads/{id}', function($id) {
            $user = RouteHelper::requireAdmin();

            header('Content-Type: application/json');

            try {
                $ads = new \BinktermPHP\Advertising();
                $ad = $ads->getAdById((int)$id);
                if (!$ad) {
                    http_response_code(404);
                    apiError('errors.admin.ads.not_found', apiLocalizedText('errors.admin.ads.not_found', 'Advertisement not found'), 404);
                    return;
                }

                echo json_encode(['ad' => $ad]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.ads.load_one_failed', apiLocalizedText('errors.admin.ads.load_one_failed', 'Failed to load advertisement'));
            }
        })->where(['id' => '[0-9]+']);

        SimpleRouter::post('/ads/upload', function() {
            $user = RouteHelper::requireAdmin();
            $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);

            header('Content-Type: application/json');

            $contentCommand = trim((string)($_POST['content_command'] ?? ''));
            $hasFile = isset($_FILES['ad_file']) && ($_FILES['ad_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

            if (!$hasFile && $contentCommand === '') {
                http_response_code(400);
                apiError('errors.admin.ads.upload.no_file', apiLocalizedText('errors.admin.ads.upload.no_file', 'No advertisement file uploaded'));
                return;
            }

            if ($contentCommand !== '' && !\BinktermPHP\Advertising::validateContentCommand($contentCommand)) {
                http_response_code(400);
                apiError('errors.admin.ads.invalid_content_command', apiLocalizedText('errors.admin.ads.invalid_content_command', 'The selected content command is not allowed'));
                return;
            }

            $content = '';
            $legacyFilename = trim((string)($_POST['legacy_filename'] ?? ''));
            $defaultTitle = 'Advertisement';

            if ($hasFile) {
                $file = $_FILES['ad_file'];
                $maxSize = 5 * 1024 * 1024;
                if (!empty($file['size']) && $file['size'] > $maxSize) {
                    http_response_code(400);
                    apiError('errors.admin.ads.upload.file_too_large', apiLocalizedText('errors.admin.ads.upload.file_too_large', 'Advertisement file exceeds size limit'));
                    return;
                }

                $content = @file_get_contents($file['tmp_name']);
                if ($content === false) {
                    http_response_code(400);
                    apiError('errors.admin.ads.upload.read_failed', apiLocalizedText('errors.admin.ads.upload.read_failed', 'Failed to read uploaded advertisement file'));
                    return;
                }

                if ($legacyFilename === '') {
                    $legacyFilename = (string)($file['name'] ?? '');
                }
                $filename = (string)($file['name'] ?? 'Advertisement');
                $defaultTitle = pathinfo($filename, PATHINFO_FILENAME);
            }

            $adFilePrefixes = ['ans' => '[ANSI]', 'rip' => '[RIP]', 'six' => '[SIXEL]', 'sixel' => '[SIXEL]'];
            $getAdFilePrefix = static function(string $fname) use ($adFilePrefixes): string {
                return $adFilePrefixes[strtolower(pathinfo($fname, PATHINFO_EXTENSION))] ?? '';
            };

            if ($hasFile && ($prefix = $getAdFilePrefix($filename)) !== '') {
                $defaultTitle = $prefix . ' ' . $defaultTitle;
            }

            try {
                $ads = new \BinktermPHP\Advertising();
                $duplicates = $content !== '' ? $ads->findDuplicatesByContent($content) : [];
                $resolvedTitle = trim((string)($_POST['title'] ?? $defaultTitle));
                if ($hasFile) {
                    $prefix = $getAdFilePrefix((string)($_FILES['ad_file']['name'] ?? ''));
                    if ($prefix !== '' && !str_starts_with($resolvedTitle, $prefix)) {
                        $resolvedTitle = $prefix . ' ' . $resolvedTitle;
                    }
                }
                $created = $ads->createAd([
                    'title' => $resolvedTitle,
                    'slug' => trim((string)($_POST['slug'] ?? '')),
                    'description' => trim((string)($_POST['description'] ?? '')),
                    'tags' => trim((string)($_POST['tags'] ?? '')),
                    'content' => $content,
                    'content_command' => $contentCommand,
                    'legacy_filename' => $legacyFilename,
                    'source_type' => 'upload',
                    'is_active' => !isset($_POST['is_active']) || $_POST['is_active'] !== '0',
                    'show_on_dashboard' => !empty($_POST['show_on_dashboard']),
                    'allow_auto_post' => !empty($_POST['allow_auto_post']),
                    'dashboard_weight' => max(1, (int)($_POST['dashboard_weight'] ?? 1)),
                    'dashboard_priority' => (int)($_POST['dashboard_priority'] ?? 0),
                    'click_url' => trim((string)($_POST['click_url'] ?? '')),
                ], $userId > 0 ? $userId : null);
                echo json_encode([
                    'success' => true,
                    'ad' => $created,
                    'duplicates' => $duplicates,
                    'message_code' => 'ui.admin.ads.uploaded'
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.ads.upload.failed', apiLocalizedText('errors.admin.ads.upload.failed', 'Failed to upload advertisement'));
            }
        });

        SimpleRouter::post('/ads/{id}', function($id) {
            $user = RouteHelper::requireAdmin();
            $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);

            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true);
                if (!is_array($payload)) {
                    http_response_code(400);
                    apiError('errors.admin.ads.invalid_payload', apiLocalizedText('errors.admin.ads.invalid_payload', 'Invalid advertisement payload'), 400);
                    return;
                }

                $ads = new \BinktermPHP\Advertising();
                $adId = (int)$id;
                $existing = $ads->getAdById($adId);
                if (!$existing) {
                    http_response_code(404);
                    apiError('errors.admin.ads.not_found', apiLocalizedText('errors.admin.ads.not_found', 'Advertisement not found'), 404);
                    return;
                }

                if (array_key_exists('content_command', $payload)) {
                    $cmd = trim((string)($payload['content_command'] ?? ''));
                    if ($cmd !== '' && !\BinktermPHP\Advertising::validateContentCommand($cmd)) {
                        http_response_code(400);
                        apiError('errors.admin.ads.invalid_content_command', apiLocalizedText('errors.admin.ads.invalid_content_command', 'The selected content command is not allowed'), 400);
                        return;
                    }
                }

                $duplicates = [];
                if (array_key_exists('content', $payload)) {
                    $duplicates = $ads->findDuplicatesByContent((string)$payload['content'], $adId);
                }

                $updated = $ads->updateAd($adId, $payload, $userId > 0 ? $userId : null);
                echo json_encode([
                    'success' => true,
                    'ad' => $updated,
                    'duplicates' => $duplicates,
                    'message_code' => 'ui.admin.ads.saved'
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.ads.save_failed', apiLocalizedText('errors.admin.ads.save_failed', 'Failed to save advertisement'));
            }
        })->where(['id' => '[0-9]+']);

        SimpleRouter::delete('/ads/{id}', function($id) {
            $user = RouteHelper::requireAdmin();

            header('Content-Type: application/json');

            try {
                $ads = new \BinktermPHP\Advertising();
                $ad = $ads->getAdById((int)$id);
                if (!$ad) {
                    http_response_code(404);
                    apiError('errors.admin.ads.not_found', apiLocalizedText('errors.admin.ads.not_found', 'Advertisement not found'), 404);
                    return;
                }

                $ads->deleteAd((int)$id);
                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.admin.ads.deleted'
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.ads.delete_failed', apiLocalizedText('errors.admin.ads.delete_failed', 'Failed to delete advertisement'));
            }
        })->where(['id' => '[0-9]+']);

        SimpleRouter::get('/ad-campaigns', function() {
            $user = RouteHelper::requireAdmin();

            header('Content-Type: application/json');

            try {
                $ads = new \BinktermPHP\Advertising();
                echo json_encode(['campaigns' => $ads->listCampaigns()]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.ad_campaigns.list_failed', apiLocalizedText('errors.admin.ad_campaigns.list_failed', 'Failed to load ad campaigns'));
            }
        });

        SimpleRouter::get('/ad-campaigns/log', function() {
            $user = RouteHelper::requireAdmin();

            header('Content-Type: application/json');

            try {
                $campaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : null;
                $limit = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 50;
                $status = isset($_GET['status']) ? trim((string)$_GET['status']) : null;
                $ads = new \BinktermPHP\Advertising();
                echo json_encode(['log' => $ads->listPostLog($campaignId ?: null, $limit, $status ?: null)]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.ad_campaigns.log_failed', apiLocalizedText('errors.admin.ad_campaigns.log_failed', 'Failed to load ad campaign log'));
            }
        });

        SimpleRouter::get('/ad-campaigns/meta', function() {
            $user = RouteHelper::requireAdmin();
            $db = \BinktermPHP\Database::getInstance()->getPdo();

            header('Content-Type: application/json');

            try {
                $ads = new \BinktermPHP\Advertising();
                $users = $db->query("SELECT id, username, real_name, is_system FROM users ORDER BY is_system DESC, LOWER(username)")->fetchAll(PDO::FETCH_ASSOC);
                $echoareas = $db->query("SELECT id, tag, domain, is_local FROM echoareas WHERE is_active = TRUE ORDER BY LOWER(tag), LOWER(domain)")->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode([
                    'ads' => $ads->listAds(false),
                    'tags' => $ads->listTags(),
                    'users' => $users,
                    'echoareas' => $echoareas,
                    'timezones' => \DateTimeZone::listIdentifiers()
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.ad_campaigns.meta_failed', apiLocalizedText('errors.admin.ad_campaigns.meta_failed', 'Failed to load ad campaign metadata'));
            }
        });

        SimpleRouter::get('/ad-campaigns/{id}', function($id) {
            $user = RouteHelper::requireAdmin();

            header('Content-Type: application/json');

            try {
                $ads = new \BinktermPHP\Advertising();
                $campaign = $ads->getCampaignById((int)$id);
                if (!$campaign) {
                    http_response_code(404);
                    apiError('errors.admin.ad_campaigns.not_found', apiLocalizedText('errors.admin.ad_campaigns.not_found', 'Ad campaign not found'), 404);
                    return;
                }

                echo json_encode(['campaign' => $campaign]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.ad_campaigns.load_one_failed', apiLocalizedText('errors.admin.ad_campaigns.load_one_failed', 'Failed to load ad campaign'));
            }
        })->where(['id' => '[0-9]+']);

        SimpleRouter::post('/ad-campaigns', function() {
            $user = RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true);
                if (!is_array($payload)) {
                    http_response_code(400);
                    apiError('errors.admin.ad_campaigns.invalid_payload', apiLocalizedText('errors.admin.ad_campaigns.invalid_payload', 'Invalid ad campaign payload'), 400);
                    return;
                }

                $ads = new \BinktermPHP\Advertising();
                $campaign = $ads->createCampaign($payload);
                echo json_encode([
                    'success' => true,
                    'campaign' => $campaign,
                    'message_code' => 'ui.admin.ad_campaigns.created'
                ]);
            } catch (\InvalidArgumentException $e) {
                http_response_code(400);
                apiError('errors.admin.ad_campaigns.invalid_payload', $e->getMessage(), 400);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.ad_campaigns.create_failed', apiLocalizedText('errors.admin.ad_campaigns.create_failed', 'Failed to create ad campaign'));
            }
        });

        SimpleRouter::post('/ad-campaigns/{id}', function($id) {
            $user = RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $payload = json_decode(file_get_contents('php://input'), true);
                if (!is_array($payload)) {
                    http_response_code(400);
                    apiError('errors.admin.ad_campaigns.invalid_payload', apiLocalizedText('errors.admin.ad_campaigns.invalid_payload', 'Invalid ad campaign payload'), 400);
                    return;
                }

                $ads = new \BinktermPHP\Advertising();
                $campaign = $ads->updateCampaign((int)$id, $payload);
                echo json_encode([
                    'success' => true,
                    'campaign' => $campaign,
                    'message_code' => 'ui.admin.ad_campaigns.saved'
                ]);
            } catch (\RuntimeException $e) {
                http_response_code(404);
                apiError('errors.admin.ad_campaigns.not_found', $e->getMessage(), 404);
            } catch (\InvalidArgumentException $e) {
                http_response_code(400);
                apiError('errors.admin.ad_campaigns.invalid_payload', $e->getMessage(), 400);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.ad_campaigns.save_failed', apiLocalizedText('errors.admin.ad_campaigns.save_failed', 'Failed to save ad campaign'));
            }
        })->where(['id' => '[0-9]+']);

        SimpleRouter::delete('/ad-campaigns/{id}', function($id) {
            $user = RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $ads = new \BinktermPHP\Advertising();
                $campaign = $ads->getCampaignById((int)$id);
                if (!$campaign) {
                    http_response_code(404);
                    apiError('errors.admin.ad_campaigns.not_found', apiLocalizedText('errors.admin.ad_campaigns.not_found', 'Ad campaign not found'), 404);
                    return;
                }

                $ads->deleteCampaign((int)$id);
                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.admin.ad_campaigns.deleted'
                ]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.ad_campaigns.delete_failed', apiLocalizedText('errors.admin.ad_campaigns.delete_failed', 'Failed to delete ad campaign'));
            }
        })->where(['id' => '[0-9]+']);

        SimpleRouter::post('/ad-campaigns/run/{id}', function($id) {
            $user = RouteHelper::requireAdmin();
            header('Content-Type: application/json');

            try {
                $ads = new \BinktermPHP\Advertising();
                $campaign = $ads->getCampaignById((int)$id);
                if (!$campaign) {
                    http_response_code(404);
                    apiError('errors.admin.ad_campaigns.not_found', apiLocalizedText('errors.admin.ad_campaigns.not_found', 'Ad campaign not found'), 404);
                    return;
                }

                $results = $ads->processDueCampaigns((int)$id, false, true);
                echo json_encode([
                    'success' => true,
                    'results' => $results,
                    'message_code' => 'ui.admin.ad_campaigns.run_complete'
                ]);
            } catch (\Throwable $e) {
                http_response_code(500);
                apiError('errors.admin.ad_campaigns.run_failed', apiLocalizedText('errors.admin.ad_campaigns.run_failed', 'Failed to run ad campaign'));
            }
        })->where(['id' => '[0-9]+']);


        SimpleRouter::post('/chat-rooms', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $input = json_decode(file_get_contents('php://input'), true);
                $name = trim($input['name'] ?? '');
                $description = trim($input['description'] ?? '');
                $isActive = !empty($input['is_active']);

                if ($name === '' || strlen($name) > 64) {
                    throw new Exception('Room name must be 1-64 characters');
                }

                $db = \BinktermPHP\Database::getInstance()->getPdo();
                $stmt = $db->prepare("
                    INSERT INTO chat_rooms (name, description, is_active)
                    VALUES (?, ?, ?)
                    RETURNING id
                ");
                $stmt->execute([$name, $description ?: null, $isActive ? 1 : 0]);
                $roomId = $stmt->fetchColumn();

                echo json_encode([
                    'success' => true,
                    'id' => (int)$roomId,
                    'message_code' => 'ui.admin.chat_rooms.created_success'
                ]);
            } catch (Exception $e) {
                http_response_code(400);
                $message = $e->getMessage();
                if ($message === 'Room name must be 1-64 characters') {
                    apiError('errors.admin.chat_rooms.invalid_name_length', apiLocalizedText('errors.admin.chat_rooms.invalid_name_length', 'Room name must be 1-64 characters'));
                } else {
                    apiError('errors.admin.chat_rooms.create_failed', apiLocalizedText('errors.admin.chat_rooms.create_failed', 'Failed to create chat room'));
                }
            }
        });

        SimpleRouter::put('/chat-rooms/{id}', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $input = json_decode(file_get_contents('php://input'), true);
                $name = trim($input['name'] ?? '');
                $description = trim($input['description'] ?? '');
                $isActive = !empty($input['is_active']);

                $db = \BinktermPHP\Database::getInstance()->getPdo();
                $existingStmt = $db->prepare("SELECT name FROM chat_rooms WHERE id = ?");
                $existingStmt->execute([$id]);
                $existingName = $existingStmt->fetchColumn();

                if (!$existingName) {
                    throw new Exception('Chat room not found');
                }

                if ($existingName === 'Lobby' && $name !== '' && $name !== 'Lobby') {
                    throw new Exception('Lobby name cannot be changed');
                }

                $finalName = $name !== '' ? $name : $existingName;
                if (strlen($finalName) > 64) {
                    throw new Exception('Room name must be 1-64 characters');
                }

                $stmt = $db->prepare("
                    UPDATE chat_rooms
                    SET name = ?, description = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([$finalName, $description ?: null, $isActive ? 1 : 0, $id]);

                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.admin.chat_rooms.updated_success'
                ]);
            } catch (Exception $e) {
                http_response_code(400);
                $message = $e->getMessage();
                if ($message === 'Chat room not found') {
                    apiError('errors.admin.chat_rooms.not_found', apiLocalizedText('errors.admin.chat_rooms.not_found', 'Chat room not found'));
                } elseif ($message === 'Lobby name cannot be changed') {
                    apiError('errors.admin.chat_rooms.lobby_name_locked', apiLocalizedText('errors.admin.chat_rooms.lobby_name_locked', 'Lobby name cannot be changed'));
                } elseif ($message === 'Room name must be 1-64 characters') {
                    apiError('errors.admin.chat_rooms.invalid_name_length', apiLocalizedText('errors.admin.chat_rooms.invalid_name_length', 'Room name must be 1-64 characters'));
                } else {
                    apiError('errors.admin.chat_rooms.update_failed', apiLocalizedText('errors.admin.chat_rooms.update_failed', 'Failed to update chat room'));
                }
            }
        });

        SimpleRouter::delete('/chat-rooms/{id}', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $db = \BinktermPHP\Database::getInstance()->getPdo();
                $existingStmt = $db->prepare("SELECT name FROM chat_rooms WHERE id = ?");
                $existingStmt->execute([$id]);
                $existingName = $existingStmt->fetchColumn();

                if (!$existingName) {
                    throw new Exception('Chat room not found');
                }

                if ($existingName === 'Lobby') {
                    throw new Exception('Lobby cannot be deleted');
                }

                $stmt = $db->prepare("DELETE FROM chat_rooms WHERE id = ?");
                $stmt->execute([$id]);

                echo json_encode([
                    'success' => true,
                    'message_code' => 'ui.admin.chat_rooms.deleted_success'
                ]);
            } catch (Exception $e) {
                http_response_code(400);
                $message = $e->getMessage();
                if ($message === 'Chat room not found') {
                    apiError('errors.admin.chat_rooms.not_found', apiLocalizedText('errors.admin.chat_rooms.not_found', 'Chat room not found'));
                } elseif ($message === 'Lobby cannot be deleted') {
                    apiError('errors.admin.chat_rooms.lobby_delete_forbidden', apiLocalizedText('errors.admin.chat_rooms.lobby_delete_forbidden', 'Lobby cannot be deleted'));
                } else {
                    apiError('errors.admin.chat_rooms.delete_failed', apiLocalizedText('errors.admin.chat_rooms.delete_failed', 'Failed to delete chat room'));
                }
            }
        });

        // ========================================
        // Insecure Nodes Management
        // ========================================

        // List insecure nodes
        SimpleRouter::get('/insecure-nodes', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');
            $nodes = $adminController->getInsecureNodes();
            echo json_encode(['nodes' => $nodes]);
        });

        // Add insecure node
        SimpleRouter::post('/insecure-nodes', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $input = json_decode(file_get_contents('php://input'), true);
                $nodeId = $adminController->addInsecureNode($input);
                echo json_encode([
                    'success' => true,
                    'id' => $nodeId,
                    'message_code' => 'ui.admin.insecure_nodes.added_success'
                ]);
            } catch (Exception $e) {
                http_response_code(400);
                apiError('errors.admin.insecure_nodes.create_failed', apiLocalizedText('errors.admin.insecure_nodes.create_failed', 'Failed to add insecure node'));
            }
        });

        // Update insecure node
        SimpleRouter::put('/insecure-nodes/{id}', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $input = json_decode(file_get_contents('php://input'), true);
                $result = $adminController->updateInsecureNode($id, $input);
                if ($result) {
                    echo json_encode([
                        'success' => true,
                        'message_code' => 'ui.admin.insecure_nodes.updated_success'
                    ]);
                } else {
                    echo json_encode(['success' => false]);
                }
            } catch (Exception $e) {
                http_response_code(400);
                apiError('errors.admin.insecure_nodes.update_failed', apiLocalizedText('errors.admin.insecure_nodes.update_failed', 'Failed to update insecure node'));
            }
        });

        // Delete insecure node
        SimpleRouter::delete('/insecure-nodes/{id}', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $result = $adminController->deleteInsecureNode($id);
                if ($result) {
                    echo json_encode([
                        'success' => true,
                        'message_code' => 'ui.admin.insecure_nodes.deleted_success'
                    ]);
                } else {
                    echo json_encode(['success' => false]);
                }
            } catch (Exception $e) {
                http_response_code(400);
                apiError('errors.admin.insecure_nodes.delete_failed', apiLocalizedText('errors.admin.insecure_nodes.delete_failed', 'Failed to delete insecure node'));
            }
        });

        // ========================================
        // Crashmail Queue Management
        // ========================================

        // Get crashmail queue stats
        SimpleRouter::get('/crashmail/stats', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');
            $stats = $adminController->getCrashmailStats();
            echo json_encode($stats);
        });

        // Get crashmail queue items
        SimpleRouter::get('/crashmail/queue', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');
            $status = $_GET['status'] ?? null;
            $limit = intval($_GET['limit'] ?? 50);
            $items = $adminController->getCrashmailQueue($status, $limit);
            echo json_encode(['items' => $items]);
        });

        // Retry failed crashmail
        SimpleRouter::post('/crashmail/{id}/retry', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $result = $adminController->retryCrashmail($id);
                if ($result) {
                    echo json_encode([
                        'success' => true,
                        'message_code' => 'ui.admin.crashmail_queue.retry_success'
                    ]);
                } else {
                    echo json_encode(['success' => false]);
                }
            } catch (Exception $e) {
                http_response_code(400);
                apiError('errors.admin.crashmail.retry_failed', apiLocalizedText('errors.admin.crashmail.retry_failed', 'Failed to retry crashmail item'));
            }
        });

        // Cancel queued crashmail
        SimpleRouter::delete('/crashmail/{id}', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $result = $adminController->cancelCrashmail($id);
                if ($result) {
                    echo json_encode([
                        'success' => true,
                        'message_code' => 'ui.admin.crashmail_queue.cancel_success'
                    ]);
                } else {
                    echo json_encode(['success' => false]);
                }
            } catch (Exception $e) {
                http_response_code(400);
                apiError('errors.admin.crashmail.cancel_failed', apiLocalizedText('errors.admin.crashmail.cancel_failed', 'Failed to cancel crashmail item'));
            }
        });

        // Attempt crashmail delivery (runs crashmail_poll)
        SimpleRouter::post('/crashmail/poll', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $result = $client->crashmailPoll();
                echo json_encode([
                    'success' => true,
                    'result' => $result,
                    'message_code' => 'ui.admin.crashmail_queue.delivery_attempt_started'
                ]);
            } catch (Exception $e) {
                http_response_code(400);
                apiError('errors.admin.crashmail.poll_failed', apiLocalizedText('errors.admin.crashmail.poll_failed', 'Failed to run crashmail poll'), 400);
            }
        });

        // ========================================
        // Binkp Session Log
        // ========================================

        // Get session log
        SimpleRouter::get('/binkp-sessions', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');
            $filters = [
                'session_type' => $_GET['session_type'] ?? null,
                'status' => $_GET['status'] ?? null,
                'remote_address' => $_GET['remote_address'] ?? null,
                'is_inbound' => $_GET['is_inbound'] ?? null,
                'process_id' => $_GET['process_id'] ?? null,
            ];
            $limit = intval($_GET['limit'] ?? 50);
            $sessions = $adminController->getBinkpSessions($filters, $limit);
            echo json_encode(['sessions' => $sessions]);
        });

        // Get session stats
        SimpleRouter::get('/binkp-sessions/stats', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');
            $period = $_GET['period'] ?? 'day';
            $stats = $adminController->getBinkpSessionStats($period);
            echo json_encode($stats);
        });

        SimpleRouter::get('/binkp-sessions/{id}/logs', function($id) {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            $session = $adminController->getBinkpSession((int)$id);
            if (!$session) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Session not found']);
                return;
            }

            $processId = (int)($session['process_id'] ?? 0);
            if ($processId <= 0) {
                echo json_encode([
                    'success' => true,
                    'session' => $session,
                    'logs' => ['lines' => [], 'line_count' => 0],
                ]);
                return;
            }

            $controller = new \BinktermPHP\Binkp\Web\BinkpController();
            $logs = $controller->getLogsForPid($processId, !empty($session['log_file']) ? (string)$session['log_file'] : null);
            echo json_encode([
                'success' => true,
                'session' => $session,
                'logs' => $logs,
            ], JSON_INVALID_UTF8_SUBSTITUTE);
        });

        // ========================================
        // Custom Template Editor
        // ========================================

        SimpleRouter::get('/custom-templates', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');
            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $templates = $client->listCustomTemplates();
                echo json_encode(['templates' => $templates]);
            } catch (Exception $e) {
                http_response_code(500);
                apiError('errors.admin.custom_templates.list_failed', apiLocalizedText('errors.admin.custom_templates.list_failed', 'Failed to list custom templates'));
            }
        });

        SimpleRouter::get('/custom-templates/file', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            $path = $_GET['path'] ?? '';
            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $template = $client->getCustomTemplate($path);
                echo json_encode($template);
            } catch (Exception $e) {
                http_response_code(400);
                apiError('errors.admin.custom_templates.get_failed', apiLocalizedText('errors.admin.custom_templates.get_failed', 'Failed to load custom template'));
            }
        });

        SimpleRouter::post('/custom-templates/file', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            $input = json_decode(file_get_contents('php://input'), true);
            $path = trim($input['path'] ?? '');
            $content = (string)($input['content'] ?? '');
            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $result = $client->saveCustomTemplate($path, $content);
                if (is_array($result) && !isset($result['message_code']) && !isset($result['error']) && (($result['success'] ?? true) === true)) {
                    $result['message_code'] = 'ui.admin.template_editor.template_saved_success';
                }
                if (is_array($result)) {
                    $result = apiLocalizeErrorPayload($result, $user);
                }
                echo json_encode($result);
            } catch (Exception $e) {
                http_response_code(400);
                apiError('errors.admin.custom_templates.save_failed', apiLocalizedText('errors.admin.custom_templates.save_failed', 'Failed to save custom template'));
            }
        });

        SimpleRouter::delete('/custom-templates/file', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            $path = $_GET['path'] ?? '';
            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $result = $client->deleteCustomTemplate($path);
                if (is_array($result) && !isset($result['message_code']) && !isset($result['error']) && (($result['success'] ?? true) === true)) {
                    $result['message_code'] = 'ui.admin.template_editor.template_deleted_success';
                }
                if (is_array($result)) {
                    $result = apiLocalizeErrorPayload($result, $user);
                }
                echo json_encode($result);
            } catch (Exception $e) {
                http_response_code(400);
                apiError('errors.admin.custom_templates.delete_failed', apiLocalizedText('errors.admin.custom_templates.delete_failed', 'Failed to delete custom template'));
            }
        });

        SimpleRouter::post('/custom-templates/install', function() {
            $auth = new Auth();
            $user = $auth->requireAuth();

            $adminController = new AdminController();
            $adminController->requireAdmin($user);

            header('Content-Type: application/json');

            $input = json_decode(file_get_contents('php://input'), true);
            $source = trim($input['source'] ?? '');
            $overwrite = !empty($input['overwrite']);
            try {
                $client = new \BinktermPHP\Admin\AdminDaemonClient();
                $result = $client->installCustomTemplate($source, $overwrite);
                if (is_array($result) && !isset($result['message_code']) && !isset($result['error']) && (($result['success'] ?? true) === true)) {
                    $result['message_code'] = 'ui.admin.template_editor.template_installed_success';
                }
                if (is_array($result)) {
                    $result = apiLocalizeErrorPayload($result, $user);
                }
                echo json_encode($result);
            } catch (Exception $e) {
                http_response_code(400);
                apiError('errors.admin.custom_templates.install_failed', apiLocalizedText('errors.admin.custom_templates.install_failed', 'Failed to install custom template'));
            }
        });
    });

    // ========================================
    // Language Overlay Editor
    // ========================================

    // GET /admin/api/i18n-overrides/namespaces?locale=en
    SimpleRouter::get('/api/i18n-overrides/namespaces', function() {
        $user = RouteHelper::requireAdmin();
        header('Content-Type: application/json');

        $locale = trim($_GET['locale'] ?? '');
        if ($locale === '') {
            apiError('errors.admin.i18n_overrides.invalid_locale', 'Locale is required', 400);
        }

        $translator = new \BinktermPHP\I18n\Translator();
        if (!$translator->isSupportedLocale($locale)) {
            apiError('errors.admin.i18n_overrides.invalid_locale', 'Unsupported locale', 400);
        }

        echo json_encode([
            'success'    => true,
            'locale'     => $locale,
            'namespaces' => $translator->getAvailableNamespaces($locale),
        ]);
    });

    // GET /admin/api/i18n-overrides?locale=en&ns=common
    SimpleRouter::get('/api/i18n-overrides', function() {
        $user = RouteHelper::requireAdmin();
        header('Content-Type: application/json');

        $locale = trim($_GET['locale'] ?? '');
        $ns     = trim($_GET['ns'] ?? '');

        if ($locale === '' || $ns === '') {
            apiError('errors.admin.i18n_overrides.missing_params', 'locale and ns are required', 400);
        }

        try {
            $client = new \BinktermPHP\Admin\AdminDaemonClient();
            $result = $client->getI18nOverlay($locale, $ns);
            echo json_encode(array_merge(['success' => true, 'locale' => $locale, 'ns' => $ns], $result));
        } catch (Exception $e) {
            apiError('errors.admin.i18n_overrides.load_failed', 'Failed to load overlay: ' . $e->getMessage(), 500);
        }
    });

    // POST /admin/api/i18n-overrides
    SimpleRouter::post('/api/i18n-overrides', function() {
        $user = RouteHelper::requireAdmin();
        header('Content-Type: application/json');

        $input     = json_decode(file_get_contents('php://input'), true) ?? [];
        $locale    = trim((string)($input['locale'] ?? ''));
        $ns        = trim((string)($input['ns'] ?? ''));
        $overrides = is_array($input['overrides'] ?? null) ? $input['overrides'] : [];

        if ($locale === '' || $ns === '') {
            apiError('errors.admin.i18n_overrides.missing_params', 'locale and ns are required', 400);
        }

        try {
            $client = new \BinktermPHP\Admin\AdminDaemonClient();
            $result = $client->saveI18nOverlay($locale, $ns, $overrides);
            echo json_encode(['success' => true, 'saved' => count(array_filter($overrides, fn($v) => $v !== ''))]);
        } catch (Exception $e) {
            apiError('errors.admin.i18n_overrides.save_failed', 'Failed to save overlay: ' . $e->getMessage(), 500);
        }
    });

    // Auto Feed page
    SimpleRouter::get('/auto-feed', function() {
        $user = RouteHelper::requireAdmin();

        $template = new Template();
        $template->renderResponse('admin/auto_feed.twig');
    });

    // Auto Feed API - Get all feeds
    SimpleRouter::get('/api/auto-feed/feeds', function() {
        $user = RouteHelper::requireAdmin();
        $db = \BinktermPHP\Database::getInstance()->getPdo();

        header('Content-Type: application/json');

        $stmt = $db->query("
            SELECT f.*, u.username, e.tag as echoarea_tag, e.domain as echoarea_domain
            FROM auto_feed_sources f
            LEFT JOIN users u ON u.id = f.post_as_user_id
            LEFT JOIN echoareas e ON e.id = f.echoarea_id
            ORDER BY f.id DESC
        ");
        $feeds = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['feeds' => $feeds]);
    });

    // Auto Feed API - Get single feed
    SimpleRouter::get('/api/auto-feed/feeds/{id}', function($id) {
        $user = RouteHelper::requireAdmin();
        $db = \BinktermPHP\Database::getInstance()->getPdo();

        header('Content-Type: application/json');

        $stmt = $db->prepare("SELECT * FROM auto_feed_sources WHERE id = ?");
        $stmt->execute([$id]);
        $feed = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$feed) {
            http_response_code(404);
            apiError('errors.admin.auto_feed.not_found', apiLocalizedText('errors.admin.auto_feed.not_found', 'Feed source not found'));
            return;
        }

        echo json_encode(['feed' => $feed]);
    });

    // Auto Feed API - Create feed
    SimpleRouter::post('/api/auto-feed/feeds', function() {
        $user = RouteHelper::requireAdmin();
        $db = \BinktermPHP\Database::getInstance()->getPdo();

        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);

        // Validate required fields
        if (empty($input['feed_url']) || empty($input['echoarea_id']) || empty($input['post_as_user_id'])) {
            http_response_code(400);
            apiError('errors.admin.auto_feed.required_fields', apiLocalizedText('errors.admin.auto_feed.required_fields', 'Feed URL, echo area, and posting user are required'));
            return;
        }

        // Validate URL
        if (!filter_var($input['feed_url'], FILTER_VALIDATE_URL)) {
            http_response_code(400);
            apiError('errors.admin.auto_feed.invalid_url', apiLocalizedText('errors.admin.auto_feed.invalid_url', 'Feed URL is invalid'));
            return;
        }

        // Validate echoarea exists
        $stmt = $db->prepare("SELECT id FROM echoareas WHERE id = ?");
        $stmt->execute([$input['echoarea_id']]);
        if (!$stmt->fetch()) {
            http_response_code(400);
            apiError('errors.admin.auto_feed.echoarea_not_found', apiLocalizedText('errors.admin.auto_feed.echoarea_not_found', 'Echo area not found'));
            return;
        }

        // Validate user exists
        $stmt = $db->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->execute([$input['post_as_user_id']]);
        if (!$stmt->fetch()) {
            http_response_code(400);
            apiError('errors.admin.auto_feed.user_not_found', apiLocalizedText('errors.admin.auto_feed.user_not_found', 'Posting user not found'));
            return;
        }

        try {
            $stmt = $db->prepare("
                INSERT INTO auto_feed_sources
                (feed_url, feed_name, echoarea_id, post_as_user_id,
                 max_articles_per_check, active, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([
                $input['feed_url'],
                $input['feed_name'] ?? null,
                $input['echoarea_id'],
                $input['post_as_user_id'],
                $input['max_articles_per_check'] ?? 10,
                $input['active'] ?? true
            ]);

            $feedId = $db->lastInsertId();

            // Log action
            $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
            AdminActionLogger::logAction($userId, 'auto_feed_created', [
                'feed_id' => $feedId,
                'feed_url' => $input['feed_url'],
                'echoarea_id' => $input['echoarea_id']
            ]);

            echo json_encode([
                'success' => true,
                'id' => $feedId,
                'message_code' => 'ui.admin.auto_feed.created_success'
            ]);
        } catch (PDOException $e) {
            http_response_code(400);
            if (strpos($e->getMessage(), 'duplicate key') !== false) {
                apiError('errors.admin.auto_feed.duplicate_source', apiLocalizedText('errors.admin.auto_feed.duplicate_source', 'Feed source already exists'));
            } else {
                apiError('errors.admin.auto_feed.create_failed', apiLocalizedText('errors.admin.auto_feed.create_failed', 'Failed to create feed source'));
            }
        }
    });

    // Auto Feed API - Update feed
    SimpleRouter::put('/api/auto-feed/feeds/{id}', function($id) {
        $user = RouteHelper::requireAdmin();
        $db = \BinktermPHP\Database::getInstance()->getPdo();

        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);

        // Validate feed exists
        $stmt = $db->prepare("SELECT * FROM auto_feed_sources WHERE id = ?");
        $stmt->execute([$id]);
        $existingFeed = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existingFeed) {
            http_response_code(404);
            apiError('errors.admin.auto_feed.not_found', apiLocalizedText('errors.admin.auto_feed.not_found', 'Feed source not found'));
            return;
        }

        // Validate required fields
        if (empty($input['feed_url']) || empty($input['echoarea_id']) || empty($input['post_as_user_id'])) {
            http_response_code(400);
            apiError('errors.admin.auto_feed.required_fields', apiLocalizedText('errors.admin.auto_feed.required_fields', 'Feed URL, echo area, and posting user are required'));
            return;
        }

        try {
            $stmt = $db->prepare("
                UPDATE auto_feed_sources
                SET feed_url = ?,
                    feed_name = ?,
                    echoarea_id = ?,
                    post_as_user_id = ?,
                    max_articles_per_check = ?,
                    active = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $input['feed_url'],
                $input['feed_name'] ?? null,
                $input['echoarea_id'],
                $input['post_as_user_id'],
                $input['max_articles_per_check'] ?? 10,
                $input['active'] ?? true,
                $id
            ]);

            // Log action
            $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
            AdminActionLogger::logAction($userId, 'auto_feed_updated', [
                'feed_id' => $id,
                'feed_url' => $input['feed_url'],
                'echoarea_id' => $input['echoarea_id']
            ]);

            echo json_encode([
                'success' => true,
                'message_code' => 'ui.admin.auto_feed.updated_success'
            ]);
        } catch (PDOException $e) {
            http_response_code(400);
            apiError('errors.admin.auto_feed.update_failed', apiLocalizedText('errors.admin.auto_feed.update_failed', 'Failed to update feed source'));
        }
    });

    // Auto Feed API - Delete feed
    SimpleRouter::delete('/api/auto-feed/feeds/{id}', function($id) {
        $user = RouteHelper::requireAdmin();
        $db = \BinktermPHP\Database::getInstance()->getPdo();

        header('Content-Type: application/json');

        // Get feed info for logging
        $stmt = $db->prepare("SELECT feed_url FROM auto_feed_sources WHERE id = ?");
        $stmt->execute([$id]);
        $feed = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$feed) {
            http_response_code(404);
            apiError('errors.admin.auto_feed.not_found', apiLocalizedText('errors.admin.auto_feed.not_found', 'Feed source not found'));
            return;
        }

        $stmt = $db->prepare("DELETE FROM auto_feed_sources WHERE id = ?");
        $stmt->execute([$id]);

        // Log action
        $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
        AdminActionLogger::logAction($userId, 'auto_feed_deleted', [
            'feed_id' => $id,
            'feed_url' => $feed['feed_url']
        ]);

        echo json_encode([
            'success' => true,
            'message_code' => 'ui.admin.auto_feed.deleted_success'
        ]);
    });

    // Auto Feed API - Check feed now
    SimpleRouter::post('/api/auto-feed/check/{id}', function($id) {
        $user = RouteHelper::requireAdmin();
        $db = \BinktermPHP\Database::getInstance()->getPdo();

        header('Content-Type: application/json');

        // Verify feed exists and is active
        $stmt = $db->prepare("SELECT * FROM auto_feed_sources WHERE id = ?");
        $stmt->execute([$id]);
        $feed = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$feed) {
            http_response_code(404);
            apiError('errors.admin.auto_feed.not_found', apiLocalizedText('errors.admin.auto_feed.not_found', 'Feed source not found'));
            return;
        }

        // Execute rss_poster.php script for this feed
        $scriptPath = __DIR__ . '/../scripts/rss_poster.php';
        $command = PHP_BINARY . ' ' . escapeshellarg($scriptPath) . ' --feed-id=' . (int)$id . ' --verbose 2>&1';

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            http_response_code(500);
            apiError('errors.admin.auto_feed.check_failed', apiLocalizedText('errors.admin.auto_feed.check_failed', 'Feed check failed'));
            return;
        }

        // Reload feed to get updated stats
        $stmt->execute([$id]);
        $updatedFeed = $stmt->fetch(PDO::FETCH_ASSOC);

        // Count articles posted
        $articlesPosted = $updatedFeed['articles_posted'] - $feed['articles_posted'];

        echo json_encode([
            'success' => true,
            'message_code' => 'ui.admin.auto_feed.checked_articles_posted',
            'message_params' => ['count' => max(0, $articlesPosted)],
            'articles_posted' => max(0, $articlesPosted),
            'output' => implode("\n", $output)
        ]);
    });

    // Auto Feed API - Get statistics
    SimpleRouter::get('/api/auto-feed/stats', function() {
        $user = RouteHelper::requireAdmin();
        $db = \BinktermPHP\Database::getInstance()->getPdo();

        header('Content-Type: application/json');

        $stmt = $db->query("
            SELECT
                COUNT(*) as total_feeds,
                COUNT(CASE WHEN active THEN 1 END) as active_feeds,
                COALESCE(SUM(articles_posted), 0) as total_articles
            FROM auto_feed_sources
        ");
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode($stats);
    });

    // Ad analytics API (license required)
    SimpleRouter::get('/api/ad-analytics', function() {
        RouteHelper::requireAdmin();

        if (!\BinktermPHP\License::isValid()) {
            http_response_code(403);
            apiError('errors.admin.ad_analytics.license_required', apiLocalizedText('errors.admin.ad_analytics.license_required', 'License required'));
            return;
        }

        header('Content-Type: application/json');

        $db     = \BinktermPHP\Database::getInstance()->getPdo();
        $period = $_GET['period'] ?? '30d';

        switch ($period) {
            case '7d':  $interval = "7 days";  break;
            case '90d': $interval = "90 days"; break;
            case 'all': $interval = null;       break;
            default:    $interval = "30 days";  break;
        }

        $dateFilterImp = $interval ? "AND ai.shown_at    >= NOW() - INTERVAL '{$interval}'" : '';
        $dateFilterClk = $interval ? "AND ac.clicked_at  >= NOW() - INTERVAL '{$interval}'" : '';
        $dateFilterDay = $interval ? "WHERE ts >= NOW() - INTERVAL '{$interval}'" : '';

        try {
            // Summary totals
            $totals = $db->query("
                SELECT
                    (SELECT COUNT(*) FROM advertisement_impressions " . ($interval ? "WHERE shown_at   >= NOW() - INTERVAL '{$interval}'" : '') . ") AS total_impressions,
                    (SELECT COUNT(*) FROM advertisement_clicks       " . ($interval ? "WHERE clicked_at >= NOW() - INTERVAL '{$interval}'" : '') . ") AS total_clicks,
                    (SELECT COUNT(*) FROM advertisements WHERE is_active = TRUE) AS active_ads,
                    (SELECT COUNT(*) FROM advertisements) AS total_ads
            ")->fetch(\PDO::FETCH_ASSOC);

            // Per-ad stats
            $perAdStmt = $db->prepare("
                SELECT a.id,
                       a.title,
                       a.slug,
                       a.click_url,
                       a.is_active,
                       COUNT(DISTINCT ai.id) AS impressions,
                       COUNT(DISTINCT ac.id) AS clicks,
                       MAX(ai.shown_at)      AS last_impression,
                       MAX(ac.clicked_at)    AS last_click
                FROM advertisements a
                LEFT JOIN advertisement_impressions ai ON ai.advertisement_id = a.id {$dateFilterImp}
                LEFT JOIN advertisement_clicks       ac ON ac.advertisement_id = a.id {$dateFilterClk}
                GROUP BY a.id
                ORDER BY impressions DESC, clicks DESC, LOWER(a.title)
            ");
            $perAdStmt->execute();
            $perAd = $perAdStmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($perAd as &$row) {
                $row['id']          = (int)$row['id'];
                $row['impressions'] = (int)$row['impressions'];
                $row['clicks']      = (int)$row['clicks'];
                $row['is_active']   = $row['is_active'] === 't' || $row['is_active'] === true || $row['is_active'] === '1';
                $row['ctr']         = $row['impressions'] > 0
                    ? round(($row['clicks'] / $row['impressions']) * 100, 1)
                    : 0.0;
            }
            unset($row);

            // Daily time-series (impressions + clicks per day)
            $dailyImpStmt = $db->query("
                SELECT DATE(shown_at AT TIME ZONE 'UTC') AS day, COUNT(*) AS cnt
                FROM advertisement_impressions
                " . ($interval ? "WHERE shown_at >= NOW() - INTERVAL '{$interval}'" : '') . "
                GROUP BY day ORDER BY day
            ");
            $dailyClkStmt = $db->query("
                SELECT DATE(clicked_at AT TIME ZONE 'UTC') AS day, COUNT(*) AS cnt
                FROM advertisement_clicks
                " . ($interval ? "WHERE clicked_at >= NOW() - INTERVAL '{$interval}'" : '') . "
                GROUP BY day ORDER BY day
            ");

            $impByDay = [];
            foreach ($dailyImpStmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $impByDay[$r['day']] = (int)$r['cnt'];
            }
            $clkByDay = [];
            foreach ($dailyClkStmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $clkByDay[$r['day']] = (int)$r['cnt'];
            }

            $allDays = array_unique(array_merge(array_keys($impByDay), array_keys($clkByDay)));
            sort($allDays);
            $daily = array_map(fn($d) => [
                'day'         => $d,
                'impressions' => $impByDay[$d] ?? 0,
                'clicks'      => $clkByDay[$d] ?? 0,
            ], $allDays);

            echo json_encode([
                'summary' => [
                    'total_impressions' => (int)$totals['total_impressions'],
                    'total_clicks'      => (int)$totals['total_clicks'],
                    'active_ads'        => (int)$totals['active_ads'],
                    'total_ads'         => (int)$totals['total_ads'],
                    'overall_ctr'       => (int)$totals['total_impressions'] > 0
                        ? round(((int)$totals['total_clicks'] / (int)$totals['total_impressions']) * 100, 1)
                        : 0.0,
                ],
                'per_ad' => $perAd,
                'daily'  => $daily,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            apiError('errors.admin.ad_analytics.load_failed', apiLocalizedText('errors.admin.ad_analytics.load_failed', 'Failed to load analytics'));
        }
    });

    // Activity statistics API
    SimpleRouter::get('/api/activity-stats', function() {
        RouteHelper::requireAdmin();

        header('Content-Type: application/json');

        $db = \BinktermPHP\Database::getInstance()->getPdo();
        $period        = $_GET['period'] ?? '30d';
        $excludeAdmins = !empty($_GET['exclude_admins']);

        // Validate and sanitize user timezone
        $requestedTz = $_GET['timezone'] ?? 'UTC';
        try {
            new \DateTimeZone($requestedTz);
            $timezone = $requestedTz;
        } catch (\Exception $e) {
            $timezone = 'UTC';
        }

        // Build date filter condition
        switch ($period) {
            case '7d':
                $dateFilter = "AND ual.created_at >= NOW() - INTERVAL '7 days'";
                break;
            case '90d':
                $dateFilter = "AND ual.created_at >= NOW() - INTERVAL '90 days'";
                break;
            case 'all':
                $dateFilter = '';
                break;
            case '30d':
            default:
                $dateFilter = "AND ual.created_at >= NOW() - INTERVAL '30 days'";
                break;
        }

        // Optionally exclude activity from admin users
        $adminFilter = $excludeAdmins
            ? "AND (ual.user_id IS NULL OR ual.user_id NOT IN (SELECT id FROM users WHERE is_admin = TRUE))"
            : '';

        // Check that user_activity_log table exists
        try {
            $db->query("SELECT 1 FROM user_activity_log LIMIT 1");
        } catch (\Exception $e) {
            apiError('errors.admin.activity_stats.table_missing', apiLocalizedText('errors.admin.activity_stats.table_missing', 'Activity log table is not available'));
            return;
        }

        // Summary: total + by category
        $summaryStmt = $db->query("
            SELECT ac.name AS category, COUNT(*) AS cnt
            FROM user_activity_log ual
            JOIN activity_types at2 ON ual.activity_type_id = at2.id
            JOIN activity_categories ac ON at2.category_id = ac.id
            WHERE 1=1 {$dateFilter}{$adminFilter}
            GROUP BY ac.name
        ");
        $categoryRows = $summaryStmt->fetchAll(\PDO::FETCH_ASSOC);
        $byCategory = [];
        $totalEvents = 0;
        foreach ($categoryRows as $row) {
            $byCategory[$row['category']] = (int)$row['cnt'];
            $totalEvents += (int)$row['cnt'];
        }

        // Summary: per activity type (for netmail/echomail breakdown)
        $typeStmt = $db->query("
            SELECT activity_type_id, COUNT(*) AS cnt
            FROM user_activity_log ual
            WHERE 1=1 {$dateFilter}{$adminFilter}
            GROUP BY activity_type_id
        ");
        $byType = [];
        foreach ($typeStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $byType[(int)$row['activity_type_id']] = (int)$row['cnt'];
        }

        // Login breakdown by source (object_name: 'web', 'telnet', 'ssh', etc.)
        $loginSourceStmt = $db->query("
            SELECT COALESCE(object_name, 'web') AS source, COUNT(*) AS cnt
            FROM user_activity_log ual
            WHERE activity_type_id = 13 {$dateFilter}{$adminFilter}
            GROUP BY COALESCE(object_name, 'web')
            ORDER BY cnt DESC
        ");
        $loginBySource = [];
        foreach ($loginSourceStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $loginBySource[$row['source']] = (int)$row['cnt'];
        }

        // Popular echoareas (views and posts)
        $echoAreasStmt = $db->query("
            SELECT object_name AS name,
                   SUM(CASE WHEN activity_type_id = 1 THEN 1 ELSE 0 END) AS views,
                   SUM(CASE WHEN activity_type_id = 2 THEN 1 ELSE 0 END) AS posts
            FROM user_activity_log ual
            WHERE activity_type_id IN (1, 2) {$dateFilter}{$adminFilter}
              AND object_name IS NOT NULL
            GROUP BY object_name
            ORDER BY views DESC
            LIMIT 20
        ");
        $popularEchoareas = $echoAreasStmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($popularEchoareas as &$row) {
            $row['views'] = (int)$row['views'];
            $row['posts'] = (int)$row['posts'];
        }
        unset($row);

        // Popular WebDoors
        $webdoorsStmt = $db->query("
            SELECT object_name AS name, COUNT(*) AS count
            FROM user_activity_log ual
            WHERE activity_type_id = 8 {$dateFilter}{$adminFilter}
              AND object_name IS NOT NULL
            GROUP BY object_name
            ORDER BY count DESC
            LIMIT 10
        ");
        $popularWebdoors = $webdoorsStmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($popularWebdoors as &$row) { $row['count'] = (int)$row['count']; }
        unset($row);

        // Popular DOS Doors
        $dosdoorsStmt = $db->query("
            SELECT object_name AS name, COUNT(*) AS count
            FROM user_activity_log ual
            WHERE activity_type_id = 9 {$dateFilter}{$adminFilter}
              AND object_name IS NOT NULL
            GROUP BY object_name
            ORDER BY count DESC
            LIMIT 10
        ");
        $popularDosdoors = $dosdoorsStmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($popularDosdoors as &$row) { $row['count'] = (int)$row['count']; }
        unset($row);

        // Top downloaded files
        $topFilesStmt = $db->query("
            SELECT COALESCE(f.filename, ual.object_name) AS name, COUNT(*) AS count
            FROM user_activity_log ual
            JOIN files f ON f.id = ual.object_id
            JOIN file_areas fa ON fa.id = f.file_area_id
            WHERE activity_type_id = 6 {$dateFilter}{$adminFilter}
              AND COALESCE(fa.is_private, FALSE) = FALSE
              AND COALESCE(f.filename, ual.object_name) IS NOT NULL
            GROUP BY COALESCE(f.filename, ual.object_name)
            ORDER BY count DESC
            LIMIT 15
        ");
        $topFiles = $topFilesStmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($topFiles as &$row) { $row['count'] = (int)$row['count']; }
        unset($row);

        // Most browsed file areas
        $fileAreasStmt = $db->query("
            SELECT ual.object_id AS area_id, fa.tag AS area_name, COUNT(*) AS count
            FROM user_activity_log ual
            JOIN file_areas fa ON fa.id = ual.object_id
            WHERE ual.activity_type_id = 5 {$dateFilter}{$adminFilter}
              AND ual.object_id IS NOT NULL
              AND COALESCE(fa.is_private, FALSE) = FALSE
            GROUP BY ual.object_id, fa.tag
            ORDER BY count DESC
            LIMIT 10
        ");
        $topFileareas = $fileAreasStmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($topFileareas as &$row) {
            $row['area_id']   = (int)$row['area_id'];
            $row['area_name'] = $row['area_name'] ?? 'Area #' . $row['area_id'];  // deleted area fallback
            $row['count']     = (int)$row['count'];
        }
        unset($row);

        // Nodelist searches
        $nodelistSearchStmt = $db->query("
            SELECT object_name AS name, COUNT(*) AS count
            FROM user_activity_log ual
            WHERE activity_type_id = 10 {$dateFilter}{$adminFilter}
              AND object_name IS NOT NULL
            GROUP BY object_name
            ORDER BY count DESC
            LIMIT 10
        ");
        $topNodelistSearches = $nodelistSearchStmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($topNodelistSearches as &$row) { $row['count'] = (int)$row['count']; }
        unset($row);

        // Most viewed nodes
        $topNodesStmt = $db->query("
            SELECT object_name AS name, COUNT(*) AS count
            FROM user_activity_log ual
            WHERE activity_type_id = 11 {$dateFilter}{$adminFilter}
              AND object_name IS NOT NULL
            GROUP BY object_name
            ORDER BY count DESC
            LIMIT 10
        ");
        $topNodes = $topNodesStmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($topNodes as &$row) { $row['count'] = (int)$row['count']; }
        unset($row);

        // Top users
        $topUsersStmt = $db->query("
            SELECT u.username, COUNT(*) AS count
            FROM user_activity_log ual
            LEFT JOIN users u ON ual.user_id = u.id
            WHERE 1=1 {$dateFilter}{$adminFilter}
              AND ual.user_id IS NOT NULL
            GROUP BY ual.user_id, u.username
            ORDER BY count DESC
            LIMIT 15
        ");
        $topUsers = $topUsersStmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($topUsers as &$row) { $row['count'] = (int)$row['count']; }
        unset($row);

        // Hourly distribution in user's timezone
        $hourlyStmt = $db->prepare("
            SELECT EXTRACT(HOUR FROM created_at AT TIME ZONE :tz)::int AS hour, COUNT(*) AS count
            FROM user_activity_log ual
            WHERE 1=1 {$dateFilter}{$adminFilter}
            GROUP BY hour
            ORDER BY hour
        ");
        $hourlyStmt->execute([':tz' => $timezone]);
        $hourlyRaw = $hourlyStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Fill all 24 hours even if no data
        $hourly = [];
        $hourlyByHour = [];
        foreach ($hourlyRaw as $row) {
            $hourlyByHour[(int)$row['hour']] = (int)$row['count'];
        }
        for ($h = 0; $h < 24; $h++) {
            $hourly[] = ['hour' => $h, 'count' => $hourlyByHour[$h] ?? 0];
        }

        // Popular interests by subscriber count
        $popularInterests = [];
        $interestsEnabled = \BinktermPHP\Config::env('ENABLE_INTERESTS', 'true') === 'true';
        if ($interestsEnabled) {
            try {
                $popularInterestsStmt = $db->query("
                    SELECT i.name, i.icon, i.color, COUNT(uis.user_id) AS subscribers
                    FROM interests i
                    LEFT JOIN user_interest_subscriptions uis ON uis.interest_id = i.id
                    WHERE i.is_active = TRUE
                    GROUP BY i.id, i.name, i.icon, i.color
                    ORDER BY subscribers DESC
                    LIMIT 15
                ");
                $popularInterests = $popularInterestsStmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($popularInterests as &$row) {
                    $row['subscribers'] = (int)$row['subscribers'];
                }
                unset($row);
            } catch (\Exception $e) {
                $popularInterests = [];
            }
        }

        // Daily activity (last 30 days always, regardless of period for the overview chart)
        $dailyAdminFilter = $excludeAdmins
            ? "AND (user_id IS NULL OR user_id NOT IN (SELECT id FROM users WHERE is_admin = TRUE))"
            : '';
        $dailyStmt = $db->prepare("
            SELECT DATE(created_at AT TIME ZONE :tz) AS date, COUNT(*) AS count
            FROM user_activity_log
            WHERE created_at >= NOW() - INTERVAL '30 days'
            {$dailyAdminFilter}
            GROUP BY date
            ORDER BY date
        ");
        $dailyStmt->execute([':tz' => $timezone]);
        $daily = $dailyStmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($daily as &$row) { $row['count'] = (int)$row['count']; }
        unset($row);

        echo json_encode([
            'period'   => $period,
            'timezone' => $timezone,
            'summary' => [
                'total'       => $totalEvents,
                'by_category' => $byCategory,
                'by_type'     => [
                    'echomail_views' => $byType[1] ?? 0,
                    'echomail_sends' => $byType[2] ?? 0,
                    'netmail_reads'  => $byType[3] ?? 0,
                    'netmail_sends'  => $byType[4] ?? 0,
                ],
                'login_by_source' => $loginBySource,
            ],
            'popular_echoareas'     => $popularEchoareas,
            'popular_webdoors'      => $popularWebdoors,
            'popular_dosdoors'      => $popularDosdoors,
            'top_files'             => $topFiles,
            'top_fileareas'         => $topFileareas,
            'top_nodelist_searches' => $topNodelistSearches,
            'top_nodes'             => $topNodes,
            'top_users'             => $topUsers,
            'popular_interests'     => $popularInterests,
            'hourly'                => $hourly,
            'daily'                 => $daily,
        ]);
    });

    SimpleRouter::get('/api/sharing', function() {
        RouteHelper::requireAdmin();

        header('Content-Type: application/json');

        $db = \BinktermPHP\Database::getInstance()->getPdo();
        $baseUrl = \BinktermPHP\Config::getSiteUrl();

        $messageStmt = $db->query("
            SELECT sm.message_type,
                   sm.share_key,
                   sm.area_identifier,
                   sm.slug,
                   sm.created_at,
                   sm.expires_at,
                   sm.access_count,
                   sm.last_accessed_at,
                   sm.is_public,
                   u.username AS shared_by_username,
                   u.real_name AS shared_by_real_name,
                   COALESCE(em.subject, nm.subject, '') AS subject,
                   CASE
                       WHEN sm.message_type = 'echomail' THEN ea.tag
                       ELSE 'netmail'
                   END AS area_tag
            FROM shared_messages sm
            LEFT JOIN echomail em ON (sm.message_type = 'echomail' AND sm.message_id = em.id)
            LEFT JOIN echoareas ea ON (sm.message_type = 'echomail' AND em.echoarea_id = ea.id)
            LEFT JOIN netmail nm ON (sm.message_type = 'netmail' AND sm.message_id = nm.id)
            JOIN users u ON sm.shared_by_user_id = u.id
            WHERE sm.is_active = TRUE
              AND (sm.expires_at IS NULL OR sm.expires_at > NOW())
            ORDER BY sm.access_count DESC, sm.created_at DESC
        ");
        $messageRows = $messageStmt->fetchAll(\PDO::FETCH_ASSOC);

        $messages = [];
        foreach ($messageRows as $row) {
            $areaIdentifier = $row['area_identifier'] ?? null;
            $slug = $row['slug'] ?? null;
            $shareUrl = (!empty($areaIdentifier) && !empty($slug))
                ? $baseUrl . '/shared/' . rawurlencode($areaIdentifier) . '/' . rawurlencode($slug)
                : $baseUrl . '/shared/' . $row['share_key'];

            $messages[] = [
                'message_type' => $row['message_type'],
                'subject' => $row['subject'],
                'area_tag' => $row['area_tag'] ?? '',
                'shared_by' => $row['shared_by_real_name'] ?: $row['shared_by_username'],
                'created_at' => $row['created_at'],
                'expires_at' => $row['expires_at'],
                'access_count' => (int)$row['access_count'],
                'last_accessed_at' => $row['last_accessed_at'],
                'is_public' => filter_var($row['is_public'], FILTER_VALIDATE_BOOLEAN),
                'share_url' => $shareUrl,
            ];
        }

        $fileStmt = $db->query("
            SELECT sf.created_at,
                   sf.expires_at,
                   sf.access_count,
                   sf.last_accessed_at,
                   sf.freq_accessible,
                   u.username AS shared_by_username,
                   u.real_name AS shared_by_real_name,
                   f.filename,
                   fa.tag AS area_tag
            FROM shared_files sf
            JOIN files f ON sf.file_id = f.id
            JOIN file_areas fa ON f.file_area_id = fa.id
            JOIN users u ON sf.shared_by_user_id = u.id
            WHERE sf.is_active = TRUE
              AND (sf.expires_at IS NULL OR sf.expires_at > NOW())
              AND f.status = 'approved'
            ORDER BY sf.access_count DESC, sf.created_at DESC
        ");
        $fileRows = $fileStmt->fetchAll(\PDO::FETCH_ASSOC);

        $files = [];
        foreach ($fileRows as $row) {
            $files[] = [
                'filename' => $row['filename'],
                'area_tag' => $row['area_tag'],
                'shared_by' => $row['shared_by_real_name'] ?: $row['shared_by_username'],
                'created_at' => $row['created_at'],
                'expires_at' => $row['expires_at'],
                'access_count' => (int)$row['access_count'],
                'last_accessed_at' => $row['last_accessed_at'],
                'freq_accessible' => filter_var($row['freq_accessible'], FILTER_VALIDATE_BOOLEAN),
                'share_url' => $baseUrl
                    . '/shared/file/'
                    . rawurlencode($row['area_tag'])
                    . '/'
                    . rawurlencode($row['filename']),
            ];
        }

        echo json_encode([
            'messages' => $messages,
            'files' => $files,
        ]);
    });
});


// FREQ Log admin page
SimpleRouter::get('/admin/freq-log', function() {
    $auth = new Auth();
    $user = $auth->requireAuth();

    $adminController = new AdminController();
    $adminController->requireAdmin($user);

    $template = new Template();
    $template->renderResponse('admin/freq_log.twig');
});

// Crashmail Queue page
SimpleRouter::get('/admin/crashmail', function() {
    $auth = new Auth();
    $user = $auth->requireAuth();

    $adminController = new AdminController();
    $adminController->requireAdmin($user);

    $template = new Template();
    $template->renderResponse('admin/crashmail_queue.twig');
});

// Insecure Nodes page
SimpleRouter::get('/admin/insecure-nodes', function() {
    $auth = new Auth();
    $user = $auth->requireAuth();

    $adminController = new AdminController();
    $adminController->requireAdmin($user);

    $template = new Template();
    $template->renderResponse('admin/insecure_nodes.twig');
});

// Binkp Sessions page
SimpleRouter::get('/admin/binkp-sessions', function() {
    $auth = new Auth();
    $user = $auth->requireAuth();

    $adminController = new AdminController();
    $adminController->requireAdmin($user);

    $template = new Template();
    $template->renderResponse('admin/binkp_sessions.twig');
});

// Admin subscription management page
SimpleRouter::get('/admin/subscriptions', function() {
    $auth = new Auth();
    $user = $auth->requireAuth();

    $adminController = new AdminController();
    $adminController->requireAdmin($user);

    $controller = new BinktermPHP\SubscriptionController();
    $data = $controller->renderAdminSubscriptionPage();

    // Only render template if we got data back (not redirected)
    if ($data !== null) {
        $template = new Template();
        $template->renderResponse('admin_subscriptions.twig', $data);
    }
});

// ─── Weather Config Admin ────────────────────────────────────────────────────

// Weather configuration page
SimpleRouter::get('/admin/weather-config', function() {
    RouteHelper::requireAdmin();
    $template = new Template();
    $template->renderResponse('admin/weather_config.twig');
});

if (!function_exists('buildWeatherConfigFromRequestBody')) {
    /**
     * @return array{0: ?array, 1: ?string}
     */
    function buildWeatherConfigFromRequestBody($body): array
    {
        if (!is_array($body)) {
            return [null, 'invalid_json'];
        }

        $title = trim((string)($body['title'] ?? ''));
        $coverageArea = trim((string)($body['coverage_area'] ?? ''));
        $apiKey = trim((string)($body['api_key'] ?? ''));
        $locations = $body['locations'] ?? [];
        $settings = $body['settings'] ?? [];

        if ($title === '') {
            return [null, 'errors.admin.weather.title_required'];
        }
        if ($apiKey === '') {
            return [null, 'errors.admin.weather.api_key_required'];
        }
        if (!is_array($locations) || count($locations) === 0) {
            return [null, 'errors.admin.weather.locations_required'];
        }

        return [[
            'title' => $title,
            'coverage_area' => $coverageArea,
            'api_key' => $apiKey,
            'locations' => array_values(array_map(function($loc) {
                return [
                    'name' => trim((string)($loc['name'] ?? '')),
                    'lat' => (float)($loc['lat'] ?? 0),
                    'lon' => (float)($loc['lon'] ?? 0),
                ];
            }, $locations)),
            'settings' => [
                'api_timeout' => max(1, (int)($settings['api_timeout'] ?? 10)),
                'max_locations' => max(1, (int)($settings['max_locations'] ?? 10)),
                'units' => in_array($settings['units'] ?? '', ['metric', 'imperial', 'standard'], true)
                    ? $settings['units']
                    : 'metric',
            ],
        ], null];
    }
}

// GET current weather config
SimpleRouter::get('/admin/api/weather-config', function() {
    RouteHelper::requireAdmin();
    header('Content-Type: application/json');

    $daemon = new \BinktermPHP\Admin\AdminDaemonClient();
    try {
        $result = $daemon->getWeatherConfig();
        echo json_encode(['success' => true, 'data' => $result]);
    } catch (\Exception $e) {
        http_response_code(503);
        echo json_encode(['success' => false, 'error' => 'daemon_unreachable', 'daemon_error' => true]);
    }
});

// POST weather config preview
SimpleRouter::post('/admin/api/weather-config/preview', function() {
    RouteHelper::requireAdmin();
    header('Content-Type: application/json');

    [$config, $error] = buildWeatherConfigFromRequestBody(json_decode(file_get_contents('php://input'), true));
    if ($config === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $error]);
        return;
    }

    ob_start();
    require_once __DIR__ . '/../scripts/weather_report.php';
    ob_end_clean();

    $tmpPath = tempnam(sys_get_temp_dir(), 'binkweather_');
    if ($tmpPath === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'errors.admin.weather.preview_failed']);
        return;
    }

    try {
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($tmpPath, $json) === false) {
            throw new \RuntimeException('Failed to write temporary weather configuration');
        }

        $generator = new \WeatherReportGenerator($tmpPath);
        $report = $generator->generateReport(false);
        if (strpos($report, 'ERROR:') === 0) {
            throw new \RuntimeException(trim($report));
        }

        echo json_encode([
            'success' => true,
            'report' => $report,
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'errors.admin.weather.preview_failed',
            'message' => $e->getMessage(),
        ]);
    } finally {
        @unlink($tmpPath);
    }
});

// POST save weather config
SimpleRouter::post('/admin/api/weather-config', function() {
    $user = RouteHelper::requireAdmin();
    header('Content-Type: application/json');

    [$config, $error] = buildWeatherConfigFromRequestBody(json_decode(file_get_contents('php://input'), true));
    if ($config === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $error]);
        return;
    }

    $daemon = new \BinktermPHP\Admin\AdminDaemonClient();
    try {
        $daemon->saveWeatherConfig(json_encode($config));
        echo json_encode(['success' => true]);
    } catch (\Exception $e) {
        http_response_code(503);
        echo json_encode(['success' => false, 'error' => 'daemon_unreachable', 'daemon_error' => true]);
    }
});

// ─── BBS Directory Admin ─────────────────────────────────────────────────────

// BBS Directory admin page
SimpleRouter::get('/admin/bbs-directory', function() {
    RouteHelper::requireAdmin();
    $template = new Template();
    $template->renderResponse('admin/bbs_directory.twig');
});

// Echomail Robots admin page
SimpleRouter::get('/admin/echomail-robots', function() {
    RouteHelper::requireAdmin();
    $template = new Template();
    $template->renderResponse('admin/echomail_robots.twig');
});

// BBS Directory API - list entries (paged + search)
SimpleRouter::get('/admin/api/bbs-directory/entries', function() {
    RouteHelper::requireAdmin();
    $db = \BinktermPHP\Database::getInstance()->getPdo();
    header('Content-Type: application/json');

    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(1, min(100, (int)($_GET['per_page'] ?? 25)));
    $search  = trim($_GET['search'] ?? '');

    $directory = new \BinktermPHP\BbsDirectory($db);
    $entries   = $directory->getAllEntries($page, $perPage, $search);
    $total     = $directory->getTotalCount($search);

    echo json_encode([
        'entries'  => $entries,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
    ]);
});

// BBS Directory API - create manual entry
SimpleRouter::post('/admin/api/bbs-directory/entries', function() {
    $user = RouteHelper::requireAdmin();
    $db   = \BinktermPHP\Database::getInstance()->getPdo();
    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['name'])) {
        http_response_code(400);
        apiError('errors.admin.bbs_directory.name_required', apiLocalizedText('errors.admin.bbs_directory.name_required', 'BBS name is required'));
        return;
    }

    $directory = new \BinktermPHP\BbsDirectory($db);

    try {
        $id = $directory->createEntry($input);
        $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
        AdminActionLogger::logAction($userId, 'bbs_directory_entry_created', ['entry_id' => $id, 'name' => $input['name']]);
        echo json_encode(['success' => true, 'id' => $id]);
    } catch (\PDOException $e) {
        http_response_code(400);
        if (strpos($e->getMessage(), 'duplicate key') !== false || strpos($e->getMessage(), 'unique') !== false) {
            apiError('errors.admin.bbs_directory.duplicate_name', apiLocalizedText('errors.admin.bbs_directory.duplicate_name', 'A BBS with that name already exists'));
        } else {
            apiError('errors.admin.bbs_directory.not_found', $e->getMessage());
        }
    }
});

// BBS Directory API - update entry
SimpleRouter::put('/admin/api/bbs-directory/entries/{id}', function($id) {
    $user = RouteHelper::requireAdmin();
    $db   = \BinktermPHP\Database::getInstance()->getPdo();
    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['name'])) {
        http_response_code(400);
        apiError('errors.admin.bbs_directory.name_required', apiLocalizedText('errors.admin.bbs_directory.name_required', 'BBS name is required'));
        return;
    }

    $directory = new \BinktermPHP\BbsDirectory($db);

    if (!$directory->getEntry((int)$id)) {
        http_response_code(404);
        apiError('errors.admin.bbs_directory.not_found', apiLocalizedText('errors.admin.bbs_directory.not_found', 'BBS directory entry not found'));
        return;
    }

    try {
        $directory->updateEntry((int)$id, $input);
        $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
        AdminActionLogger::logAction($userId, 'bbs_directory_entry_updated', ['entry_id' => $id, 'name' => $input['name']]);
        echo json_encode(['success' => true]);
    } catch (\PDOException $e) {
        http_response_code(400);
        if (strpos($e->getMessage(), 'duplicate key') !== false || strpos($e->getMessage(), 'unique') !== false) {
            apiError('errors.admin.bbs_directory.duplicate_name', apiLocalizedText('errors.admin.bbs_directory.duplicate_name', 'A BBS with that name already exists'));
        } else {
            apiError('errors.admin.bbs_directory.not_found', $e->getMessage());
        }
    }
});

// BBS Directory API - delete entry
SimpleRouter::delete('/admin/api/bbs-directory/entries/{id}', function($id) {
    $user = RouteHelper::requireAdmin();
    $db   = \BinktermPHP\Database::getInstance()->getPdo();
    header('Content-Type: application/json');

    $directory = new \BinktermPHP\BbsDirectory($db);

    if (!$directory->getEntry((int)$id)) {
        http_response_code(404);
        apiError('errors.admin.bbs_directory.not_found', apiLocalizedText('errors.admin.bbs_directory.not_found', 'BBS directory entry not found'));
        return;
    }

    $directory->deleteEntry((int)$id);
    $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
    AdminActionLogger::logAction($userId, 'bbs_directory_entry_deleted', ['entry_id' => $id]);
    echo json_encode(['success' => true]);
});

// BBS Directory API - merge duplicate into keep entry
SimpleRouter::post('/admin/api/bbs-directory/entries/{id}/merge', function($id) {
    $user = RouteHelper::requireAdmin();
    $db   = \BinktermPHP\Database::getInstance()->getPdo();
    header('Content-Type: application/json');

    $input     = json_decode(file_get_contents('php://input'), true);
    $discardId = (int)($input['discard_id'] ?? 0);

    if ($discardId === 0) {
        http_response_code(400);
        apiError('errors.admin.bbs_directory.merge_missing_discard', apiLocalizedText('errors.admin.bbs_directory.merge_missing_discard', 'discard_id is required'));
        return;
    }

    $directory = new \BinktermPHP\BbsDirectory($db);

    if (!$directory->getEntry((int)$id)) {
        http_response_code(404);
        apiError('errors.admin.bbs_directory.not_found', apiLocalizedText('errors.admin.bbs_directory.not_found', 'BBS directory entry not found'));
        return;
    }

    if (!$directory->getEntry($discardId)) {
        http_response_code(404);
        apiError('errors.admin.bbs_directory.not_found', apiLocalizedText('errors.admin.bbs_directory.not_found', 'BBS directory entry not found'));
        return;
    }

    $ok = $directory->mergeEntries((int)$id, $discardId);
    if (!$ok) {
        http_response_code(400);
        apiError('errors.admin.bbs_directory.merge_failed', apiLocalizedText('errors.admin.bbs_directory.merge_failed', 'Merge failed'));
        return;
    }

    $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
    AdminActionLogger::logAction($userId, 'bbs_directory_entry_merged', ['keep_id' => (int)$id, 'discard_id' => $discardId]);
    echo json_encode(['success' => true]);
});

// BBS Directory API - list pending entries
SimpleRouter::get('/admin/api/bbs-directory/entries/pending', function() {
    RouteHelper::requireAdmin();
    $db = \BinktermPHP\Database::getInstance()->getPdo();
    header('Content-Type: application/json');

    $directory = new \BinktermPHP\BbsDirectory($db);
    echo json_encode(['entries' => $directory->getPendingEntries()]);
});

// BBS Directory API - get single entry
SimpleRouter::get('/admin/api/bbs-directory/entries/{id}', function($id) {
    RouteHelper::requireAdmin();
    $db = \BinktermPHP\Database::getInstance()->getPdo();
    header('Content-Type: application/json');

    $directory = new \BinktermPHP\BbsDirectory($db);
    $entry = $directory->getEntry((int)$id);
    if (!$entry) {
        http_response_code(404);
        apiError('errors.admin.bbs_directory.not_found', apiLocalizedText('errors.admin.bbs_directory.not_found', 'BBS directory entry not found'));
        return;
    }
    echo json_encode(['entry' => $entry]);
});

// BBS Directory API - approve pending entry
SimpleRouter::post('/admin/api/bbs-directory/entries/{id}/approve', function($id) {
    $user = RouteHelper::requireAdmin();
    $db   = \BinktermPHP\Database::getInstance()->getPdo();
    header('Content-Type: application/json');

    $directory = new \BinktermPHP\BbsDirectory($db);

    if (!$directory->getEntry((int)$id)) {
        http_response_code(404);
        apiError('errors.admin.bbs_directory.not_found', apiLocalizedText('errors.admin.bbs_directory.not_found', 'BBS directory entry not found'));
        return;
    }

    $directory->approveEntry((int)$id);
    $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
    AdminActionLogger::logAction($userId, 'bbs_directory_entry_approved', ['entry_id' => $id]);
    echo json_encode(['success' => true]);
});

// BBS Directory API - reject pending entry
SimpleRouter::post('/admin/api/bbs-directory/entries/{id}/reject', function($id) {
    $user = RouteHelper::requireAdmin();
    $db   = \BinktermPHP\Database::getInstance()->getPdo();
    header('Content-Type: application/json');

    $directory = new \BinktermPHP\BbsDirectory($db);

    if (!$directory->getEntry((int)$id)) {
        http_response_code(404);
        apiError('errors.admin.bbs_directory.not_found', apiLocalizedText('errors.admin.bbs_directory.not_found', 'BBS directory entry not found'));
        return;
    }

    $directory->rejectEntry((int)$id);
    $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
    AdminActionLogger::logAction($userId, 'bbs_directory_entry_rejected', ['entry_id' => $id]);
    echo json_encode(['success' => true]);
});

// BBS Directory API - list robot rules
SimpleRouter::get('/admin/api/bbs-directory/robots', function() {
    RouteHelper::requireAdmin();
    $db = \BinktermPHP\Database::getInstance()->getPdo();
    header('Content-Type: application/json');

    $stmt = $db->query("
        SELECT r.*, e.tag AS echoarea_tag, e.domain AS echoarea_domain
        FROM echomail_robots r
        LEFT JOIN echoareas e ON e.id = r.echoarea_id
        ORDER BY r.id ASC
    ");
    $robots = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    echo json_encode(['robots' => $robots]);
});

// BBS Directory API - create robot rule
SimpleRouter::post('/admin/api/bbs-directory/robots', function() {
    $user = RouteHelper::requireAdmin();
    $db   = \BinktermPHP\Database::getInstance()->getPdo();
    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['name']) || empty($input['echoarea_id']) || empty($input['processor_type'])) {
        http_response_code(400);
        apiError('errors.admin.bbs_directory.robot_required_fields', apiLocalizedText('errors.admin.bbs_directory.robot_required_fields', 'Name, echo area, and processor type are required'));
        return;
    }

    $runner     = new \BinktermPHP\Robots\EchomailRobotRunner($db);
    $processors = $runner->getRegisteredProcessors();
    if (!isset($processors[$input['processor_type']])) {
        http_response_code(400);
        apiError('errors.admin.bbs_directory.invalid_processor_type', apiLocalizedText('errors.admin.bbs_directory.invalid_processor_type', 'Unknown or unsupported processor type'));
        return;
    }

    $stmt = $db->prepare("
        INSERT INTO echomail_robots
            (name, echoarea_id, subject_pattern, processor_type, processor_config, enabled, created_at, updated_at)
        VALUES
            (:name, :echoarea_id, :subject_pattern, :processor_type, :processor_config, :enabled, NOW(), NOW())
        RETURNING id
    ");
    $stmt->execute([
        ':name'             => $input['name'],
        ':echoarea_id'      => (int)$input['echoarea_id'],
        ':subject_pattern'  => $input['subject_pattern'] ?? null,
        ':processor_type'   => $input['processor_type'],
        ':processor_config' => json_encode($input['processor_config'] ?? []),
        ':enabled'          => isset($input['enabled']) ? ($input['enabled'] ? 'true' : 'false') : 'true',
    ]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    $robotId = (int)$row['id'];

    $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
    AdminActionLogger::logAction($userId, 'echomail_robot_created', ['robot_id' => $robotId, 'name' => $input['name']]);
    echo json_encode(['success' => true, 'id' => $robotId]);
});

// BBS Directory API - update robot rule
SimpleRouter::put('/admin/api/bbs-directory/robots/{id}', function($id) {
    $user = RouteHelper::requireAdmin();
    $db   = \BinktermPHP\Database::getInstance()->getPdo();
    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true);

    $checkStmt = $db->prepare("SELECT id FROM echomail_robots WHERE id = ?");
    $checkStmt->execute([$id]);
    if (!$checkStmt->fetch()) {
        http_response_code(404);
        apiError('errors.admin.bbs_directory.robot_not_found', apiLocalizedText('errors.admin.bbs_directory.robot_not_found', 'Robot rule not found'));
        return;
    }

    if (empty($input['name']) || empty($input['echoarea_id']) || empty($input['processor_type'])) {
        http_response_code(400);
        apiError('errors.admin.bbs_directory.robot_required_fields', apiLocalizedText('errors.admin.bbs_directory.robot_required_fields', 'Name, echo area, and processor type are required'));
        return;
    }

    $runner     = new \BinktermPHP\Robots\EchomailRobotRunner($db);
    $processors = $runner->getRegisteredProcessors();
    if (!isset($processors[$input['processor_type']])) {
        http_response_code(400);
        apiError('errors.admin.bbs_directory.invalid_processor_type', apiLocalizedText('errors.admin.bbs_directory.invalid_processor_type', 'Unknown or unsupported processor type'));
        return;
    }

    $stmt = $db->prepare("
        UPDATE echomail_robots SET
            name             = :name,
            echoarea_id      = :echoarea_id,
            subject_pattern  = :subject_pattern,
            processor_type   = :processor_type,
            processor_config = :processor_config,
            enabled          = :enabled,
            updated_at       = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':name'             => $input['name'],
        ':echoarea_id'      => (int)$input['echoarea_id'],
        ':subject_pattern'  => $input['subject_pattern'] ?? null,
        ':processor_type'   => $input['processor_type'],
        ':processor_config' => json_encode($input['processor_config'] ?? []),
        ':enabled'          => isset($input['enabled']) ? ($input['enabled'] ? 'true' : 'false') : 'true',
        ':id'               => (int)$id,
    ]);

    $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
    AdminActionLogger::logAction($userId, 'echomail_robot_updated', ['robot_id' => $id, 'name' => $input['name']]);
    echo json_encode(['success' => true]);
});

// BBS Directory API - delete robot rule
SimpleRouter::delete('/admin/api/bbs-directory/robots/{id}', function($id) {
    $user = RouteHelper::requireAdmin();
    $db   = \BinktermPHP\Database::getInstance()->getPdo();
    header('Content-Type: application/json');

    $checkStmt = $db->prepare("SELECT name FROM echomail_robots WHERE id = ?");
    $checkStmt->execute([$id]);
    $robot = $checkStmt->fetch(\PDO::FETCH_ASSOC);

    if (!$robot) {
        http_response_code(404);
        apiError('errors.admin.bbs_directory.robot_not_found', apiLocalizedText('errors.admin.bbs_directory.robot_not_found', 'Robot rule not found'));
        return;
    }

    $stmt = $db->prepare("DELETE FROM echomail_robots WHERE id = ?");
    $stmt->execute([$id]);

    $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
    AdminActionLogger::logAction($userId, 'echomail_robot_deleted', ['robot_id' => $id, 'name' => $robot['name']]);
    echo json_encode(['success' => true]);
});

// BBS Directory API - run robot now (via admin daemon)
SimpleRouter::post('/admin/api/bbs-directory/robots/{id}/run', function($id) {
    RouteHelper::requireAdmin();
    $db = \BinktermPHP\Database::getInstance()->getPdo();
    header('Content-Type: application/json');

    $checkStmt = $db->prepare("SELECT id, name FROM echomail_robots WHERE id = ?");
    $checkStmt->execute([$id]);
    if (!$checkStmt->fetch()) {
        http_response_code(404);
        apiError('errors.admin.bbs_directory.robot_not_found', apiLocalizedText('errors.admin.bbs_directory.robot_not_found', 'Robot rule not found'));
        return;
    }

    try {
        $client = new \BinktermPHP\Admin\AdminDaemonClient();
        $result = $client->runEchomailRobot((int)$id);

        echo json_encode([
            'success'   => true,
            'exit_code' => $result['exit_code'] ?? 0,
            'output'    => trim($result['stdout'] ?? ''),
            'stderr'    => trim($result['stderr'] ?? ''),
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        apiError('errors.admin.bbs_directory.run_failed', $e->getMessage());
    }
});

// BBS Directory API - get registered processor types
SimpleRouter::get('/admin/api/bbs-directory/processor-types', function() {
    RouteHelper::requireAdmin();
    $db = \BinktermPHP\Database::getInstance()->getPdo();
    header('Content-Type: application/json');

    $runner     = new \BinktermPHP\Robots\EchomailRobotRunner($db);
    $processors = array_values($runner->getRegisteredProcessors());
    echo json_encode(['processors' => $processors]);
});

// ============================================================================
// LovlyNet subscription management
// ============================================================================

if (!function_exists('annotateLovlyNetAreasWithMetadataIssues')) {
    /**
     * @param array<int, array<string, mixed>> $areas
     * @param string $areaType
     * @return array<int, array<string, mixed>>
     */
    function annotateLovlyNetAreasWithMetadataIssues(array $areas, string $areaType): array
    {
        foreach ($areas as &$area) {
            $metadata = isset($area['metadata']) && is_array($area['metadata']) ? $area['metadata'] : [];
            $issues = [];

            if ($areaType === 'echo' && array_key_exists('sysop_only', $metadata) && !empty($area['local_exists'])) {
                $recommendedSysopOnly = filter_var($metadata['sysop_only'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $actualSysopOnly = !empty($area['local_is_sysop_only']);
                if ($recommendedSysopOnly !== null && $recommendedSysopOnly !== $actualSysopOnly) {
                    $issues[] = [
                        'setting' => 'sysop_only',
                        'recommended' => $recommendedSysopOnly,
                        'actual' => $actualSysopOnly,
                    ];
                }
            }

            if ($areaType === 'file' && array_key_exists('readonly', $metadata) && !empty($area['local_exists'])) {
                $recommendedReadonly = filter_var($metadata['readonly'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $actualReadonly = ((int)($area['local_upload_permission'] ?? -1)) === \BinktermPHP\FileAreaManager::UPLOAD_READ_ONLY;
                if ($recommendedReadonly !== null && $recommendedReadonly !== $actualReadonly) {
                    $issues[] = [
                        'setting' => 'readonly',
                        'recommended' => $recommendedReadonly,
                        'actual' => $actualReadonly,
                    ];
                }
            }

            if ($areaType === 'file' && array_key_exists('replace', $metadata) && !empty($area['local_exists'])) {
                $recommendedReplace = filter_var($metadata['replace'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $actualReplace = !empty($area['local_replace_existing']);
                if ($recommendedReplace !== null && $recommendedReplace !== $actualReplace) {
                    $issues[] = [
                        'setting' => 'replace',
                        'recommended' => $recommendedReplace,
                        'actual' => $actualReplace,
                    ];
                }
            }

            $area['setting_issues'] = $issues;
            $area['has_setting_issues'] = $issues !== [];
        }
        unset($area);

        return $areas;
    }
}

/**
 * GET /admin/lovlynet
 * Admin page for managing LovlyNet echo/file area subscriptions.
 */
SimpleRouter::get('/admin/lovlynet', function() {
    RouteHelper::requireAdmin();
    $client   = new \BinktermPHP\LovlyNetClient();
    $template = new Template();
    $template->renderResponse('admin/lovlynet.twig', [
        'lovlynet_configured'  => $client->isConfigured(),
        'lovlynet_node_number' => $client->getFtnAddress(),
        'lovlynet_base_url'    => $client->getBaseUrl(),
        'lovlynet_hub_address' => $client->getHubAddress(),
    ]);
});

/**
 * GET /admin/api/lovlynet/areas
 * Proxy: fetch echo and file areas with subscription status from LovlyNet.
 */
SimpleRouter::get('/admin/api/lovlynet/areas', function() {
    RouteHelper::requireAdmin();
    header('Content-Type: application/json');

    $client = new \BinktermPHP\LovlyNetClient();
    $result = $client->getAreas();

    if (!$result['success']) {
        http_response_code(502);
        echo json_encode(['error' => $result['error']]);
        return;
    }

    $echoareaManager = new \BinktermPHP\EchoareaManager();
    $fileAreaManager = new \BinktermPHP\FileAreaManager();
    $echoareas = $echoareaManager->annotateAreasWithLocalStatus($result['echoareas'], ['', 'lovlynet']);
    $fileareas = $fileAreaManager->annotateAreasWithLocalStatus($result['fileareas'], ['', 'lovlynet']);
    $echoareas = annotateLovlyNetAreasWithMetadataIssues($echoareas, 'echo');
    $fileareas = annotateLovlyNetAreasWithMetadataIssues($fileareas, 'file');

    echo json_encode([
        'echoareas'   => $echoareas,
        'fileareas'   => $fileareas,
        'ftn_address' => $result['ftn_address'] ?? '',
    ]);
});

/**
 * POST /admin/api/lovlynet/subscription
 * Proxy: subscribe or unsubscribe from a LovlyNet area.
 *
 * Body: { "action": "subscribe"|"unsubscribe", "area_type": "echo"|"file", "area_tag": "TAG" }
 */
SimpleRouter::post('/admin/api/lovlynet/subscription', function() {
    RouteHelper::requireAdmin();
    header('Content-Type: application/json');

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        return;
    }

    $action   = trim($body['action']   ?? '');
    $areaType = trim($body['area_type'] ?? '');
    $areaTag  = trim($body['area_tag']  ?? '');

    if (!in_array($action, ['subscribe', 'unsubscribe'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        return;
    }
    if (!in_array($areaType, ['echo', 'file'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid area_type']);
        return;
    }
    if ($areaTag === '') {
        http_response_code(400);
        echo json_encode(['error' => 'area_tag is required']);
        return;
    }

    $client = new \BinktermPHP\LovlyNetClient();

    if ($action === 'subscribe') {
        $areasResult = $client->getAreas();
        if (!$areasResult['success']) {
            http_response_code(502);
            echo json_encode(['error' => $areasResult['error']]);
            return;
        }
    }

    if ($action === 'subscribe' && $areaType === 'echo') {
        $remoteArea = null;
        foreach (($areasResult['echoareas'] ?? []) as $candidate) {
            if (strcasecmp(trim((string)($candidate['tag'] ?? '')), $areaTag) === 0) {
                $remoteArea = $candidate;
                break;
            }
        }

        if ($remoteArea === null) {
            http_response_code(400);
            echo json_encode(['error' => 'Unknown LovlyNet echo area']);
            return;
        }

        $metadata = isset($remoteArea['metadata']) && is_array($remoteArea['metadata'])
            ? $remoteArea['metadata'] : [];
        $isSysopOnly = false;
        if (isset($metadata['sysop_only'])) {
            $v = filter_var($metadata['sysop_only'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($v !== null) {
                $isSysopOnly = $v;
            }
        }

        $echoareaManager = new \BinktermPHP\EchoareaManager();
        $localEchoareaId = $echoareaManager->createIfMissing([
            'tag'            => $remoteArea['tag'] ?? $areaTag,
            'description'    => $remoteArea['description'] ?? '',
            'domain'         => 'lovlynet',
            'uplink_address' => $client->getHubAddress(),
            'is_local'       => false,
            'is_active'      => true,
            'is_sysop_only'  => $isSysopOnly,
            'gemini_public'  => false,
        ], ['', 'lovlynet']);

        $client->applyRecommendedSettings('echo', array_merge($remoteArea, [
            'local_echoarea_id' => $localEchoareaId,
        ]));
    }

    if ($action === 'subscribe' && $areaType === 'file') {
        $remoteArea = null;
        foreach (($areasResult['fileareas'] ?? []) as $candidate) {
            if (strcasecmp(trim((string)($candidate['tag'] ?? '')), $areaTag) === 0) {
                $remoteArea = $candidate;
                break;
            }
        }

        if ($remoteArea !== null) {
            $metadata = isset($remoteArea['metadata']) && is_array($remoteArea['metadata'])
                ? $remoteArea['metadata'] : [];
            $uploadPermission = \BinktermPHP\FileAreaManager::getDefaultUploadPermissionForArea(
                $remoteArea['tag'] ?? $areaTag, 'lovlynet'
            );
            if (isset($metadata['readonly'])) {
                $v = filter_var($metadata['readonly'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($v !== null) {
                    $uploadPermission = $v
                        ? \BinktermPHP\FileAreaManager::UPLOAD_READ_ONLY
                        : \BinktermPHP\FileAreaManager::UPLOAD_USERS_ALLOWED;
                }
            }
            $replaceExisting = true;
            if (isset($metadata['replace'])) {
                $v = filter_var($metadata['replace'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($v !== null) {
                    $replaceExisting = $v;
                }
            }

            $fileAreaManager = new \BinktermPHP\FileAreaManager();
            $localFileareaId = $fileAreaManager->createIfMissing([
                'tag'               => $remoteArea['tag'] ?? $areaTag,
                'description'       => $remoteArea['description'] ?? '',
                'domain'            => 'lovlynet',
                'is_local'          => false,
                'is_active'         => true,
                'upload_permission' => $uploadPermission,
                'replace_existing'  => $replaceExisting,
            ]);

            $client->applyRecommendedSettings('file', array_merge($remoteArea, [
                'local_filearea_id' => $localFileareaId,
            ]));
        }
    }

    $result = $client->setSubscription($action, $areaType, $areaTag);

    if (!$result['success']) {
        http_response_code(502);
        echo json_encode(['error' => $result['error']]);
        return;
    }

    $echoareas = $result['echoareas'] ?? [];
    $fileareas = $result['fileareas'] ?? [];
    if ($areaType === 'echo') {
        $echoareaManager = new \BinktermPHP\EchoareaManager();
        $echoareas = $echoareaManager->annotateAreasWithLocalStatus($echoareas, ['', 'lovlynet']);
        $echoareas = annotateLovlyNetAreasWithMetadataIssues($echoareas, 'echo');
    } elseif ($areaType === 'file') {
        $fileAreaManager = new \BinktermPHP\FileAreaManager();
        $fileareas = $fileAreaManager->annotateAreasWithLocalStatus($fileareas, ['', 'lovlynet']);
        $fileareas = annotateLovlyNetAreasWithMetadataIssues($fileareas, 'file');
    }

    echo json_encode([
        'success'    => true,
        'echoareas'  => $echoareas,
        'fileareas'  => $fileareas,
    ]);
});

/**
 * POST /admin/api/lovlynet/request
 * Send an AreaFix or FileFix netmail request to the configured LovlyNet hub.
 *
 * Body: { "area_type": "echo"|"file", "message_text": "..." }
 */
SimpleRouter::post('/admin/api/lovlynet/request', function() {
    $user = RouteHelper::requireAdmin();
    header('Content-Type: application/json');

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        apiError(
            'errors.admin.lovlynet.invalid_json',
            apiLocalizedText('errors.admin.lovlynet.invalid_json', 'Invalid request payload'),
            400,
            ['success' => false]
        );
    }

    $areaType = trim((string)($body['area_type'] ?? ''));
    $messageText = trim((string)($body['message_text'] ?? ''));

    if (!in_array($areaType, ['echo', 'file'], true)) {
        apiError(
            'errors.admin.lovlynet.invalid_area_type',
            apiLocalizedText('errors.admin.lovlynet.invalid_area_type', 'Invalid area type'),
            400,
            ['success' => false]
        );
    }

    if ($messageText === '') {
        apiError(
            'errors.admin.lovlynet.request_message_required',
            apiLocalizedText('errors.admin.lovlynet.request_message_required', 'Request message is required'),
            400,
            ['success' => false]
        );
    }

    $client = new \BinktermPHP\LovlyNetClient();
    if (!$client->isConfigured()) {
        apiError(
            'errors.admin.lovlynet.not_configured',
            apiLocalizedText('errors.admin.lovlynet.not_configured', 'LovlyNet is not configured'),
            400,
            ['success' => false]
        );
    }

    $hubAddress = trim($client->getHubAddress());
    $areafixPassword = trim($client->getAreafixPassword());
    if ($hubAddress === '' || $areafixPassword === '') {
        apiError(
            'errors.admin.lovlynet.request_config_missing',
            apiLocalizedText('errors.admin.lovlynet.request_config_missing', 'LovlyNet request settings are incomplete'),
            400,
            ['success' => false]
        );
    }

    $toName = $areaType === 'file' ? 'FileFix' : 'AreaFix';
    $fromUserId = (int)($user['user_id'] ?? $user['id'] ?? 0);
    $fromName = trim((string)($user['real_name'] ?? $user['username'] ?? ''));

    try {
        $messageHandler = new \BinktermPHP\MessageHandler();
        $messageHandler->sendNetmail(
            $fromUserId,
            $hubAddress,
            $toName,
            $areafixPassword,
            $messageText,
            $fromName !== '' ? $fromName : null
        );
    } catch (\Throwable $e) {
        apiError(
            'errors.admin.lovlynet.request_send_failed',
            apiLocalizedText('errors.admin.lovlynet.request_send_failed', 'Failed to send request netmail', $user),
            500,
            ['success' => false]
        );
    }

    echo json_encode(['success' => true]);
});

/**
 * GET /admin/api/lovlynet/help?type=echo|file
 * Proxy: fetch AreaFix/FileFix help text from LovlyNet.
 */
SimpleRouter::get('/admin/api/lovlynet/help', function() {
    $user = RouteHelper::requireAdmin();
    header('Content-Type: application/json');

    $type = trim((string)($_GET['type'] ?? ''));
    if (!in_array($type, ['echo', 'file'], true)) {
        apiError(
            'errors.admin.lovlynet.invalid_area_type',
            apiLocalizedText('errors.admin.lovlynet.invalid_area_type', 'Invalid area type', $user),
            400,
            ['success' => false]
        );
    }

    $client = new \BinktermPHP\LovlyNetClient();
    $result = $type === 'file' ? $client->getFileFixHelp() : $client->getAreaFixHelp();
    if (!$result['success']) {
        apiError(
            'errors.admin.lovlynet.help_fetch_failed',
            apiLocalizedText('errors.admin.lovlynet.help_fetch_failed', 'Failed to load help text', $user),
            502,
            ['success' => false]
        );
    }

    echo json_encode([
        'success' => true,
        'help' => $result['help'] ?? '',
    ]);
});

/**
 * POST /admin/api/lovlynet/echoarea-sync
 * Fetch the current LovlyNet description for a local LovlyNet echoarea.
 */
SimpleRouter::post('/admin/api/lovlynet/echoarea-sync', function() {
    $user = RouteHelper::requireAdmin();
    header('Content-Type: application/json');

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        apiError(
            'errors.admin.lovlynet.invalid_json',
            apiLocalizedText('errors.admin.lovlynet.invalid_json', 'Invalid request payload', $user),
            400,
            ['success' => false]
        );
    }

    $echoareaId = (int)($body['echoarea_id'] ?? 0);
    if ($echoareaId <= 0) {
        apiError(
            'errors.echoareas.not_found',
            apiLocalizedText('errors.echoareas.not_found', 'Echo area not found', $user),
            404,
            ['success' => false]
        );
    }

    $echoareaManager = new \BinktermPHP\EchoareaManager();
    $echoarea = $echoareaManager->getById($echoareaId);
    if (!$echoarea) {
        apiError(
            'errors.echoareas.not_found',
            apiLocalizedText('errors.echoareas.not_found', 'Echo area not found', $user),
            404,
            ['success' => false]
        );
    }

    if (strcasecmp((string)($echoarea['domain'] ?? ''), 'lovlynet') !== 0) {
        apiError(
            'errors.admin.lovlynet.invalid_area_type',
            apiLocalizedText('errors.admin.lovlynet.invalid_area_type', 'Invalid area type', $user),
            400,
            ['success' => false]
        );
    }

    $client = new \BinktermPHP\LovlyNetClient();
    $areasResult = $client->getAreas();
    if (!$areasResult['success']) {
        apiError(
            'errors.admin.lovlynet.help_fetch_failed',
            apiLocalizedText('errors.admin.lovlynet.help_fetch_failed', 'Failed to load help text', $user),
            502,
            ['success' => false]
        );
    }

    $remoteArea = null;
    foreach (($areasResult['echoareas'] ?? []) as $candidate) {
        if (strcasecmp(trim((string)($candidate['tag'] ?? '')), trim((string)($echoarea['tag'] ?? ''))) === 0) {
            $remoteArea = $candidate;
            break;
        }
    }

    if ($remoteArea === null) {
        apiError(
            'errors.echoareas.not_found',
            apiLocalizedText('errors.echoareas.not_found', 'Echo area not found', $user),
            404,
            ['success' => false]
        );
    }

    $description = trim((string)($remoteArea['description'] ?? ''));
    if ($description === '') {
        apiError(
            'errors.admin.lovlynet.request_send_failed',
            apiLocalizedText('errors.admin.lovlynet.request_send_failed', 'Failed to send request netmail', $user),
            502,
            ['success' => false]
        );
    }

    echo json_encode([
        'success' => true,
        'description' => $description,
        'message_code' => 'ui.echoareas.lovlynet_sync_success',
    ]);
});

/**
 * POST /admin/api/lovlynet/area-sync
 * Ensure a subscribed LovlyNet area exists locally and has the current description.
 */
SimpleRouter::post('/admin/api/lovlynet/area-sync', function() {
    $user = RouteHelper::requireAdmin();
    header('Content-Type: application/json');

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        apiError(
            'errors.admin.lovlynet.invalid_json',
            apiLocalizedText('errors.admin.lovlynet.invalid_json', 'Invalid request payload', $user),
            400,
            ['success' => false]
        );
    }

    $areaType = trim((string)($body['area_type'] ?? 'echo'));
    if (!in_array($areaType, ['echo', 'file'], true)) {
        apiError(
            'errors.admin.lovlynet.invalid_area_type',
            apiLocalizedText('errors.admin.lovlynet.invalid_area_type', 'Invalid area type', $user),
            400,
            ['success' => false]
        );
    }

    $areaTag = strtoupper(trim((string)($body['area_tag'] ?? '')));
    if ($areaTag === '') {
        apiError(
            $areaType === 'file' ? 'errors.fileareas.not_found' : 'errors.echoareas.not_found',
            apiLocalizedText($areaType === 'file' ? 'errors.fileareas.not_found' : 'errors.echoareas.not_found', $areaType === 'file' ? 'File area not found' : 'Echo area not found', $user),
            404,
            ['success' => false]
        );
    }

    $client = new \BinktermPHP\LovlyNetClient();
    $areasResult = $client->getAreas();
    if (!$areasResult['success']) {
        apiError(
            'errors.admin.lovlynet.help_fetch_failed',
            apiLocalizedText('errors.admin.lovlynet.help_fetch_failed', 'Failed to load help text', $user),
            502,
            ['success' => false]
        );
    }

    $remoteArea = null;
    $remoteAreas = $areaType === 'file' ? ($areasResult['fileareas'] ?? []) : ($areasResult['echoareas'] ?? []);
    foreach ($remoteAreas as $candidate) {
        if (strcasecmp(trim((string)($candidate['tag'] ?? '')), $areaTag) === 0) {
            $remoteArea = $candidate;
            break;
        }
    }

    if ($remoteArea === null) {
        apiError(
            $areaType === 'file' ? 'errors.fileareas.not_found' : 'errors.echoareas.not_found',
            apiLocalizedText($areaType === 'file' ? 'errors.fileareas.not_found' : 'errors.echoareas.not_found', $areaType === 'file' ? 'File area not found' : 'Echo area not found', $user),
            404,
            ['success' => false]
        );
    }

    $description = trim((string)($remoteArea['description'] ?? ''));
    if ($description === '') {
        apiError(
            $areaType === 'file' ? 'errors.fileareas.update_failed' : 'errors.echoareas.update_failed',
            apiLocalizedText($areaType === 'file' ? 'errors.fileareas.update_failed' : 'errors.echoareas.update_failed', $areaType === 'file' ? 'Failed to update file area' : 'Failed to update echo area', $user),
            500,
            ['success' => false]
        );
    }

    if ($areaType === 'file') {
        $fileAreaManager = new \BinktermPHP\FileAreaManager();
        $existingFileArea = $fileAreaManager->getFileAreaByTag((string)($remoteArea['tag'] ?? $areaTag), 'lovlynet');
        if ($existingFileArea) {
            $fileAreaId = (int)$existingFileArea['id'];
        } else {
            $metadata = isset($remoteArea['metadata']) && is_array($remoteArea['metadata'])
                ? $remoteArea['metadata'] : [];
            $uploadPermission = \BinktermPHP\FileAreaManager::getDefaultUploadPermissionForArea(
                $remoteArea['tag'] ?? $areaTag, 'lovlynet'
            );
            if (isset($metadata['readonly'])) {
                $v = filter_var($metadata['readonly'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($v !== null) {
                    $uploadPermission = $v
                        ? \BinktermPHP\FileAreaManager::UPLOAD_READ_ONLY
                        : \BinktermPHP\FileAreaManager::UPLOAD_USERS_ALLOWED;
                }
            }
            $replaceExisting = true;
            if (isset($metadata['replace'])) {
                $v = filter_var($metadata['replace'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($v !== null) {
                    $replaceExisting = $v;
                }
            }

            $fileAreaId = $fileAreaManager->createIfMissing([
                'tag'               => $remoteArea['tag'] ?? $areaTag,
                'description'       => $description,
                'domain'            => 'lovlynet',
                'is_local'          => false,
                'is_active'         => true,
                'upload_permission' => $uploadPermission,
                'replace_existing'  => $replaceExisting,
            ]);
        }

        if (!$fileAreaManager->updateDescription($fileAreaId, $description)) {
            apiError(
                'errors.fileareas.update_failed',
                apiLocalizedText('errors.fileareas.update_failed', 'Failed to update file area', $user),
                500,
                ['success' => false]
            );
        }

        $client->applyRecommendedSettings('file', array_merge($remoteArea, [
            'local_filearea_id' => $fileAreaId,
        ]));
    } else {
        $echoareaManager = new \BinktermPHP\EchoareaManager();
        $existingEchoarea = $echoareaManager->findByTagAndDomains((string)($remoteArea['tag'] ?? $areaTag), ['', 'lovlynet']);
        if ($existingEchoarea) {
            $echoareaId = (int)$existingEchoarea['id'];
        } else {
            $syncMetadata = isset($remoteArea['metadata']) && is_array($remoteArea['metadata'])
                ? $remoteArea['metadata'] : [];
            $isSysopOnly = false;
            if (isset($syncMetadata['sysop_only'])) {
                $v = filter_var($syncMetadata['sysop_only'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($v !== null) {
                    $isSysopOnly = $v;
                }
            }

            $echoareaId = $echoareaManager->createIfMissing([
                'tag'            => $remoteArea['tag'] ?? $areaTag,
                'description'    => $description,
                'domain'         => 'lovlynet',
                'uplink_address' => $client->getHubAddress(),
                'is_local'       => false,
                'is_active'      => true,
                'is_sysop_only'  => $isSysopOnly,
                'gemini_public'  => false,
            ], ['', 'lovlynet']);
        }

        if (!$echoareaManager->updateDescription($echoareaId, $description)) {
            apiError(
                'errors.echoareas.update_failed',
                apiLocalizedText('errors.echoareas.update_failed', 'Failed to update echo area', $user),
                500,
                ['success' => false]
            );
        }

        $client->applyRecommendedSettings('echo', array_merge($remoteArea, [
            'local_echoarea_id' => $echoareaId,
        ]));
    }

    echo json_encode([
        'success' => true,
        'description' => $description,
        'message_code' => 'ui.echoareas.lovlynet_sync_success',
    ]);
});

/**
 * GET /admin/api/lovlynet/requests
 * Return outbound AreaFix/FileFix requests and inbound responses for the admin.
 */
SimpleRouter::get('/admin/api/lovlynet/requests', function() {
    $user = RouteHelper::requireAdmin();
    header('Content-Type: application/json');

    $client = new \BinktermPHP\LovlyNetClient();
    if (!$client->isConfigured()) {
        apiError(
            'errors.admin.lovlynet.not_configured',
            apiLocalizedText('errors.admin.lovlynet.not_configured', 'LovlyNet is not configured', $user),
            400,
            ['success' => false]
        );
    }

    $hubAddress = trim($client->getHubAddress());
    if ($hubAddress === '') {
        apiError(
            'errors.admin.lovlynet.request_config_missing',
            apiLocalizedText('errors.admin.lovlynet.request_config_missing', 'LovlyNet request settings are incomplete', $user),
            400,
            ['success' => false]
        );
    }

    $messageHandler = new \BinktermPHP\MessageHandler();
    $requests = $messageHandler->getLovlyNetRequests((int)($user['user_id'] ?? $user['id'] ?? 0), $hubAddress);

    echo json_encode([
        'success' => true,
        'requests' => $requests,
    ]);
});

/**
 * GET /admin/api/lovlynet/filearea-files?tag=TAG
 * Fetch the list of files available in a LovlyNet file area (parsed from files.bbs).
 */
SimpleRouter::get('/admin/api/lovlynet/filearea-files', function() {
    $user = RouteHelper::requireAdmin();
    header('Content-Type: application/json');

    $areaTag = trim((string)($_GET['tag'] ?? ''));
    if ($areaTag === '') {
        apiError(
            'errors.admin.lovlynet.invalid_area_type',
            apiLocalizedText('errors.admin.lovlynet.invalid_area_type', 'Area tag is required', $user),
            400,
            ['success' => false]
        );
    }

    $client = new \BinktermPHP\LovlyNetClient();
    if (!$client->isConfigured()) {
        apiError(
            'errors.admin.lovlynet.not_configured',
            apiLocalizedText('errors.admin.lovlynet.not_configured', 'LovlyNet is not configured', $user),
            400,
            ['success' => false]
        );
    }

    $result = $client->getFileAreaFiles($areaTag);
    if (!$result['success']) {
        apiError(
            'errors.admin.lovlynet.filearea_files_failed',
            apiLocalizedText('errors.admin.lovlynet.filearea_files_failed', 'Failed to load file area files', $user),
            502,
            ['success' => false]
        );
    }

    // Build a set of locally held filenames (case-insensitive) and collect full local file records.
    $localFilenames = [];  // lowercase filename => true, for local_exists check
    $allLocalFiles  = [];  // full records: id, filename, description
    try {
        $db   = \BinktermPHP\Database::getInstance()->getPdo();
        $stmt = $db->prepare("
            SELECT f.id, f.filename, f.short_description
            FROM files f
            JOIN file_areas fa ON f.file_area_id = fa.id
            WHERE LOWER(fa.tag) = LOWER(?)
              AND f.status = 'approved'
            ORDER BY f.filename
        ");
        $stmt->execute([$areaTag]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $localFilenames[strtolower($row['filename'])] = true;
            $allLocalFiles[] = [
                'id'          => (int)$row['id'],
                'filename'    => $row['filename'],
                'description' => $row['short_description'] ?? '',
            ];
        }
    } catch (\Throwable $e) {
        // Non-fatal — local_exists will default to false
    }

    $files = array_map(function (array $file) use ($localFilenames): array {
        $file['local_exists'] = isset($localFilenames[strtolower($file['filename'] ?? '')]);
        return $file;
    }, $result['files']);

    // Local-only files: present locally but not in the LovlyNet remote list.
    $remoteFilenamesLower = array_map(fn($f) => strtolower($f['filename'] ?? ''), $result['files']);
    $localOnlyFiles = array_values(array_filter($allLocalFiles, function (array $lf) use ($remoteFilenamesLower): bool {
        return !in_array(strtolower($lf['filename']), $remoteFilenamesLower, true);
    }));

    echo json_encode([
        'success'          => true,
        'files'            => $files,
        'local_only_files' => $localOnlyFiles,
    ]);
});

/**
 * POST /admin/api/lovlynet/hatch-file
 * Hatch a local file to LovlyNet uplinks via the admin daemon.
 */
SimpleRouter::post('/admin/api/lovlynet/hatch-file', function() {
    $user = RouteHelper::requireAdmin();
    header('Content-Type: application/json');

    $data   = json_decode(file_get_contents('php://input'), true) ?? [];
    $fileId = (int)($data['file_id'] ?? 0);
    if ($fileId <= 0) {
        apiError(
            'errors.admin.lovlynet.invalid_file_id',
            apiLocalizedText('errors.admin.lovlynet.invalid_file_id', 'Invalid file ID', $user),
            400,
            ['success' => false]
        );
    }

    $daemon = new \BinktermPHP\Admin\AdminDaemonClient();
    $result = $daemon->rehatchFile($fileId);
    if (!($result['ok'] ?? false)) {
        apiError(
            'errors.admin.lovlynet.hatch_failed',
            apiLocalizedText('errors.admin.lovlynet.hatch_failed', 'Failed to hatch file', $user),
            500,
            ['success' => false]
        );
    }

    echo json_encode(['success' => true]);
});

/**
 * GET /admin/api/lovlynet/registration
 * Return the local LovlyNet registration status and BinkpConfig defaults for the update form.
 */
SimpleRouter::get('/admin/api/lovlynet/registration', function() {
    RouteHelper::requireAdmin();
    header('Content-Type: application/json');

    $client = new \BinktermPHP\LovlyNetClient();
    $status = $client->getRegistrationStatus();

    if (!$status['success']) {
        http_response_code(404);
        echo json_encode([
            'error_code' => 'errors.admin.lovlynet.not_registered',
            'error'      => $status['error'],
        ]);
        return;
    }

    // Try to fetch current values from the LovlyNet server; fall back to local BinkpConfig
    $remote = $client->getRemoteRegistration();
    if ($remote['success']) {
        $defaults = [
            'system_name' => $remote['system_name'],
            'sysop_name'  => $remote['sysop_name'],
            'hostname'    => $remote['hostname'],
            'binkp_port'  => $remote['binkp_port'],
            'site_url'    => $remote['site_url'],
        ];
        $isPassive = $remote['is_passive'];
    } else {
        $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
        $defaults = [
            'system_name' => $binkpConfig->getSystemName(),
            'sysop_name'  => $binkpConfig->getSystemSysop(),
            'hostname'    => $binkpConfig->getSystemHostname(),
            'binkp_port'  => $binkpConfig->getBinkpPort(),
            'site_url'    => \BinktermPHP\Config::getSiteUrl(),
        ];
        $isPassive = $status['is_passive'];
    }

    echo json_encode([
        'ftn_address'   => $status['ftn_address'],
        'hub_address'   => $status['hub_address'],
        'registered_at' => $status['registered_at'],
        'updated_at'    => $status['updated_at'],
        'is_passive'    => $isPassive,
        'defaults'      => $defaults,
    ]);
});

/**
 * POST /admin/api/lovlynet/update-registration
 * Update this node's registration with the LovlyNet registry.
 *
 * Body: { "system_name": "...", "sysop_name": "...", "hostname": "...",
 *         "binkp_port": 24554, "site_url": "...", "is_passive": false }
 */
SimpleRouter::post('/admin/api/lovlynet/update-registration', function() {
    $user = RouteHelper::requireAdmin();
    header('Content-Type: application/json');

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        apiError(
            'errors.admin.lovlynet.invalid_json',
            apiLocalizedText('errors.admin.lovlynet.invalid_json', 'Invalid request payload', $user),
            400
        );
        return;
    }

    $systemName = trim((string)($data['system_name'] ?? ''));
    $sysopName  = trim((string)($data['sysop_name']  ?? ''));
    $hostname   = trim((string)($data['hostname']    ?? ''));
    $siteUrl    = trim((string)($data['site_url']    ?? ''));
    $binkpPort  = (int)($data['binkp_port'] ?? 0);
    $isPassive  = (bool)($data['is_passive'] ?? false);

    if ($systemName === '' || $sysopName === '' || $hostname === '') {
        apiError(
            'errors.admin.lovlynet.registration_update_failed',
            apiLocalizedText('errors.admin.lovlynet.registration_update_failed', 'Registration update failed', $user),
            400
        );
        return;
    }

    $client = new \BinktermPHP\LovlyNetClient();
    $result = $client->updateRegistration([
        'system_name' => $systemName,
        'sysop_name'  => $sysopName,
        'hostname'    => $hostname,
        'binkp_port'  => $binkpPort,
        'site_url'    => $siteUrl,
        'is_passive'  => $isPassive,
    ]);

    if (!$result['success']) {
        apiError(
            'errors.admin.lovlynet.registration_update_failed',
            $result['error'] ?? apiLocalizedText('errors.admin.lovlynet.registration_update_failed', 'Registration update failed', $user),
            502
        );
        return;
    }

    $regData = $result['data']['data'] ?? $result['data'] ?? [];
    $regData['is_passive'] = $isPassive;
    if (!$client->saveRegistrationUpdate($regData)) {
        error_log('LovlyNet: saveRegistrationUpdate failed to write config/lovlynet.json');
    }

    echo json_encode(['success' => true]);
});

/**
 * GET /admin/api/lovlynet/checklist
 * Return the status of LovlyNet setup checklist items.
 */
SimpleRouter::get('/admin/api/lovlynet/checklist', function() {
    RouteHelper::requireAdmin();
    header('Content-Type: application/json');

    $recommendedPattern = '/^LOVLYNET\\.(Z|A|L|R|J)[0-9]{2}$/i';
    $areaKey = 'LVLY_NODELIST@LOVLYNET';

    $ruleExists = false;
    $patternMatches = false;
    $nodelistRuleDaemonError = false;

    try {
        $daemonClient = new \BinktermPHP\Admin\AdminDaemonClient();
        $rulesConfig = $daemonClient->getFileAreaRulesConfig();
        $configJson = $rulesConfig['config_json'] ?? null;

        if ($configJson !== null) {
            $parsed = json_decode($configJson, true);
            if (is_array($parsed)) {
                $areaRules = $parsed['area_rules'][$areaKey]
                    ?? $parsed['area_rules']['LVLY_NODELIST']
                    ?? [];
                foreach ($areaRules as $rule) {
                    if (isset($rule['pattern'])) {
                        $ruleExists = true;
                        if ($rule['pattern'] === $recommendedPattern) {
                            $patternMatches = true;
                            break;
                        }
                    }
                }
            }
        }
    } catch (\Exception $e) {
        error_log('LovlyNet checklist: failed to load file area rules: ' . $e->getMessage());
        $nodelistRuleDaemonError = true;
    }

    // Check default area subscriptions using is_default flag from areas response
    $defaultAreasItem = ['id' => 'default_areas', 'ok' => true, 'unsubscribed_echo' => [], 'unsubscribed_file' => [], 'fetch_error' => false];

    $lovlyClient = new \BinktermPHP\LovlyNetClient();
    $areasResult = $lovlyClient->getAreas();

    if (!$areasResult['success']) {
        $defaultAreasItem['fetch_error'] = true;
    } else {
        $missingEcho = [];
        foreach ($areasResult['echoareas'] as $area) {
            if (!empty($area['is_default']) && empty($area['subscribed'])) {
                $missingEcho[] = strtoupper((string)($area['tag'] ?? ''));
            }
        }
        $missingFile = [];
        foreach ($areasResult['fileareas'] as $area) {
            if (!empty($area['is_default']) && empty($area['subscribed'])) {
                $missingFile[] = strtoupper((string)($area['tag'] ?? ''));
            }
        }

        $defaultAreasItem['unsubscribed_echo'] = $missingEcho;
        $defaultAreasItem['unsubscribed_file'] = $missingFile;
        $defaultAreasItem['ok'] = ($missingEcho === [] && $missingFile === []);
    }

    // Check whether a LovlyNet uplink is configured in binkp.json
    $uplinkConfigured = false;
    try {
        $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
        $lovlyUplink = $binkpConfig->getUplinkByDomain('lovlynet');
        $uplinkConfigured = $lovlyUplink !== null
            && !empty($lovlyUplink['me'])
            && !empty($lovlyUplink['address'])
            && !empty($lovlyUplink['networks'])
            && ($lovlyUplink['enabled'] ?? true);
    } catch (\Exception $e) {
        // leave as false
    }

    // Perform a binkp authentication test: connect to the LovlyNet hub and authenticate.
    // sendCommand() returns $response['result'] directly on success and throws
    // RuntimeException on failure. Connection failures begin with "Failed to connect
    // to admin daemon"; binkp errors begin with "Admin daemon error: ".
    $binkpAuthOk      = false;
    $binkpAuthError   = null;
    $binkpAuthMethod  = null;
    $binkpDaemonError = false;
    try {
        $daemonClient2   = new \BinktermPHP\Admin\AdminDaemonClient();
        $binkpAuthResult = $daemonClient2->binkpAuthTest('lovlynet');
        $binkpAuthOk     = true;
        $binkpAuthMethod = $binkpAuthResult['auth_method'] ?? null;
    } catch (\RuntimeException $e) {
        $msg = $e->getMessage();
        if (str_starts_with($msg, 'Failed to connect to admin daemon')) {
            $binkpDaemonError = true;
        } else {
            // Strip "Admin daemon error: " prefix to surface the real message
            $binkpAuthError = (string)preg_replace('/^Admin daemon error:\s*/', '', $msg);
        }
    }

    echo json_encode([
        'success' => true,
        'items' => [
            [
                'id' => 'registration',
                'ok' => true,
            ],
            [
                'id' => 'uplink_configured',
                'ok' => $uplinkConfigured,
            ],
            [
                'id'           => 'binkp_auth_test',
                'ok'           => $binkpAuthOk,
                'auth_method'  => $binkpAuthMethod,
                'error'        => $binkpAuthError,
                'daemon_error' => $binkpDaemonError,
            ],
            [
                'id'              => 'nodelist_rule',
                'ok'              => !$nodelistRuleDaemonError && $ruleExists && $patternMatches,
                'has_rule'        => $ruleExists,
                'pattern_matches' => $patternMatches,
                'daemon_error'    => $nodelistRuleDaemonError,
            ],
            $defaultAreasItem,
        ],
    ]);
});

/**
 * GET /admin/api/lovlynet/checklist/{id}
 * Run a single LovlyNet setup checklist item and return its result.
 * Accepted IDs: registration, uplink_configured, binkp_auth_test, nodelist_rule, default_areas
 */
SimpleRouter::get('/admin/api/lovlynet/checklist/{id}', function(string $id) {
    RouteHelper::requireAdmin();
    header('Content-Type: application/json');

    $recommendedPattern = '/^LOVLYNET\\.(Z|A|L|R|J)[0-9]{2}$/i';
    $areaKey = 'LVLY_NODELIST@LOVLYNET';

    switch ($id) {
        case 'registration':
            echo json_encode(['success' => true, 'item' => ['id' => 'registration', 'ok' => true]]);
            break;

        case 'uplink_configured':
            $uplinkConfigured = false;
            try {
                $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
                $lovlyUplink = $binkpConfig->getUplinkByDomain('lovlynet');
                $uplinkConfigured = $lovlyUplink !== null
                    && !empty($lovlyUplink['me'])
                    && !empty($lovlyUplink['address'])
                    && !empty($lovlyUplink['networks'])
                    && ($lovlyUplink['enabled'] ?? true);
            } catch (\Exception $e) {
                // leave as false
            }
            echo json_encode(['success' => true, 'item' => ['id' => 'uplink_configured', 'ok' => $uplinkConfigured]]);
            break;

        case 'binkp_auth_test':
            $binkpAuthOk      = false;
            $binkpAuthError   = null;
            $binkpAuthMethod  = null;
            $binkpDaemonError = false;
            try {
                $daemonClient    = new \BinktermPHP\Admin\AdminDaemonClient();
                $binkpAuthResult = $daemonClient->binkpAuthTest('lovlynet');
                $binkpAuthOk     = true;
                $binkpAuthMethod = $binkpAuthResult['auth_method'] ?? null;
            } catch (\RuntimeException $e) {
                $msg = $e->getMessage();
                if (str_starts_with($msg, 'Failed to connect to admin daemon')) {
                    $binkpDaemonError = true;
                } else {
                    $binkpAuthError = (string)preg_replace('/^Admin daemon error:\s*/', '', $msg);
                }
            }
            echo json_encode(['success' => true, 'item' => [
                'id'           => 'binkp_auth_test',
                'ok'           => $binkpAuthOk,
                'auth_method'  => $binkpAuthMethod,
                'error'        => $binkpAuthError,
                'daemon_error' => $binkpDaemonError,
            ]]);
            break;

        case 'nodelist_rule':
            $ruleExists             = false;
            $patternMatches         = false;
            $nodelistRuleDaemonError = false;
            try {
                $daemonClient = new \BinktermPHP\Admin\AdminDaemonClient();
                $rulesConfig  = $daemonClient->getFileAreaRulesConfig();
                $configJson   = $rulesConfig['config_json'] ?? null;
                if ($configJson !== null) {
                    $parsed = json_decode($configJson, true);
                    if (is_array($parsed)) {
                        $areaRules = $parsed['area_rules'][$areaKey]
                            ?? $parsed['area_rules']['LVLY_NODELIST']
                            ?? [];
                        foreach ($areaRules as $rule) {
                            if (isset($rule['pattern'])) {
                                $ruleExists = true;
                                if ($rule['pattern'] === $recommendedPattern) {
                                    $patternMatches = true;
                                    break;
                                }
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                error_log('LovlyNet checklist: failed to load file area rules: ' . $e->getMessage());
                $nodelistRuleDaemonError = true;
            }
            echo json_encode(['success' => true, 'item' => [
                'id'              => 'nodelist_rule',
                'ok'              => !$nodelistRuleDaemonError && $ruleExists && $patternMatches,
                'has_rule'        => $ruleExists,
                'pattern_matches' => $patternMatches,
                'daemon_error'    => $nodelistRuleDaemonError,
            ]]);
            break;

        case 'default_areas':
            $defaultAreasItem = ['id' => 'default_areas', 'ok' => true, 'unsubscribed_echo' => [], 'unsubscribed_file' => [], 'fetch_error' => false];
            $lovlyClient  = new \BinktermPHP\LovlyNetClient();
            $areasResult  = $lovlyClient->getAreas();
            if (!$areasResult['success']) {
                $defaultAreasItem['fetch_error'] = true;
            } else {
                $missingEcho = [];
                foreach ($areasResult['echoareas'] as $area) {
                    if (!empty($area['is_default']) && empty($area['subscribed'])) {
                        $missingEcho[] = strtoupper((string)($area['tag'] ?? ''));
                    }
                }
                $missingFile = [];
                foreach ($areasResult['fileareas'] as $area) {
                    if (!empty($area['is_default']) && empty($area['subscribed'])) {
                        $missingFile[] = strtoupper((string)($area['tag'] ?? ''));
                    }
                }
                $defaultAreasItem['unsubscribed_echo'] = $missingEcho;
                $defaultAreasItem['unsubscribed_file'] = $missingFile;
                $defaultAreasItem['ok'] = ($missingEcho === [] && $missingFile === []);
            }
            echo json_encode(['success' => true, 'item' => $defaultAreasItem]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'unknown_item']);
            break;
    }
});

/**
 * POST /admin/api/lovlynet/checklist/fix-nodelist-rule
 * Add/fix the LVLY_NODELIST file area rule with the recommended pattern and script.
 */
SimpleRouter::post('/admin/api/lovlynet/checklist/fix-nodelist-rule', function() {
    RouteHelper::requireAdmin();
    header('Content-Type: application/json');

    $areaKey = 'LVLY_NODELIST@LOVLYNET';
    $newRule = [
        'name'           => 'Import LovlyNet Nodelist',
        'domain'         => 'lovlynet',
        'pattern'        => '/^LOVLYNET\\.(Z|A|L|R|J)[0-9]{2}$/i',
        'script'         => 'php %basedir%/scripts/import_nodelist.php %filepath% %domain% --force',
        'success_action' => 'keep',
        'fail_action'    => 'keep+notify',
        'enabled'        => true,
        'timeout'        => 300,
    ];

    try {
        $daemonClient = new \BinktermPHP\Admin\AdminDaemonClient();
        $rulesConfig = $daemonClient->getFileAreaRulesConfig();
        $configJson = $rulesConfig['config_json'] ?? null;

        $parsed = is_string($configJson) ? json_decode($configJson, true) : null;
        if (!is_array($parsed)) {
            $parsed = ['global_rules' => [], 'area_rules' => []];
        }
        if (!isset($parsed['area_rules']) || !is_array($parsed['area_rules'])) {
            $parsed['area_rules'] = [];
        }

        // Keep any existing rules for this area that have a different pattern,
        // then append the canonical rule.
        $existing = $parsed['area_rules'][$areaKey] ?? [];
        $filtered = array_values(array_filter($existing, static function ($rule) use ($newRule) {
            return ($rule['pattern'] ?? '') !== $newRule['pattern'];
        }));
        $filtered[] = $newRule;
        $parsed['area_rules'][$areaKey] = $filtered;

        $json = json_encode($parsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('json_encode failed: ' . json_last_error_msg());
        }
        $daemonClient->saveFileAreaRulesConfig($json);

        echo json_encode(['success' => true]);
    } catch (\Exception $e) {
        error_log('LovlyNet checklist fix-nodelist-rule failed: ' . $e->getMessage());
        http_response_code(500);
        apiError('errors.admin.lovlynet.checklist_fix_failed', $e->getMessage());
    }
});

/**
 * GET /admin/api/zip-diag?id=FILE_ID&path=ENTRY_PATH
 * Diagnostic: test whether a ZIP entry can be extracted via ZipArchive or unzip.
 */
SimpleRouter::get('/admin/api/zip-diag', function() {
    RouteHelper::requireAdmin();
    header('Content-Type: application/json');

    $id        = (int)($_GET['id']   ?? 0);
    $entryPath = $_GET['path'] ?? '';

    if (!$id || $entryPath === '') {
        echo json_encode(['error' => 'id and path are required']);
        return;
    }

    $manager     = new \BinktermPHP\FileAreaManager();
    $file        = $manager->getFileById($id);
    $storagePath = $file ? $manager->resolveFilePath($file) : null;

    if (!$storagePath || !file_exists($storagePath)) {
        http_response_code(404);
        echo json_encode(['error' => 'File not found']);
        return;
    }

    $entryPath = str_replace('\\', '/', $entryPath);
    $result    = ['entry' => $entryPath, 'ziparchive' => null, 'unzip_available' => null, 'unzip_result' => null];

    // Test ZipArchive
    $zip = new ZipArchive();
    if ($zip->open($storagePath) === true) {
        $exactName   = null;
        $compMethod  = null;
        $lowerTarget = strtolower($entryPath);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat && strtolower(str_replace('\\', '/', $stat['name'])) === $lowerTarget) {
                $exactName  = $stat['name'];
                $compMethod = $stat['comp_method'];
                $content    = $zip->getFromIndex($i);
                $result['ziparchive'] = [
                    'found'       => true,
                    'exact_name'  => $exactName,
                    'comp_method' => $compMethod,
                    'extracted'   => $content !== false,
                    'bytes'       => $content !== false ? strlen($content) : 0,
                ];
                break;
            }
        }
        if ($exactName === null) {
            $result['ziparchive'] = ['found' => false];
        }
        $zip->close();

        // Test available extraction tools
        $isWindows = PHP_OS_FAMILY === 'Windows';
        $null      = $isWindows ? 'NUL' : '/dev/null';
        $whichCmd  = $isWindows ? 'where' : 'which';
        // On Windows check both "unzip" and "unzip.exe" since where.exe may
        // not find extensionless names depending on PATHEXT configuration.
        $bins = $isWindows ? ['unzip', 'unzip.exe', '7z', '7za'] : ['unzip', '7z', '7za'];
        $tools = [];
        foreach ($bins as $bin) {
            $path = trim((string)@shell_exec("$whichCmd $bin 2>$null"));
            $tools[$bin] = $path !== '' ? $path : null;
        }
        $result['tools'] = $tools;

        if ($exactName !== null) {
            $zipArg  = escapeshellarg($storagePath);
            $nameArg = escapeshellarg($exactName);
            $tried   = [];
            foreach ([
                'unzip'     => "unzip -p $zipArg $nameArg 2>$null",
                'unzip.exe' => "unzip.exe -p $zipArg $nameArg 2>$null",
                '7z'        => "7z e -so $zipArg $nameArg 2>$null",
                '7za'       => "7za e -so $zipArg $nameArg 2>$null",
            ] as $tool => $cmd) {
                $out = @shell_exec($cmd);
                $tried[$tool] = [
                    'success' => $out !== null && $out !== '',
                    'bytes'   => $out !== null ? strlen($out) : 0,
                ];
                if ($out !== null && $out !== '') break;
            }
            $result['extraction_attempts'] = $tried;
        }
    } else {
        $result['ziparchive'] = ['error' => 'Cannot open ZIP'];
    }

    echo json_encode($result, JSON_PRETTY_PRINT);
});

// ---------------------------------------------------------------------------
// Interests admin page
// ---------------------------------------------------------------------------

SimpleRouter::group(['prefix' => '/admin'], function() {

    SimpleRouter::get('/interests', function() {
        RouteHelper::requireAdmin();
        if (\BinktermPHP\Config::env('ENABLE_INTERESTS', 'true') !== 'true') {
            http_response_code(404);
            return;
        }
        $template = new Template();
        $template->renderResponse('admin/interests.twig', [
            'ai_available' => !empty(\BinktermPHP\AI\AiService::create()->getConfiguredProviders()),
        ]);
    });

});

// ---------------------------------------------------------------------------
// Interests admin API
// ---------------------------------------------------------------------------

SimpleRouter::group(['prefix' => '/api/admin'], function() {

    /** List all interests (including inactive). */
    SimpleRouter::get('/interests', function() {
        RouteHelper::requireAdmin();
        if (\BinktermPHP\Config::env('ENABLE_INTERESTS', 'true') !== 'true') {
            http_response_code(404);
            return;
        }
        $manager = new \BinktermPHP\InterestManager();
        echo json_encode($manager->getInterests(false));
    });

    /** List echo areas not assigned to any interest. */
    SimpleRouter::get('/interests/unassigned-echoareas', function() {
        RouteHelper::requireAdmin();
        if (\BinktermPHP\Config::env('ENABLE_INTERESTS', 'true') !== 'true') {
            http_response_code(404);
            return;
        }
        header('Content-Type: application/json');
        $db = \BinktermPHP\Database::getInstance()->getPdo();
        $stmt = $db->query("
            SELECT e.id, e.tag, e.domain, e.description, e.is_active
            FROM echoareas e
            WHERE e.id NOT IN (SELECT echoarea_id FROM interest_echoareas)
            ORDER BY e.tag
        ");
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id']        = (int)$row['id'];
            $row['is_active'] = (bool)$row['is_active'];
        }
        unset($row);
        echo json_encode(['areas' => $rows, 'count' => count($rows)]);
    });

    /** Get a single interest with its area lists. */
    SimpleRouter::get('/interests/{id}', function($id) {
        RouteHelper::requireAdmin();
        if (\BinktermPHP\Config::env('ENABLE_INTERESTS', 'true') !== 'true') {
            http_response_code(404);
            return;
        }
        $manager = new \BinktermPHP\InterestManager();
        $interest = $manager->getInterest((int)$id);
        if (!$interest) {
            apiError('errors.interests.not_found', apiLocalizedText('errors.interests.not_found', 'Interest not found.'), 404);
            return;
        }
        echo json_encode($interest);
    });

    /** Create a new interest. */
    SimpleRouter::post('/interests', function() {
        RouteHelper::requireAdmin();
        if (\BinktermPHP\Config::env('ENABLE_INTERESTS', 'true') !== 'true') {
            http_response_code(404);
            return;
        }
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty(trim((string)($data['name'] ?? '')))) {
            apiError('errors.interests.name_required', apiLocalizedText('errors.interests.name_required', 'Interest name is required.'), 400);
            return;
        }
        try {
            $manager = new \BinktermPHP\InterestManager();
            $id = $manager->createInterest($data);
            http_response_code(201);
            echo json_encode(['success' => true, 'id' => $id]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'interests_name_key') || str_contains($e->getMessage(), 'unique constraint')) {
                apiError('errors.interests.name_taken', apiLocalizedText('errors.interests.name_taken', 'An interest with that name already exists.'), 409);
            } elseif (str_contains($e->getMessage(), 'interests_slug_key')) {
                apiError('errors.interests.slug_taken', apiLocalizedText('errors.interests.slug_taken', 'An interest with that slug already exists.'), 409);
            } else {
                throw $e;
            }
        }
    });

    /** Update an interest's metadata. */
    SimpleRouter::put('/interests/{id}', function($id) {
        RouteHelper::requireAdmin();
        if (\BinktermPHP\Config::env('ENABLE_INTERESTS', 'true') !== 'true') {
            http_response_code(404);
            return;
        }
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $manager = new \BinktermPHP\InterestManager();
        $interest = $manager->getInterest((int)$id);
        if (!$interest) {
            apiError('errors.interests.not_found', apiLocalizedText('errors.interests.not_found', 'Interest not found.'), 404);
            return;
        }
        try {
            $manager->updateInterest((int)$id, $data);
            echo json_encode(['success' => true]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'interests_name_key')) {
                apiError('errors.interests.name_taken', apiLocalizedText('errors.interests.name_taken', 'An interest with that name already exists.'), 409);
            } elseif (str_contains($e->getMessage(), 'interests_slug_key')) {
                apiError('errors.interests.slug_taken', apiLocalizedText('errors.interests.slug_taken', 'An interest with that slug already exists.'), 409);
            } else {
                throw $e;
            }
        }
    });

    /** Delete an interest. */
    SimpleRouter::delete('/interests/{id}', function($id) {
        RouteHelper::requireAdmin();
        if (\BinktermPHP\Config::env('ENABLE_INTERESTS', 'true') !== 'true') {
            http_response_code(404);
            return;
        }
        $manager = new \BinktermPHP\InterestManager();
        if (!$manager->deleteInterest((int)$id)) {
            apiError('errors.interests.not_found', apiLocalizedText('errors.interests.not_found', 'Interest not found.'), 404);
            return;
        }
        echo json_encode(['success' => true]);
    });

    /** Set echo areas for an interest (replaces current list). */
    SimpleRouter::post('/interests/{id}/echoareas', function($id) {
        RouteHelper::requireAdmin();
        if (\BinktermPHP\Config::env('ENABLE_INTERESTS', 'true') !== 'true') {
            http_response_code(404);
            return;
        }
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $ids = array_map('intval', (array)($data['ids'] ?? []));
        $manager = new \BinktermPHP\InterestManager();
        if (!$manager->getInterest((int)$id)) {
            apiError('errors.interests.not_found', apiLocalizedText('errors.interests.not_found', 'Interest not found.'), 404);
            return;
        }
        $manager->setEchoareas((int)$id, $ids);
        echo json_encode(['success' => true]);
    });

    /** Set file areas for an interest (replaces current list). */
    SimpleRouter::post('/interests/{id}/fileareas', function($id) {
        RouteHelper::requireAdmin();
        if (\BinktermPHP\Config::env('ENABLE_INTERESTS', 'true') !== 'true') {
            http_response_code(404);
            return;
        }
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $ids = array_map('intval', (array)($data['ids'] ?? []));
        $manager = new \BinktermPHP\InterestManager();
        if (!$manager->getInterest((int)$id)) {
            apiError('errors.interests.not_found', apiLocalizedText('errors.interests.not_found', 'Interest not found.'), 404);
            return;
        }
        $manager->setFileareas((int)$id, $ids);
        echo json_encode(['success' => true]);
    });

    /** Echo areas not assigned to any interest. */
    /**
     * Generate interest suggestions via keyword heuristics and optionally AI.
     * Does NOT create any interests — returns suggestions for admin review only.
     */
    SimpleRouter::post('/interests/generate', function() {
        RouteHelper::requireAdmin();
        if (\BinktermPHP\Config::env('ENABLE_INTERESTS', 'true') !== 'true') {
            http_response_code(404);
            return;
        }
        $data        = json_decode(file_get_contents('php://input'), true) ?? [];
        $useAi       = (bool)($data['use_ai'] ?? true);
        $useKeywords = (bool)($data['use_keywords'] ?? true);
        $result = (new \BinktermPHP\InterestGenerator())->generate($useAi, $useKeywords);
        echo json_encode($result);
    });

    /** Keyword-classify a single echo area (fast, no AI). */
    SimpleRouter::get('/interests/echoarea/{id}/classify', function($id) {
        RouteHelper::requireAdmin();
        if (\BinktermPHP\Config::env('ENABLE_INTERESTS', 'true') !== 'true') {
            http_response_code(404);
            return;
        }
        header('Content-Type: application/json');
        try {
            $result = (new \BinktermPHP\InterestGenerator())->classifyOne((int)$id, false);
            echo json_encode($result);
        } catch (\RuntimeException $e) {
            http_response_code(404);
            echo json_encode(['error' => $e->getMessage()]);
        }
    });

    /** AI-classify a single echo area (slower, uses configured AI provider). */
    SimpleRouter::post('/interests/echoarea/{id}/classify-ai', function($id) {
        RouteHelper::requireAdmin();
        if (\BinktermPHP\Config::env('ENABLE_INTERESTS', 'true') !== 'true') {
            http_response_code(404);
            return;
        }
        header('Content-Type: application/json');
        try {
            $result = (new \BinktermPHP\InterestGenerator())->classifyOne((int)$id, true);
            echo json_encode($result);
        } catch (\RuntimeException $e) {
            http_response_code(404);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    });

    /** Add a single echo area to an interest (non-destructive). */
    SimpleRouter::post('/interests/{id}/echoareas/add', function($id) {
        RouteHelper::requireAdmin();
        if (\BinktermPHP\Config::env('ENABLE_INTERESTS', 'true') !== 'true') {
            http_response_code(404);
            return;
        }
        header('Content-Type: application/json');
        $data       = json_decode(file_get_contents('php://input'), true) ?? [];
        $echoareaId = (int)($data['echoarea_id'] ?? 0);
        if (!$echoareaId) {
            http_response_code(400);
            echo json_encode(['error' => 'echoarea_id required']);
            return;
        }
        $manager  = new \BinktermPHP\InterestManager();
        $interest = $manager->getInterest((int)$id);
        if (!$interest) {
            http_response_code(404);
            echo json_encode(['error' => 'Interest not found']);
            return;
        }
        $manager->addEchoarea((int)$id, $echoareaId);
        echo json_encode(['ok' => true]);
    });

});

// ============================================================
// AreaFix / FileFix Manager
// ============================================================

/**
 * GET /admin/areafix
 * AreaFix / FileFix manager admin page.
 */
SimpleRouter::get('/admin/areafix', function () {
    $user = RouteHelper::requireAdmin();

    $areafixManager = new \BinktermPHP\AreaFixManager();
    $uplinks = $areafixManager->getConfiguredUplinks();

    $template = new Template();
    $template->renderResponse('admin/areafix.twig', [
        'uplinks'                => $uplinks,
        'has_configured_uplinks' => !empty($uplinks),
    ]);
});

/**
 * GET /api/admin/areafix/uplinks
 * Return uplinks that have areafix or filefix passwords configured.
 */
SimpleRouter::get('/api/admin/areafix/uplinks', function () {
    $user = RouteHelper::requireAdmin();
    header('Content-Type: application/json');

    $areafixManager = new \BinktermPHP\AreaFixManager();
    $uplinks = $areafixManager->getConfiguredUplinks();

    echo json_encode(['success' => true, 'uplinks' => $uplinks]);
});

/**
 * POST /api/admin/areafix/send
 * Send AreaFix or FileFix commands to a hub uplink.
 * Body: { uplink: string, robot: "areafix"|"filefix", commands: string[] }
 */
SimpleRouter::post('/api/admin/areafix/send', function () {
    $user = RouteHelper::requireAdmin();
    header('Content-Type: application/json');

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        apiError(
            'errors.admin.areafix.invalid_json',
            apiLocalizedText('errors.admin.areafix.invalid_json', 'Invalid request payload', $user),
            400,
            ['success' => false]
        );
    }

    $uplinkAddress = trim((string)($body['uplink'] ?? ''));
    $robot = strtolower(trim((string)($body['robot'] ?? '')));
    $commands = $body['commands'] ?? [];

    if ($uplinkAddress === '') {
        apiError(
            'errors.admin.areafix.uplink_required',
            apiLocalizedText('errors.admin.areafix.uplink_required', 'Uplink address is required', $user),
            400,
            ['success' => false]
        );
    }

    if (!in_array($robot, ['areafix', 'filefix'], true)) {
        apiError(
            'errors.admin.areafix.invalid_robot',
            apiLocalizedText('errors.admin.areafix.invalid_robot', 'Robot must be "areafix" or "filefix"', $user),
            400,
            ['success' => false]
        );
    }

    if (empty($commands) || !is_array($commands)) {
        apiError(
            'errors.admin.areafix.commands_required',
            apiLocalizedText('errors.admin.areafix.commands_required', 'At least one command is required', $user),
            400,
            ['success' => false]
        );
    }

    // Sanitize commands: must be non-empty strings
    $commands = array_values(array_filter(array_map('trim', $commands), static fn ($c) => $c !== ''));
    if (empty($commands)) {
        apiError(
            'errors.admin.areafix.commands_required',
            apiLocalizedText('errors.admin.areafix.commands_required', 'At least one command is required', $user),
            400,
            ['success' => false]
        );
    }

    $sysopUserId = (int)($user['user_id'] ?? $user['id'] ?? 0);

    try {
        $areafixManager = new \BinktermPHP\AreaFixManager();
        $areafixManager->sendCommand($uplinkAddress, $commands, $robot, $sysopUserId);
    } catch (\RuntimeException $e) {
        apiError(
            'errors.admin.areafix.send_failed',
            $e->getMessage(),
            400,
            ['success' => false]
        );
    } catch (\Throwable $e) {
        apiError(
            'errors.admin.areafix.send_failed',
            apiLocalizedText('errors.admin.areafix.send_failed', 'Failed to send command', $user),
            500,
            ['success' => false]
        );
    }

    echo json_encode(['success' => true]);
});

/**
 * GET /api/admin/areafix/history
 * Return AreaFix/FileFix message history for an uplink.
 * Query params: uplink=<address>
 */
SimpleRouter::get('/api/admin/areafix/history', function () {
    $user = RouteHelper::requireAdmin();
    header('Content-Type: application/json');

    $uplinkAddress = trim((string)($_GET['uplink'] ?? ''));
    if ($uplinkAddress === '') {
        apiError(
            'errors.admin.areafix.uplink_required',
            apiLocalizedText('errors.admin.areafix.uplink_required', 'Uplink address is required', $user),
            400,
            ['success' => false]
        );
    }

    $sysopUserId = (int)($user['user_id'] ?? $user['id'] ?? 0);

    try {
        $areafixManager = new \BinktermPHP\AreaFixManager();
        $messages = $areafixManager->getHistory($uplinkAddress, $sysopUserId);
    } catch (\Throwable $e) {
        apiError(
            'errors.admin.areafix.history_failed',
            apiLocalizedText('errors.admin.areafix.history_failed', 'Failed to load message history', $user),
            500,
            ['success' => false]
        );
    }

    echo json_encode(['success' => true, 'messages' => $messages]);
});

/**
 * POST /api/admin/areafix/sync
 * Parse area list and sync to local echo/file area table.
 * Body: { uplink: string, robot: "areafix"|"filefix", areas: [{name,description},...], deactivate_missing: bool }
 */
SimpleRouter::post('/api/admin/areafix/sync', function () {
    $user = RouteHelper::requireAdmin();
    header('Content-Type: application/json');

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        apiError(
            'errors.admin.areafix.invalid_json',
            apiLocalizedText('errors.admin.areafix.invalid_json', 'Invalid request payload', $user),
            400,
            ['success' => false]
        );
    }

    $uplinkAddress = trim((string)($body['uplink'] ?? ''));
    $robot = strtolower(trim((string)($body['robot'] ?? '')));
    $parsedAreas = $body['areas'] ?? [];
    $deactivateMissing = (bool)($body['deactivate_missing'] ?? false);

    if ($uplinkAddress === '') {
        apiError(
            'errors.admin.areafix.uplink_required',
            apiLocalizedText('errors.admin.areafix.uplink_required', 'Uplink address is required', $user),
            400,
            ['success' => false]
        );
    }

    if (!in_array($robot, ['areafix', 'filefix'], true)) {
        apiError(
            'errors.admin.areafix.invalid_robot',
            apiLocalizedText('errors.admin.areafix.invalid_robot', 'Robot must be "areafix" or "filefix"', $user),
            400,
            ['success' => false]
        );
    }

    if (!is_array($parsedAreas)) {
        apiError(
            'errors.admin.areafix.invalid_json',
            apiLocalizedText('errors.admin.areafix.invalid_json', 'Invalid request payload', $user),
            400,
            ['success' => false]
        );
    }

    // Look up domain for this uplink
    $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
    $uplink = $binkpConfig->getUplinkByAddress($uplinkAddress);
    $domain = (string)($uplink['domain'] ?? 'fidonet');

    try {
        $areafixManager = new \BinktermPHP\AreaFixManager();
        $summary = $areafixManager->syncSubscribedAreas(
            $uplinkAddress,
            $domain,
            $parsedAreas,
            $deactivateMissing,
            $robot
        );
    } catch (\Throwable $e) {
        apiError(
            'errors.admin.areafix.sync_failed',
            apiLocalizedText('errors.admin.areafix.sync_failed', 'Failed to sync areas', $user),
            500,
            ['success' => false]
        );
    }

    echo json_encode(['success' => true, 'summary' => $summary]);
});

// GET /admin/api/uplinks — list configured uplink addresses for the admin terminal
SimpleRouter::get('/admin/api/uplinks', function () {
    $user = RouteHelper::requireAdmin();
    header('Content-Type: application/json');

    $config = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
    $uplinks = array_map(function ($u) {
        return [
            'address' => $u['address'] ?? '',
            'host'    => $u['host'] ?? '',
            'domain'  => $u['domain'] ?? '',
        ];
    }, $config->getUplinks());

    echo json_encode(['success' => true, 'uplinks' => $uplinks]);
});

// POST /admin/api/poll — synchronous binkp poll for the admin terminal
SimpleRouter::post('/admin/api/poll', function () {
    $user = RouteHelper::requireAdmin();
    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true);
    $upstream = trim((string)($input['upstream'] ?? 'all'));
    if ($upstream === '') {
        $upstream = 'all';
    }

    try {
        $client = new \BinktermPHP\Admin\AdminDaemonClient();
        $result = $client->binkPollSync($upstream);
        $client->close();
        echo json_encode(['success' => true, 'result' => $result]);
    } catch (\Throwable $e) {
        apiError('errors.admin.poll.failed', 'Poll failed: ' . $e->getMessage(), 500);
    }
});

// GET /admin/api/last — recent callers within the past N hours (default 168 = 1 week)
SimpleRouter::get('/admin/api/last', function () {
    RouteHelper::requireAdmin();
    header('Content-Type: application/json');

    $hours = isset($_GET['hours']) ? max(1, (int)$_GET['hours']) : 168;
    $auth = new \BinktermPHP\Auth();
    $callers = $auth->getRecentCallers($hours);

    echo json_encode(['callers' => $callers, 'hours' => $hours]);
});

// POST /admin/api/wall — broadcast a wall message to all connected users
SimpleRouter::post('/admin/api/wall', function () {
    $auth = new Auth();
    $user = $auth->requireAuth();

    $adminController = new AdminController();
    $adminController->requireAdmin($user);

    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true);
    $message = trim((string)($input['message'] ?? ''));

    if ($message === '') {
        apiError('errors.admin.wall.empty_message', 'Message cannot be empty', 400);
        return;
    }

    if (mb_strlen($message) > 1000) {
        apiError('errors.admin.wall.message_too_long', 'Message too long (max 1000 characters)', 400);
        return;
    }

    $db = \BinktermPHP\Database::getInstance()->getPdo();
    \BinktermPHP\Realtime\BinkStream::emit($db, 'wall_message', [
        'from'    => $user['username'],
        'message' => $message,
    ], null, false);

    echo json_encode(['success' => true]);
});

// POST /admin/api/msg — send a private message to a specific user
SimpleRouter::post('/admin/api/msg', function () {
    $auth = new Auth();
    $user = $auth->requireAuth();

    $adminController = new AdminController();
    $adminController->requireAdmin($user);

    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true);
    $targetUsername = trim((string)($input['username'] ?? ''));
    $message = trim((string)($input['message'] ?? ''));

    if ($targetUsername === '') {
        apiError('errors.admin.msg.no_username', 'Username is required', 400);
        return;
    }

    if ($message === '') {
        apiError('errors.admin.msg.empty_message', 'Message cannot be empty', 400);
        return;
    }

    if (mb_strlen($message) > 1000) {
        apiError('errors.admin.msg.message_too_long', 'Message too long (max 1000 characters)', 400);
        return;
    }

    $db = \BinktermPHP\Database::getInstance()->getPdo();
    $stmt = $db->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1");
    $stmt->execute([$targetUsername]);
    $target = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!$target) {
        apiError('errors.admin.msg.user_not_found', 'User not found', 404);
        return;
    }

    \BinktermPHP\Realtime\BinkStream::emit($db, 'wall_message', [
        'from'    => $user['username'],
        'message' => $message,
        'private' => true,
    ], (int)$target['id'], false);

    echo json_encode(['success' => true]);
});

