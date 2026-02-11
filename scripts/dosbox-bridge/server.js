#!/usr/bin/env node
/**
 * DOSBox Door Bridge Server
 *
 * Bridges TCP connections (DOSBox serial port) to WebSocket clients (browser)
 *
 * Usage: node door-bridge-server.js <tcp_port> <ws_port> [session_id]
 * Example: node door-bridge-server.js 5000 5001 test-session
 */

const net = require('net');
const WebSocket = require('ws');
const iconv = require('iconv-lite');

// Parse command line arguments
const args = process.argv.slice(2);
const tcpPort = parseInt(args[0]) || 5000;
const wsPort = parseInt(args[1]) || 5001;
const sessionId = args[2] || 'unknown';

console.log('=== DOSBox Door Bridge Server ===');
console.log(`Session ID: ${sessionId}`);
console.log(`TCP Port (DOSBox): ${tcpPort}`);
console.log(`WebSocket Port (Client): ${wsPort}`);
console.log('');

class DoorBridge {
    constructor(tcpPort, wsPort, sessionId) {
        this.tcpPort = tcpPort;
        this.wsPort = wsPort;
        this.sessionId = sessionId;
        this.dosboxSocket = null;
        this.wsClient = null;
        this.startTime = Date.now();
        this.bytesFromDosbox = 0;
        this.bytesToDosbox = 0;

        // TCP server for DOSBox connection
        this.tcpServer = net.createServer((socket) => {
            console.log('[TCP] DOSBox connected from', socket.remoteAddress);
            this.dosboxSocket = socket;

            // Disable Nagle's algorithm for lower latency
            socket.setNoDelay(true);

            socket.on('data', (data) => {
                this.bytesFromDosbox += data.length;

                // DOSBox sends CP437 (DOS) encoded text
                // Convert to UTF-8 for web client
                try {
                    const utf8Data = iconv.decode(data, 'cp437');

                    // DEBUG: Show what we received from DOSBox
                    // console.log('[TCP→WS]', data.length, 'bytes:', JSON.stringify(utf8Data));

                    // Forward to WebSocket client
                    if (this.wsClient && this.wsClient.readyState === WebSocket.OPEN) {
                        this.wsClient.send(utf8Data);
                    } else {
                        console.warn('[TCP] Received data but no WebSocket client connected');
                    }
                } catch (err) {
                    console.error('[TCP] Error converting encoding:', err.message);
                }
            });

            socket.on('close', () => {
                console.log('[TCP] DOSBox disconnected');
                this.dosboxSocket = null;

                // Close WebSocket when DOSBox disconnects
                if (this.wsClient) {
                    this.wsClient.close();
                }
            });

            socket.on('error', (err) => {
                console.error('[TCP] Socket error:', err.message);
            });
        });

        // WebSocket server for web client
        this.wsServer = new WebSocket.Server({
            port: wsPort,
            clientTracking: true
        });

        this.wsServer.on('connection', (ws, req) => {
            const clientIp = req.socket.remoteAddress;
            console.log('[WS] Web client connected from', clientIp);

            // Only allow one client at a time
            if (this.wsClient) {
                console.warn('[WS] Client already connected, rejecting new connection');
                ws.close(1008, 'Another client is already connected');
                return;
            }

            this.wsClient = ws;

            ws.on('message', (data) => {
                this.bytesToDosbox += data.length;

                // Web client sends UTF-8
                // Convert to CP437 for DOSBox
                try {
                    const dataStr = data.toString('utf8');
                    const cp437Data = iconv.encode(dataStr, 'cp437');

                    // DEBUG: Show what we received from web client
                    // console.log('[WS→TCP]', data.length, 'bytes:', JSON.stringify(dataStr));

                    // Forward to DOSBox
                    if (this.dosboxSocket && !this.dosboxSocket.destroyed) {
                        this.dosboxSocket.write(cp437Data);
                    } else {
                        console.warn('[WS] Received data but DOSBox not connected');
                    }
                } catch (err) {
                    console.error('[WS] Error converting encoding:', err.message);
                }
            });

            ws.on('close', (code, reason) => {
                console.log(`[WS] Web client disconnected: ${code} ${reason}`);
                this.wsClient = null;

                // Don't close DOSBox connection - user might reconnect
                // DOSBox will close when it exits
            });

            ws.on('error', (err) => {
                console.error('[WS] WebSocket error:', err.message);
            });

            // Send welcome message (disabled - can cause issues with CTTY)
            // ws.send('\r\n=== Connected to DOSBox Door Bridge ===\r\n');
            // ws.send(`Session: ${this.sessionId}\r\n\r\n`);
        });

        this.wsServer.on('error', (err) => {
            console.error('[WS] Server error:', err.message);
        });
    }

    start() {
        this.tcpServer.listen(this.tcpPort, '127.0.0.1', () => {
            console.log(`[TCP] Listening on 127.0.0.1:${this.tcpPort}`);
            console.log('[TCP] Waiting for DOSBox connection...');
        });

        console.log(`[WS] WebSocket server listening on port ${this.wsPort}`);
        console.log('[WS] Waiting for web client connection...');
        console.log('');
        console.log('Bridge running. Press Ctrl+C to stop.');
    }

    stop() {
        console.log('\n=== Shutting down bridge ===');

        // Close DOSBox connection
        if (this.dosboxSocket) {
            this.dosboxSocket.destroy();
        }

        // Close WebSocket client
        if (this.wsClient) {
            this.wsClient.close(1000, 'Server shutting down');
        }

        // Close servers
        this.tcpServer.close(() => {
            console.log('[TCP] Server closed');
        });

        this.wsServer.close(() => {
            console.log('[WS] Server closed');
        });

        // Print statistics
        const uptime = Math.floor((Date.now() - this.startTime) / 1000);
        console.log('');
        console.log('=== Session Statistics ===');
        console.log(`Uptime: ${uptime} seconds`);
        console.log(`Bytes from DOSBox: ${this.bytesFromDosbox}`);
        console.log(`Bytes to DOSBox: ${this.bytesToDosbox}`);
    }

    getStatus() {
        return {
            sessionId: this.sessionId,
            tcpPort: this.tcpPort,
            wsPort: this.wsPort,
            dosboxConnected: this.dosboxSocket !== null,
            webClientConnected: this.wsClient !== null,
            uptime: Math.floor((Date.now() - this.startTime) / 1000),
            bytesFromDosbox: this.bytesFromDosbox,
            bytesToDosbox: this.bytesToDosbox
        };
    }
}

// Check Node.js version
const nodeVersion = process.version.match(/^v(\d+)\./)[1];
if (parseInt(nodeVersion) < 18) {
    console.error('Error: Node.js 18.x or newer required');
    console.error(`Current version: ${process.version}`);
    process.exit(1);
}

// Check required modules
try {
    require.resolve('ws');
    require.resolve('iconv-lite');
} catch (err) {
    console.error('Error: Required Node.js modules not found');
    console.error('Please install dependencies:');
    console.error('  npm install ws@^8.16.0 iconv-lite@^0.6.3');
    process.exit(1);
}

// Create and start bridge
const bridge = new DoorBridge(tcpPort, wsPort, sessionId);
bridge.start();

// Status reporting (every 30 seconds)
const statusInterval = setInterval(() => {
    const status = bridge.getStatus();
    console.log('[STATUS]', JSON.stringify(status));
}, 30000);

// Cleanup on exit
process.on('SIGINT', () => {
    clearInterval(statusInterval);
    bridge.stop();
    setTimeout(() => {
        process.exit(0);
    }, 1000);
});

process.on('SIGTERM', () => {
    clearInterval(statusInterval);
    bridge.stop();
    setTimeout(() => {
        process.exit(0);
    }, 1000);
});

// Handle uncaught errors
process.on('uncaughtException', (err) => {
    console.error('[ERROR] Uncaught exception:', err);
    bridge.stop();
    process.exit(1);
});

process.on('unhandledRejection', (reason, promise) => {
    console.error('[ERROR] Unhandled rejection at:', promise, 'reason:', reason);
});
