// Source Games WebDoor - Community Server Browser
// Displays configured Source engine game servers

let config = null;

// Load game configuration from BBS API
async function loadConfig() {
    try {
        const response = await fetch('/api/webdoor/source-games/config');
        if (!response.ok) {
            throw new Error('Failed to load configuration');
        }
        config = await response.json();
        console.log('Loaded config:', config);
        displayServers();
    } catch (error) {
        console.error('Error loading config:', error);
        showError('Failed to load server list. Please try again later.');
    }
}

// Display servers in the grid
function displayServers() {
    const container = document.getElementById('servers-container');

    if (!config || !config.servers || config.servers.length === 0) {
        container.innerHTML = '<div class="error">No servers configured. Contact the system operator.</div>';
        return;
    }

    container.innerHTML = '';

    config.servers.forEach((server, index) => {
        const card = createServerCard(server, index);
        container.appendChild(card);
    });
}

// Create a server card element
function createServerCard(server, index) {
    const card = document.createElement('div');
    card.className = 'server-card';
    card.innerHTML = `
        <div class="server-header">
            <div>
                <div class="server-name">${escapeHtml(server.name)}</div>
                <div class="server-game">${escapeHtml(server.game)}</div>
            </div>
        </div>

        <div class="server-description">
            ${escapeHtml(server.description || 'Join us for some great gaming!')}
        </div>

        <div class="server-info">
            <div class="server-info-row">
                <span class="info-label">Current Map:</span>
                <span class="info-value">${escapeHtml(server.map || 'Unknown')}</span>
            </div>
            <div class="server-info-row">
                <span class="info-label">Max Players:</span>
                <span class="info-value player-count">${server.maxPlayers || 'Unknown'}</span>
            </div>
            ${server.steamAppId ? `
            <div class="server-info-row">
                <span class="info-label">Steam App ID:</span>
                <span class="info-value">${server.steamAppId}</span>
            </div>
            ` : ''}
        </div>

        <div class="server-address">
            📡 ${escapeHtml(server.address)}
        </div>

        <button class="connect-btn" onclick="connectToServer('${escapeHtml(server.address)}', ${server.steamAppId || 0})">
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

// Show error message
function showError(message) {
    const container = document.getElementById('servers-container');
    container.innerHTML = `<div class="error">${escapeHtml(message)}</div>`;
}

// Escape HTML to prevent XSS
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Initialize when page loads
document.addEventListener('DOMContentLoaded', () => {
    console.log('Source Games WebDoor loading...');
    loadConfig();
});
