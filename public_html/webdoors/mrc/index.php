<?php
/**
 * MRC Chat WebDoor
 *
 * Loads the user's selected BBS theme so the MRC interface inherits
 * the same colour palette as the rest of the site.
 */

// Include WebDoor SDK (handles autoload, database, and session initialization)
require_once __DIR__ . '/../_doorsdk/php/helpers.php';

use BinktermPHP\AppearanceConfig;
use BinktermPHP\Auth;
use BinktermPHP\Config;
use BinktermPHP\MessageHandler;
use BinktermPHP\Mrc\MrcConfig;

// Resolve the correct theme stylesheet for the current user
// (mirrors the logic in Template::addGlobalVariables)
$availableThemes = Config::getThemes();
$defaultTheme    = AppearanceConfig::getDefaultTheme();
$stylesheet      = ($defaultTheme !== '' && in_array($defaultTheme, $availableThemes, true))
    ? $defaultTheme
    : Config::getStylesheet();

$auth        = new Auth();
$currentUser = $auth->getCurrentUser();

if ($currentUser && !empty($currentUser['user_id']) && !AppearanceConfig::isThemeLocked()) {
    try {
        $handler  = new MessageHandler();
        $settings = $handler->getUserSettings($currentUser['user_id']);
        if (!empty($settings['theme']) && in_array($settings['theme'], $availableThemes, true)) {
            $stylesheet = $settings['theme'];
        }
    } catch (\Exception $e) {
        // Fall back to sysop default
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MRC Chat</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <!-- User's selected BBS theme (provides CSS variable overrides) -->
    <link href="<?php echo htmlspecialchars($stylesheet); ?>" rel="stylesheet">
    <link href="/css/ansisys.css" rel="stylesheet">
    <link href="mrc.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid h-100 p-0">
        <div class="row h-100 g-0">
            <!-- Left Sidebar: Room List -->
            <div class="col-md-2 border-end" id="mrc-sidebar-left">
                <div class="p-3">
                    <h5 class="mb-2">
                        <i class="bi bi-door-open"></i> Rooms
                    </h5>
                    <div class="input-group input-group-sm mb-3">
                        <input type="text" class="form-control" id="join-room-input"
                               placeholder="Room name..." autocomplete="off" maxlength="20">
                        <button class="btn btn-outline-primary" id="join-room-btn" type="button" title="Join or create room">
                            <i class="bi bi-arrow-right-circle"></i>
                        </button>
                    </div>
                    <div id="room-list" class="list-group">
                        <div class="text-center py-3 mrc-text-muted">
                            <div class="spinner-border spinner-border-sm" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <div class="mt-2">Loading rooms...</div>
                        </div>
                    </div>
                </div>

                <!-- Connection Status -->
                <div class="p-3 border-top mrc-border">
                    <div id="connection-status" class="mrc-text-muted small">
                        <i class="bi bi-circle-fill text-secondary"></i> Checking...
                    </div>
                </div>
            </div>

            <!-- Center: Chat Messages -->
            <div class="col-md-8 d-flex flex-column">
                <!-- Chat Header -->
                <div class="border-bottom p-3" id="mrc-chat-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-0" id="current-room-name">
                                <i class="bi bi-hash"></i> <span>Select a room</span>
                            </h5>
                            <small class="mrc-text-muted" id="current-room-topic"></small>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <div class="badge bg-warning text-dark d-none" id="private-chat-indicator">
                                <span>Direct:</span>
                                <span class="ms-1" id="private-chat-user"></span>
                                <button type="button" class="btn btn-sm btn-link p-0 ms-2" id="private-chat-exit" title="Return to room chat">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                            <button class="btn btn-sm btn-outline-primary d-none" id="join-room-active-btn">
                                <i class="bi bi-door-open"></i> Join Room
                            </button>
                            <button class="btn btn-sm btn-outline-secondary" id="refresh-btn">
                                <i class="bi bi-arrow-clockwise"></i> Refresh
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Chat Messages Area -->
                <div class="flex-grow-1 overflow-auto p-3" id="chat-messages">
                    <div class="text-center mrc-text-muted py-5">
                        <i class="bi bi-chat-dots" style="font-size: 3rem;"></i>
                        <p class="mt-3">Join a room to start chatting</p>
                    </div>
                </div>

                <!-- Message Input -->
                <div class="border-top p-3" id="mrc-input-bar">
                    <form id="message-form">
                        <div class="input-group">
                            <input type="text"
                                   class="form-control"
                                   id="message-input"
                                   placeholder="Type a message... (max 140 chars)"
                                   maxlength="140"
                                   autocomplete="off"
                                   disabled>
                            <button class="btn btn-primary" type="submit" id="send-btn" disabled>
                                <i class="bi bi-send"></i> Send
                            </button>
                        </div>
                        <div class="form-text mt-1 mrc-text-muted">
                            <span id="char-count">0</span>/140 characters
                        </div>
                    </form>
                </div>
            </div>

            <!-- Right Sidebar: User List -->
            <div class="col-md-2 border-start" id="mrc-sidebar-right">
                <div class="p-3">
                    <h5 class="mb-3">
                        <i class="bi bi-people"></i> Users
                        <span class="badge bg-secondary" id="user-count">0</span>
                        <span class="badge bg-warning text-dark ms-2 d-none" id="private-unread-count">0</span>
                    </h5>
                    <div id="user-list" class="list-group">
                        <div class="text-center mrc-text-muted py-3 small">
                            Join a room to see users
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Pipe/ANSI color code parser -->
    <script src="/js/ansisys.js"></script>
    <script>
        window.mrcCurrentUser = <?php echo json_encode($currentUser['username'] ?? ''); ?>;
        window.mrcCurrentBbs = <?php echo json_encode(MrcConfig::getInstance()->getBbsName()); ?>;
    </script>
    <!-- MRC Client JS -->
    <script src="mrc.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const modalEl = document.getElementById('mrcWarningModal');
            if (!modalEl || typeof bootstrap === 'undefined') return;
            if (sessionStorage.getItem('mrcWarningSeen') === '1') return;
            const modal = new bootstrap.Modal(modalEl, { backdrop: 'static', keyboard: true });
            modal.show();
            modalEl.addEventListener('hidden.bs.modal', () => {
                sessionStorage.setItem('mrcWarningSeen', '1');
            }, { once: true });
        });
    </script>

    <!-- MRC Warning Modal -->
    <div class="modal fade" id="mrcWarningModal" tabindex="-1" aria-labelledby="mrcWarningModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="mrcWarningModalLabel">MRC Under Development</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    MRC is currently under development and in testing, and may not be suitable for production use due to bugs or other issues.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">I Understand</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MOTD Modal -->
    <div class="modal fade" id="motdModal" tabindex="-1" aria-labelledby="motdModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="motdModalLabel">Message of the Day</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <pre id="motdModalBody" class="mrc-motd-text"></pre>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
