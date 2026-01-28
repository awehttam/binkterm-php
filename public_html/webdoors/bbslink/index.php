<?php
/** BBSLink Web Terminal */

require_once __DIR__ . '../../../../vendor/autoload.php';

use BinktermPHP\Auth;
use BinktermPHP\Template;
use BinktermPHP\Database;
use BinktermPHP\Config;

// Initialize database
Database::getInstance();

// Start session for auth cookies
if (!headers_sent()) {
    session_start();
}

// Check authentication (optional for terminal)
$auth = new Auth();
$user = $auth->getCurrentUser();
$username='';
// Load terminal configuration
$bbsAuthToken = $auth->generateGatewayToken($user['user_id'], 'menu', 300);
$gameConfig = \BinktermPHP\GameConfig::getGameConfig("bbslink");

// Load terminal configuration
$terminalEnabled = Config::env('TERMINAL_ENABLED', 'false') === 'true';
$terminalHost = 'games.bbslink.net';
$terminalPort = 23;
$terminalProxyHost = $gameConfig['proxy'];
$terminalProxyPort = $gameConfig['proxy_port'];
$terminalProxyScheme = $gameConfig['proxy_scheme'] ?? 'https';
$terminalTitle =  'BBSLink';

// Check if terminal is enabled.
if (!$terminalEnabled) {
    $template = new Template();
    $template->renderResponse('error.twig', [
        'error' => 'Terminal access is currently disabled.'
    ]);
    exit();
}
// Get system name from BinkP config
try {
    $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
    $systemName = $binkpConfig->getSystemName();
} catch (\Exception $e) {
    $systemName = \BinktermPHP\Config::SYSTEM_NAME;
}


?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($terminalTitle); ?> - <?php echo htmlspecialchars($systemName); ?></title>
    
    <!-- Favicons -->
    <link rel="icon" type="image/svg+xml" href="{{ favicon_svg }}">
    <link rel="icon" type="image/svg+xml" href="{{ favicon_svg }}">
    <link rel="icon" type="image/x-icon" href="{{ favicon_ico }}">
    <link rel="apple-touch-icon" href="{{ favicon_png }}">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(Config::getStylesheet()); ?>?v=<?php echo time(); ?>" rel="stylesheet">
    <link rel="stylesheet" href="/webdoors/bbslink/assets/xterm.css" />
    
    <style>
        body {
            background: linear-gradient(135deg, #2d3748 0%, #1a202c 100%);
            min-height: 100vh;
        }
        .terminal-container {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 30px;
            margin-top: 20px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        #terminal {
            width: 100%;
            height: 60vh;
            border-radius: 8px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            background: #000;
        }
        .login-form {
            background: linear-gradient(135deg, #4a5568 0%, #2d3748 100%);
            padding: 30px;
            border-radius: 12px;
            color: #fff;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .login-form h3 {
            color: #f8fafc;
            font-weight: 400;
            text-align: center;
            margin-bottom: 25px;
        }
        .login-form input {
            width: 100%;
            padding: 15px;
            margin: 12px 0;
            border: 1px solid rgba(255, 255, 255, 0.2);
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
            border-radius: 8px;
            font-size: 16px;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }
        .login-form input::placeholder {
            color: rgba(255, 255, 255, 0.7);
        }
        .login-form input:focus {
            outline: none;
            border-color: #60a5fa;
            background: rgba(255, 255, 255, 0.15);
            box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.2);
        }
        .login-form button {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: #fff;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            transition: all 0.3s ease;
            box-shadow: 0 4px 20px rgba(59, 130, 246, 0.3);
        }
        .login-form button:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 25px rgba(59, 130, 246, 0.4);
        }
        .login-form select {
            width: 100%;
            padding: 15px;
            margin: 12px 0;
            border: 1px solid rgba(255, 255, 255, 0.2);
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
            border-radius: 8px;
            font-size: 16px;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .login-form select:focus {
            outline: none;
            border-color: #60a5fa;
            background: rgba(255, 255, 255, 0.15);
            box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.2);
        }
        .login-form select option {
            background: #2d3748;
            color: #fff;
        }
        #auth-fields {
            transition: opacity 0.3s ease, max-height 0.3s ease;
            overflow: hidden;
        }
        #auth-fields[style*="display: none"] {
            opacity: 0;
            max-height: 0;
        }
        .hidden {
            display: none;
        }
        
        
        /* Terminal specific navbar styling */
        .navbar-dark {
            background: rgba(13, 110, 253, 0.9) !important;
            backdrop-filter: blur(10px);
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="/webdoors/bbslink/assets/xterm.js"></script>
    <script src="/webdoors/bbslink/assets/xterm-addon-fit.js"></script>
    <script src="/webdoors/bbslink/assets/xterm-addon-web-links.js"></script>
    <script src="/webdoors/bbslink/assets/socket.io.min.js"></script>
</head>
<body>


    <main class="container">
        <div class="terminal-container">
            <div class="text-center mb-4">
                <h1 class="text-white">
                    <i class="fas fa-terminal me-2"></i>
                    BBSLink Doors
                </h1>
            </div>
            
            <div id="login-form" class="login-form">

                <P>
                    BBSLink is a free InterBBS game server offering a variety of door games to BBS's world wide.  By connecting to BBSLink you'll have access to games such as LORD, LORD2, TEOS, TW2002, Doormud and much more.
                </P>
                <label for="sshhost" style="display: block; margin-bottom: 8px; color: rgba(255, 255, 255, 0.9); font-weight: 500;">Connect to BBSLink</label>
                <input type="hidden" id="sshhost" name="sshhost" value="<?php echo $terminalHost.':'.$terminalPort.':telnet';?>">


                <button onclick="startConnection()">Connect</button>
            </div>
            <div id="terminal" class="hidden"></div>
        </div>
    </main>



    <script>
        // Pre-configured proxy server settings
        const PROXY_HOST = '<?php echo addslashes($terminalProxyHost); ?>';
        const PROXY_PORT = <?php echo intval($terminalProxyPort); ?>;
        const PROXY_SCHEME = '<?php echo $terminalProxyScheme;?>';
        
        // Remote host settings
        let REMOTE_HOST = '<?php echo addslashes($terminalHost); ?>';
        let REMOTE_PORT = '<?php echo intval($terminalPort); ?>';
        let REMOTE_PROTO = 'telnet';


        // Initialize xterm.js
        const terminal = new Terminal({
            cursorBlink: true,
            theme: {
                background: '#000000',
                foreground: '#ffffff'
            }
        });

        // Add fit addon
        const fitAddon = new FitAddon.FitAddon();
        terminal.loadAddon(fitAddon);

        // Add web links addon
        const webLinksAddon = new WebLinksAddon.WebLinksAddon();
        terminal.loadAddon(webLinksAddon);

        // Initialize terminal but don't open it yet
        let terminalInitialized = false;

        // Socket.IO connection
        let socket;

        // Update auth fields visibility based on protocol
        function updateAuthFields() {
            const hostValue = document.getElementById('sshhost').value;
            const parts = hostValue.split(':');
            const proto = parts[2] || 'ssh';
            const authFields = document.getElementById('auth-fields');

            if (proto === 'telnet') {
                authFields.style.display = 'none';
            } else {
                authFields.style.display = 'block';
            }
        }

        function startConnection() {
            const hostValue = document.getElementById('sshhost').value;
            const parts = hostValue.split(':');
            REMOTE_HOST = parts[0];
            REMOTE_PORT = parts[1];
            REMOTE_PROTO = parts[2] || 'ssh';
            // For SSH, require username and password
            let username = '';
            let password = '';

            if (REMOTE_PROTO === 'ssh') {
                username = document.getElementById('username').value;
                password = document.getElementById('password').value;

                if (!username || !password) {
                    alert('Please enter both username and password');
                    return;
                }
            }

            // Hide login form and show terminal
            document.getElementById('login-form').classList.add('hidden');
            document.getElementById('terminal').classList.remove('hidden');

            // Initialize terminal if not already done
            if (!terminalInitialized) {
                terminal.open(document.getElementById('terminal'));
                fitAddon.fit();
                terminalInitialized = true;
            }

            connect(username, password);
        }
        
        function connect(username, password) {
            const serverUrl = `${PROXY_SCHEME}://${PROXY_HOST}:${PROXY_PORT}`;
            console.log('Connecting to:', serverUrl);
            socket = io(serverUrl);
            
            socket.on('connect', function() {
                console.log('Socket.IO connected successfully');
                terminal.write('\r\n\x1b[32mConnected to terminal server\x1b[0m\r\n');

                // Initiate connection (SSH or Telnet based on protocol)
                const connectionType = 'connect-bbslink-auth';// REMOTE_PROTO === 'telnet' ? 'connect-telnet' : 'connect-ssh';
                console.log(`Sending ${connectionType} request:`, { host: REMOTE_HOST, port: REMOTE_PORT, username, proto: REMOTE_PROTO });

                socket.emit(connectionType, {
                    host: REMOTE_HOST,
                    port: REMOTE_PORT,
                    userid: 1,
                    doorCode: 'menu',
                    token: '<?php echo $bbsAuthToken;?>',
                    proto: REMOTE_PROTO
                });
            });
            
            socket.on('data', function(data) {
                terminal.write(data);
            });
            
            socket.on('connection-status', function(status) {
                const protoName = REMOTE_PROTO.toUpperCase();
                if (status.status === 'connected') {
                    terminal.write(`\r\n\x1b[32m${protoName} connection established\x1b[0m\r\n`);
                } else if (status.status === 'disconnected') {
                    terminal.write(`\r\n\x1b[31m${protoName} connection closed\x1b[0m\r\n`);
                }
            });
            
            socket.on('error', function(error) {
                console.error('Socket.IO error:', error);
                terminal.write(`\r\n\x1b[31mError: ${error.message}\x1b[0m\r\n`);
            });
            
            socket.on('disconnect', function() {
                console.log('Socket.IO disconnected');
                terminal.write('\r\n\x1b[31mConnection lost. Attempting to reconnect...\x1b[0m\r\n');
            });
            
            socket.on('connect_error', function(error) {
                console.error('Socket.IO connection error:', error);
                terminal.write(`\r\n\x1b[31mConnection Error: ${error.message}\x1b[0m\r\n`);
            });
        }

        // Send data from terminal to server
        terminal.onData(function(data) {
            if (socket && socket.connected) {
                socket.emit('input', data);
            }
        });

        // Handle terminal resize
        terminal.onResize(function(size) {
            if (socket && socket.connected) {
                socket.emit('resize', { rows: size.rows, cols: size.cols });
            }
        });

        // Handle window resize
        window.addEventListener('resize', function() {
            fitAddon.fit();
        });

        // Allow Enter key to submit form
        document.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && !document.getElementById('login-form').classList.contains('hidden')) {
                startConnection();
            }
        });

        // Initialize auth fields visibility on page load
        document.addEventListener('DOMContentLoaded', function() {
        });
    </script>
</body>
</html>