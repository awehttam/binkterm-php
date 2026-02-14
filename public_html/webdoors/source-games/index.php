<?php
// Include WebDoor SDK (handles autoload, database, and session initialization)
require_once __DIR__ . '/../_doorsdk/php/helpers.php';

use BinktermPHP\Auth;
use BinktermPHP\GameConfig;

// Check if user is logged in
$auth = new Auth();
$user = $auth->getCurrentUser();

if (!$user) {
    header('Location: /login');
    exit;
}

// Check if game system is enabled
if (!GameConfig::isGameSystemEnabled()) {
    http_response_code(503);
    echo json_encode(['error' => 'Game system is currently disabled.']);
    exit;
}

// Load game configuration
$gameConfig = GameConfig::getGameConfig('source-games') ?? [];

// Ensure servers array exists
if (!isset($gameConfig['servers'])) {
    $gameConfig['servers'] = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Source Games - Community Servers</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            color: #ffffff;
            padding: 20px;
            min-height: 100vh;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        h1 {
            text-align: center;
            margin-bottom: 10px;
            color: #FF6600;
            text-shadow: 0 0 10px rgba(255, 102, 0, 0.5);
            font-size: 2.5em;
        }

        .subtitle {
            text-align: center;
            color: #aaa;
            margin-bottom: 30px;
            font-size: 1.1em;
        }

        .servers-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .server-card {
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid rgba(255, 102, 0, 0.3);
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .server-card:hover {
            transform: translateY(-5px);
            border-color: #FF6600;
            box-shadow: 0 8px 25px rgba(255, 102, 0, 0.3);
        }

        .server-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }

        .server-name {
            font-size: 1.4em;
            font-weight: bold;
            color: #FF6600;
            margin-bottom: 5px;
        }

        .server-game {
            color: #888;
            font-size: 0.9em;
            margin-bottom: 10px;
        }

        .server-description {
            color: #ccc;
            margin-bottom: 15px;
            line-height: 1.5;
        }

        .server-info {
            background: rgba(0, 0, 0, 0.3);
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .server-info-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .server-info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            color: #888;
            font-size: 0.9em;
        }

        .info-value {
            color: #fff;
            font-weight: bold;
        }

        .player-count {
            color: #00ff88;
        }

        .server-address {
            font-family: 'Courier New', monospace;
            background: rgba(255, 102, 0, 0.2);
            padding: 8px 12px;
            border-radius: 6px;
            margin-bottom: 15px;
            text-align: center;
            font-size: 0.95em;
            letter-spacing: 0.5px;
        }

        .connect-btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #FF6600 0%, #ff8833 100%);
            border: none;
            border-radius: 8px;
            color: white;
            font-size: 1.1em;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .connect-btn:hover {
            background: linear-gradient(135deg, #ff8833 0%, #ffaa55 100%);
            transform: scale(1.02);
            box-shadow: 0 5px 15px rgba(255, 102, 0, 0.4);
        }

        .connect-btn:active {
            transform: scale(0.98);
        }

        .steam-icon {
            margin-right: 8px;
        }

        .error {
            text-align: center;
            padding: 40px;
            color: #ff4444;
            background: rgba(255, 68, 68, 0.1);
            border-radius: 12px;
            border: 2px solid rgba(255, 68, 68, 0.3);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #888;
        }

        .empty-state h2 {
            color: #FF6600;
            margin-bottom: 15px;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.85em;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-online {
            background: #00ff88;
            color: #000;
        }

        .status-offline {
            background: #ff4444;
            color: #fff;
        }

        .loading {
            text-align: center;
            padding: 40px;
            color: #888;
            font-size: 1.2em;
        }

        @media (max-width: 768px) {
            .servers-grid {
                grid-template-columns: 1fr;
            }

            h1 {
                font-size: 2em;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🎮 Source Games</h1>
        <p class="subtitle">Join our community game servers and socialize!</p>

        <div id="servers-container" class="servers-grid">
            <!-- Servers will be rendered here -->
        </div>
    </div>

    <script>
        // Load live server status from API
        async function loadServers() {
            const container = document.getElementById('servers-container');
            container.innerHTML = '<div class="loading">🔄 Querying servers...</div>';

            try {
                const response = await fetch('api-status.php');
                const data = await response.json();

                if (!data.servers || data.servers.length === 0) {
                    container.innerHTML = `
                        <div class="empty-state">
                            <h2>No Servers Configured</h2>
                            <p>The system operator hasn't configured any game servers yet.</p>
                            <p>Check back later!</p>
                        </div>
                    `;
                    return;
                }

                container.innerHTML = '';
                data.servers.forEach((server) => {
                    const card = createServerCard(server);
                    container.appendChild(card);
                });

            } catch (error) {
                console.error('Failed to load servers:', error);
                container.innerHTML = '<div class="error">Failed to load server status. Please refresh the page.</div>';
            }
        }

        // Create a server card element
        function createServerCard(server) {
            const card = document.createElement('div');
            card.className = 'server-card';

            const statusBadge = server.online
                ? '<span class="status-badge status-online">Online</span>'
                : '<span class="status-badge status-offline">Offline</span>';

            const serverInfo = server.online ? `
                <div class="server-info">
                    <div class="server-info-row">
                        <span class="info-label">Current Map:</span>
                        <span class="info-value">${escapeHtml(server.map)}</span>
                    </div>
                    <div class="server-info-row">
                        <span class="info-label">Players:</span>
                        <span class="info-value player-count">${server.players}/${server.maxPlayers}</span>
                    </div>
                </div>
            ` : '<div class="server-info"><p class="text-muted" style="margin:0;color:#888;">Server offline or not responding</p></div>';

            card.innerHTML = `
                <div class="server-header">
                    <div>
                        <div class="server-name">${escapeHtml(server.name)}</div>
                        <div class="server-game">${escapeHtml(server.game)}</div>
                    </div>
                    <div>${statusBadge}</div>
                </div>

                <div class="server-description">
                    ${escapeHtml(server.description || 'Join us for some great gaming!')}
                </div>

                ${serverInfo}

                <div class="server-address">
                    📡 ${escapeHtml(server.address)}
                </div>

                <button class="connect-btn" onclick='connectToServer(${JSON.stringify(server.address)}, ${server.steamAppId || 0})' ${!server.online ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : ''}>
                    <span class="steam-icon">🎮</span> Connect to Server
                </button>
            `;

            return card;
        }

        // Connect to a Source server
        function connectToServer(address, steamAppId) {
            // Try Steam browser protocol first (requires Steam installed)
            if (steamAppId) {
                const steamUrl = `steam://connect/${address}`;
                console.log('Connecting via Steam:', steamUrl);
                window.location.href = steamUrl;

                // Show fallback instructions after a delay
                setTimeout(() => {
                    showConnectInstructions(address, steamAppId);
                }, 1000);
            } else {
                showConnectInstructions(address, 0);
            }
        }

        // Show connection instructions
        function showConnectInstructions(address, steamAppId) {
            const message = steamAppId
                ? `To connect to this server:\n\n` +
                  `1. Make sure Steam is running\n` +
                  `2. Launch the game from Steam\n` +
                  `3. Open the console (~) and type:\n` +
                  `   connect ${address}\n\n` +
                  `Or click the connect button again to try the Steam protocol.`
                : `To connect to this server:\n\n` +
                  `Launch the game and open the console (~)\n` +
                  `Type: connect ${address}`;

            alert(message);
        }

        // Escape HTML to prevent XSS
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', () => {
            console.log('Source Games WebDoor - Loading servers...');
            loadServers();
        });
    </script>
</body>
</html>
