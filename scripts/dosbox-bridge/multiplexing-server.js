#!/usr/bin/env node
/**
 * DOSBox Door Bridge - Multiplexing Server v4
 *
 * Architecture:
 * - PHP creates session record in database (with ws_token, session_path, etc.)
 * - Browser connects to WebSocket with auth token
 * - Bridge authenticates, launches emulator (DOSBox or DOSEMU), generates config
 * - Emulator connects to bridge (TCP for DOSBox, PTY for DOSEMU)
 * - Bridge multiplexes data between WebSocket and emulator
 *
 * Supports multiple emulators via adapter pattern for optimal memory usage.
 */

const net = require('net');
const WebSocket = require('ws');
const iconv = require('iconv-lite');
const url = require('url');
const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');
const { Client } = require('pg');
const { createEmulatorAdapter } = require('./emulator-adapters');
require('dotenv').config({ path: __dirname + '/../../.env' });

// Prepend ISO timestamp to every console.log / .error / .warn line
['log', 'error', 'warn'].forEach(method => {
    const original = console[method].bind(console);
    console[method] = (...args) => {
        const d = new Date();
        const ts = `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')} ${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}:${String(d.getSeconds()).padStart(2,'0')}`;
        original(`[${ts}]`, ...args);
    };
});

// Daemon support
const IS_DAEMON = process.argv.includes('--daemon');
const IS_DAEMON_CHILD = process.env.DOSBOX_BRIDGE_DAEMON_CHILD === '1';
const PID_FILE = path.resolve(__dirname, '../../data/run/multiplexing-server.pid');
const LOG_FILE = path.resolve(__dirname, '../../data/logs/multiplexing-server.log');

if (IS_DAEMON && !IS_DAEMON_CHILD) {
    // Parent: spawn a detached child then exit immediately so the shell returns.
    // The child inherits the log file as stdout/stderr (proper Unix daemon pattern).

    // Ensure directories exist
    fs.mkdirSync(path.dirname(PID_FILE), { recursive: true });
    fs.mkdirSync(path.dirname(LOG_FILE), { recursive: true });

    // Check if already running
    if (fs.existsSync(PID_FILE)) {
        const oldPid = parseInt(fs.readFileSync(PID_FILE, 'utf8'));
        try {
            process.kill(oldPid, 0);
            console.error(`Error: Multiplexing server already running with PID ${oldPid}`);
            console.error(`PID file: ${PID_FILE}`);
            console.error('To force start, remove the PID file first.');
            process.exit(1);
        } catch (err) {
            // Stale PID file - process is gone
            console.log(`Removing stale PID file (process ${oldPid} not running)`);
            fs.unlinkSync(PID_FILE);
        }
    }

    // Open log file so the child can inherit it as stdout/stderr
    const logFd = fs.openSync(LOG_FILE, 'a');

    const child = spawn(process.execPath, process.argv.slice(1), {
        detached: true,
        stdio: ['ignore', logFd, logFd],
        env: { ...process.env, DOSBOX_BRIDGE_DAEMON_CHILD: '1' }
    });

    fs.closeSync(logFd);
    child.unref(); // Allow parent to exit without waiting for child

    console.log(`Starting in daemon mode (PID: ${child.pid})`);
    console.log(`PID file: ${PID_FILE}`);
    console.log(`Log file: ${LOG_FILE}`);
    process.exit(0);

} else if (IS_DAEMON && IS_DAEMON_CHILD) {
    // Child (daemon): write PID file, set up signal handlers, then continue running.
    // stdout/stderr are already pointing at the log file via inherited file descriptors.

    fs.writeFileSync(PID_FILE, process.pid.toString());

    const cleanupPidFile = () => {
        try {
            if (fs.existsSync(PID_FILE)) {
                const currentPid = parseInt(fs.readFileSync(PID_FILE, 'utf8'));
                if (currentPid === process.pid) {
                    fs.unlinkSync(PID_FILE);
                }
            }
        } catch (err) { /* best effort */ }
    };

    process.on('exit', cleanupPidFile);
    process.on('SIGINT', () => {
        console.log('\nReceived SIGINT, shutting down...');
        process.exit(0);
    });
    process.on('SIGTERM', () => {
        console.log('\nReceived SIGTERM, shutting down...');
        process.exit(0);
    });

} else {
    // Interactive mode
    process.on('SIGINT', () => {
        console.log('\nReceived SIGINT, shutting down...');
        process.exit(0);
    });
    process.on('SIGTERM', () => {
        console.log('\nReceived SIGTERM, shutting down...');
        process.exit(0);
    });
}

// Configuration from environment
const WS_PORT = parseInt(process.env.DOSDOOR_WS_PORT) || 6001;
const WS_BIND_HOST = process.env.DOSDOOR_WS_BIND_HOST || '127.0.0.1';
const DISCONNECT_TIMEOUT = parseInt(process.env.DOSDOOR_DISCONNECT_TIMEOUT) || 0;
const DEBUG_KEEP_FILES = process.env.DOSDOOR_DEBUG_KEEP_FILES === 'true'; // Set to 'true' to disable cleanup
const CARRIER_LOSS_TIMEOUT = parseInt(process.env.DOSDOOR_CARRIER_LOSS_TIMEOUT) || 5000; // ms to wait after carrier loss
const TCP_PORT_BASE = 5000;
const TCP_PORT_MAX = 5100;
const BASE_PATH = path.resolve(__dirname, '../..');

// Database configuration
const DB_CONFIG = {
    host: process.env.DB_HOST || 'localhost',
    port: parseInt(process.env.DB_PORT) || 5432,
    database: process.env.DB_NAME || 'binktest',
    user: process.env.DB_USER || 'binktest',
    password: process.env.DB_PASS || 'binktest',
    ssl: process.env.DB_SSL === 'true' ? { rejectUnauthorized: false } : false
};

console.log('=== DOSBox Door Bridge - Multiplexing Server v3 ===');
console.log(`WebSocket Port: ${WS_PORT}`);
console.log(`Bind Address: ${WS_BIND_HOST}`);
console.log(`TCP Port Range: ${TCP_PORT_BASE}-${TCP_PORT_MAX}`);
console.log(`Disconnect Timeout: ${DISCONNECT_TIMEOUT} minutes`);
console.log(`Carrier Loss Timeout: ${CARRIER_LOSS_TIMEOUT}ms`);
console.log(`Debug Keep Files: ${DEBUG_KEEP_FILES ? 'YES (cleanup disabled)' : 'NO (cleanup enabled)'}`);
console.log(`Base Path: ${BASE_PATH}`);
console.log(`Database: ${DB_CONFIG.user}@${DB_CONFIG.host}:${DB_CONFIG.port}/${DB_CONFIG.database}`);
console.log('');

/**
 * Port Pool Manager
 * Tracks available ports and allocates them dynamically
 */
class PortPool {
    constructor(basePort, maxPort) {
        this.basePort = basePort;
        this.maxPort = maxPort;
        this.usedPorts = new Set();
    }

    allocate() {
        for (let port = this.basePort; port <= this.maxPort; port++) {
            if (!this.usedPorts.has(port)) {
                this.usedPorts.add(port);
                console.log(`[PORT] Allocated port ${port}`);
                return port;
            }
        }
        throw new Error('No available ports in pool');
    }

    release(port) {
        if (this.usedPorts.delete(port)) {
            console.log(`[PORT] Released port ${port}`);
        }
    }

    isAvailable(port) {
        return !this.usedPorts.has(port);
    }
}

/**
 * Session Manager
 * Handles door sessions and multiplexing
 */
class SessionManager {
    constructor(portPool) {
        this.portPool = portPool;
        this.sessionsByToken = new Map(); // ws_token -> session
        this.sessionsByPort = new Map(); // tcp_port -> session
        this.pendingDosBoxConnections = new Map(); // tcp_port -> session (waiting for DOSBox)
    }

    async findSessionByToken(token) {
        const client = new Client(DB_CONFIG);
        try {
            await client.connect();

            const result = await client.query(
                `SELECT session_id, user_id, door_id, node_number, ws_port, ws_token, session_path, user_data
                 FROM door_sessions
                 WHERE ws_token = $1 AND ended_at IS NULL
                 LIMIT 1`,
                [token]
            );

            if (result.rows.length === 0) {
                console.log('[AUTH] Invalid or expired token');
                return null;
            }

            const session = result.rows[0];
            console.log(`[AUTH] Token valid - Session: ${session.session_id}, Door: ${session.door_id}, Node: ${session.node_number}`);
            return session;

        } catch (err) {
            console.error('[AUTH] Database error:', err.message);
            return null;
        } finally {
            await client.end();
        }
    }

    async updateSessionPorts(sessionId, tcpPort, dosboxPid) {
        const client = new Client(DB_CONFIG);
        try {
            await client.connect();

            await client.query(
                `UPDATE door_sessions
                 SET tcp_port = $1, dosbox_pid = $2
                 WHERE session_id = $3`,
                [tcpPort, dosboxPid, sessionId]
            );

            console.log(`[DB] Updated session ${sessionId} with tcp_port=${tcpPort}, dosbox_pid=${dosboxPid}`);

        } catch (err) {
            console.error('[DB] Update error:', err.message);
            throw err;
        } finally {
            await client.end();
        }
    }

    async updateSessionPath(sessionId, sessionPath) {
        const client = new Client(DB_CONFIG);
        try {
            await client.connect();

            await client.query(
                `UPDATE door_sessions
                 SET session_path = $1
                 WHERE session_id = $2`,
                [sessionPath, sessionId]
            );

            console.log(`[DB] Updated session ${sessionId} with session_path=${sessionPath}`);

        } catch (err) {
            console.error('[DB] Update error:', err.message);
            throw err;
        } finally {
            await client.end();
        }
    }

    async deleteSession(sessionId) {
        const client = new Client(DB_CONFIG);
        try {
            await client.connect();

            await client.query(
                `DELETE FROM door_sessions
                 WHERE session_id = $1`,
                [sessionId]
            );

            console.log(`[DB] Deleted session record ${sessionId}`);

        } catch (err) {
            console.error('[DB] Delete error:', err.message);
            // Don't throw - cleanup should continue even if DB delete fails
        } finally {
            await client.end();
        }
    }

    async handleWebSocketConnection(ws, sessionData) {
        const sessionId = sessionData.session_id;
        console.log(`[WS] Connection for session ${sessionId}`);

        // Check if session already exists (reconnection)
        let session = this.sessionsByToken.get(sessionData.ws_token);

        if (session) {
            console.log(`[WS] Reconnection to existing session ${sessionId}`);
            // Close old WebSocket
            if (session.ws && session.ws.readyState === WebSocket.OPEN) {
                session.ws.close(1000, 'Client reconnected');
            }
            session.ws = ws;
            this.setupWebSocketHandlers(session);
            return;
        }

        // Create new session
        console.log(`[WS] Creating new session ${sessionId}`);
        session = {
            sessionId,
            sessionData,
            ws,
            emulator: null,          // Emulator adapter instance
            emulatorSocket: null,    // Connection to emulator (TCP socket or PTY)
            tcpPort: null,           // TCP port (DOSBox only)
            tcpServer: null,         // TCP server (DOSBox only)
            emulatorProcess: null,   // Emulator process
            emulatorPid: null,       // Emulator PID
            bytesFromEmulator: 0,
            bytesToEmulator: 0,
            startTime: Date.now(),
            disconnectTimer: null,
            isRemoving: false
        };

        this.sessionsByToken.set(sessionData.ws_token, session);

        // Set up WebSocket handlers
        this.setupWebSocketHandlers(session);

        // Launch emulator (DOSBox or DOSEMU)
        try {
            await this.launchEmulator(session);
        } catch (err) {
            console.error(`[SESSION] Failed to launch emulator for ${sessionId}:`, err.message);
            ws.close(1011, 'Failed to start door session');
            this.removeSession(session);
        }
    }

    /**
     * Launch emulator (DOSBox or DOSEMU) using adapter pattern
     */
    async launchEmulator(session) {
        const { sessionId, sessionData } = session;

        // Create emulator adapter (auto-selects DOSBox or DOSEMU)
        session.emulator = createEmulatorAdapter(BASE_PATH);
        const emulatorName = session.emulator.getName();

        console.log(`[SESSION] Using ${emulatorName} for session ${sessionId}`);

        // Set up exit handler for emulator process
        session.emulator.onExit((code, signal) => {
            console.log(`[${emulatorName}] Process exited for session ${sessionId}: code=${code}, signal=${signal}`);
            this.handleDosBoxExit(session);
        });

        // Create session directory
        const sessionPath = path.join(BASE_PATH, 'data', 'run', 'door_sessions', sessionId);
        if (!fs.existsSync(sessionPath)) {
            fs.mkdirSync(sessionPath, { recursive: true });
            console.log(`[SESSION] Created session directory: ${sessionPath}`);
        }

        // Update session with path
        session.sessionData.session_path = sessionPath;

        // Generate DOOR.SYS drop file
        if (sessionData.user_data) {
            let userData = sessionData.user_data;
            if (typeof userData === 'string') {
                userData = JSON.parse(userData);
            }

            // Load door manifest to check for custom dropfile_path
            // DOORS directory is uppercase - Linux filesystem is case-sensitive
            const manifestPath = path.join(BASE_PATH, 'dosbox-bridge', 'dos', 'DOORS', sessionData.door_id.toUpperCase(), 'dosdoor.jsn');
            let dropPath;

            if (fs.existsSync(manifestPath)) {
                const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
                if (manifest.door && manifest.door.dropfile_path) {
                    // Use custom dropfile path from manifest (e.g., "\DOORS\BRE")
                    // Convert to actual filesystem path
                    const customPath = manifest.door.dropfile_path.replace(/\\/g, '/');
                    dropPath = path.join(BASE_PATH, 'dosbox-bridge', 'dos', customPath);
                    console.log(`[DROPFILE] Using custom dropfile_path from manifest: ${dropPath}`);
                } else {
                    // Default: node-specific drop directory
                    dropPath = path.join(BASE_PATH, 'dosbox-bridge', 'dos', 'DROPS', `NODE${sessionData.node_number}`);
                    console.log(`[DROPFILE] Using default node-based dropfile path: ${dropPath}`);
                }
            } else {
                // Manifest not found, use default
                dropPath = path.join(BASE_PATH, 'dosbox-bridge', 'dos', 'DROPS', `NODE${sessionData.node_number}`);
                console.log(`[DROPFILE] Manifest not found, using default path: ${dropPath}`);
            }

            if (!fs.existsSync(dropPath)) {
                fs.mkdirSync(dropPath, { recursive: true });
                console.log(`[DROPFILE] Created drop directory: ${dropPath}`);
            }

            console.log(`[DROPFILE] Writing DOOR.SYS to: ${dropPath}`);
            console.log(`[DROPFILE] User data:`, JSON.stringify(userData).substring(0, 200));
            this.generateDoorSys(dropPath, userData, sessionData.node_number);
            console.log(`[DROPFILE] Generated DOOR.SYS in ${dropPath}`);
        } else {
            console.warn(`[DROPFILE] No user_data found for session ${sessionId}`);
        }

        // Update database with session_path
        await this.updateSessionPath(sessionId, sessionPath);

        // For DOSBox and DOSEMU: allocate port and create TCP listener
        if (emulatorName === 'DOSBox' || emulatorName === 'DOSEMU') {
            const tcpPort = this.portPool.allocate();
            session.tcpPort = tcpPort;
            this.sessionsByPort.set(tcpPort, session);

            console.log(`[${emulatorName}] Allocated port ${tcpPort} for session ${sessionId}`);

            // Create TCP listener
            await session.emulator.createTCPListener(tcpPort, (socket) => {
                this.handleEmulatorConnection(socket, session);
            });

            // Mark as pending connection
            this.pendingDosBoxConnections.set(tcpPort, session);
        }

        // Launch emulator
        const result = await session.emulator.launch(session, sessionData);
        session.emulatorProcess = result.process;
        session.emulatorPid = result.pid;

        console.log(`[${emulatorName}] Launched PID ${result.pid} for session ${sessionId}`);

        // Note: For both DOSBox and DOSEMU, we wait for TCP connection
        // setupEmulatorHandlers will be called in handleEmulatorConnection

        // Update database
        await this.updateSessionPorts(sessionId, session.tcpPort || 0, result.pid);
    }

    /**
     * Generate DOOR.SYS drop file from user_data
     * Format: https://en.wikipedia.org/wiki/Doorway_(BBS_door)
     */
    generateDoorSys(dropPath, userData, nodeNumber) {
        // Always write as DOOR.SYS (node separation is done via directory structure)
        const doorSysPath = path.join(dropPath, 'DOOR.SYS');

        // Build DOOR.SYS content (standard 52-line format)
        const lines = [
            userData.com_port || 'COM1:',                    // 1: Comm port (COM0: = local, COM1-8: = serial)
            userData.baud_rate || '115200',                  // 2: Baud rate
            '8',                                             // 3: Parity (8 = no parity)
            userData.node || '1',                            // 4: Node number
            '115200',                                        // 5: DTE rate (locked port rate)
            'Y',                                             // 6: Screen display (Y/N)
            'Y',                                             // 7: Printer toggle (Y/N)
            'Y',                                             // 8: Page bell (Y/N)
            'Y',                                             // 9: Caller alarm (Y/N)
            userData.real_name || 'Guest',                   // 10: User's name
            userData.location || 'Unknown',                  // 11: User's location
            userData.phone || '000-000-0000',                // 12: User's phone
            userData.phone || '000-000-0000',                // 13: User's phone (again)
            userData.password || 'PASSWORD',                 // 14: Password (usually blanked)
            userData.security_level || '10',                 // 15: Security level
            userData.times_on || '1',                        // 16: Times on system
            userData.last_date || '01/01/2025',              // 17: Last date on (MM/DD/YYYY)
            userData.seconds_remaining || '7200',            // 18: Seconds remaining (this session)
            userData.minutes_remaining || '120',             // 19: Minutes remaining (this session)
            'GR',                                            // 20: Graphics mode (GR/NG/7E)
            userData.page_length || '23',                    // 21: Page length
            'N',                                             // 22: User mode (Y = expert, N = novice)
            '1,2,3,4,5,6,7',                                 // 23: Conferences registered
            '1',                                             // 24: Conference in
            userData.upload_kb || '0',                       // 25: Total KB uploaded
            userData.download_kb || '0',                     // 26: Total KB downloaded
            userData.daily_dl_limit || '0',                  // 27: Daily download limit (KB)
            userData.daily_dl_total || '0',                  // 28: Daily KB downloaded today
            userData.birthdate || '00/00/00',                // 29: Birthdate (MM/DD/YY)
            userData.registration_path || '',                // 30: Path to user registration file
            userData.door_path || '',                        // 31: Path to door info file
            userData.sysop_name || 'Sysop',                  // 32: Sysop name
            userData.alias || userData.real_name || 'Guest', // 33: User's alias/handle
            '00:05',                                         // 34: Event time (HH:MM)
            'Y',                                             // 35: Error-free connection (Y/N)
            'N',                                             // 36: ANSI supported (Y/N) - We always use ANSI
            'Y',                                             // 37: Use record locking (Y/N)
            '14',                                            // 38: Default text color
            userData.time_limit || '120',                    // 39: Time limit (minutes)
            userData.time_remaining || '7200',               // 40: Time remaining (seconds)
            '0',                                             // 41: Fossil port
            '0',                                             // 42: Fossil IRQ
            '0',                                             // 43: Fossil base address
            userData.bbs_name || 'BinktermPHP BBS',          // 44: BBS name
            userData.sysop_first || 'System',                // 45: Sysop first name
            userData.sysop_last || 'Operator',               // 46: Sysop last name
            '0',                                             // 47: Fossil port (again)
            '0',                                             // 48: Fossil IRQ (again)
            '0',                                             // 49: Fossil base address (again)
            'DOOR.SYS',                                      // 50: Drop file type
            userData.node || '1',                            // 51: Node number (again)
            '115200'                                         // 52: DTE rate (again)
        ];

        // Write DOOR.SYS file (DOS CRLF line endings)
        const content = lines.join('\r\n') + '\r\n';
        try {
            fs.writeFileSync(doorSysPath, content, 'ascii');
            console.log(`[DROPFILE] Wrote ${content.length} bytes to ${doorSysPath}`);

            // Verify the write by reading back immediately
            const stats = fs.statSync(doorSysPath);
            console.log(`[DROPFILE] Verification: file size is ${stats.size} bytes`);
            if (stats.size !== content.length) {
                console.error(`[DROPFILE] WARNING: Size mismatch! Expected ${content.length}, got ${stats.size}`);
            }
        } catch (err) {
            console.error(`[DROPFILE] Failed to write DOOR.SYS: ${err.message}`);
            throw err;
        }
    }

    generateDosBoxConfig(session, tcpPort) {
        const { sessionData } = session;
        // session_path is now set by bridge
        const sessionPath = sessionData.session_path;

        // Read base config template
        const headless = process.env.DOSDOOR_HEADLESS !== 'false';
        const configTemplate = headless
            ? path.join(BASE_PATH, 'dosbox-bridge', 'dosbox-bridge-production.conf')
            : path.join(BASE_PATH, 'dosbox-bridge', 'dosbox-bridge-test.conf');

        let config = fs.readFileSync(configTemplate, 'utf8');

        // Replace port placeholder
        config = config.replace(/port:5000/g, `port:${tcpPort}`);

        // Get door manifest to build launch command
        // DOORS directory is uppercase - Linux filesystem is case-sensitive
        const manifestPath = path.join(BASE_PATH, 'dosbox-bridge', 'dos', 'DOORS', sessionData.door_id.toUpperCase(), 'dosdoor.jsn');
        const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));

        // Build door launch command
        const doorDir = manifest.door.directory.replace('dosbox-bridge/dos', '').replace(/\//g, '\\');
        const dropDir = `\\DROPS\\NODE${sessionData.node_number}`;

        let launchCmd = manifest.door.launch_command || `call ${manifest.door.executable}`;
        launchCmd = launchCmd.replace('{node}', sessionData.node_number);
        launchCmd = launchCmd.replace('{dropfile}', 'DOOR.SYS');

        // Build autoexec commands
        // Copy DOOR.SYS to door directory, CD there, then launch
        const autoexecCommands = `copy ${dropDir}\\DOOR.SYS ${doorDir}\\DOOR.SYS\ncd ${doorDir}\n${launchCmd}`;

        // Replace autoexec placeholder
        config = config.replace('# Door-specific commands will be appended here', autoexecCommands);

        // Write session-specific config
        const configPath = path.join(sessionPath, 'dosbox.conf');
        fs.writeFileSync(configPath, config);

        return configPath;
    }

    async launchDosBox(session, configPath) {
        const dosboxExe = this.findDosBoxExecutable();
        if (!dosboxExe) {
            throw new Error('DOSBox executable not found');
        }

        const headless = process.env.DOSDOOR_HEADLESS !== 'false';
        const isLinux = process.platform !== 'win32';

        // Build arguments based on platform and headless mode
        let args;
        if (headless) {
            if (isLinux) {
                // Linux: Use -noconsole for true headless
                args = ['-noconsole', '-conf', configPath, '-exit'];
            } else {
                // Windows: Use -nogui
                args = ['-nogui', '-conf', configPath, '-exit'];
            }
        } else {
            args = ['-conf', configPath, '-exit'];
        }

        // Set up spawn options
        const spawnOptions = {
            cwd: BASE_PATH,
            detached: false,
            stdio: 'ignore'
        };

        // Linux: Set SDL_VIDEODRIVER=dummy for true headless operation
        if (isLinux && headless) {
            spawnOptions.env = {
                ...process.env,
                SDL_VIDEODRIVER: 'dummy'
            };
            console.log(`[DOSBOX] Linux headless mode: SDL_VIDEODRIVER=dummy`);
        }

        console.log(`[DOSBOX] Spawning: ${dosboxExe} ${args.join(' ')}`);

        const dosboxProcess = spawn(dosboxExe, args, spawnOptions);

        session.dosboxProcess = dosboxProcess;

        dosboxProcess.on('error', (err) => {
            console.error(`[DOSBOX] Process error for session ${session.sessionId}:`, err.message);
        });

        dosboxProcess.on('exit', (code, signal) => {
            console.log(`[DOSBOX] Process exited for session ${session.sessionId}: code=${code}, signal=${signal}`);
            this.handleDosBoxExit(session);
        });

        return dosboxProcess.pid;
    }

    findDosBoxExecutable() {
        const envPath = process.env.DOSBOX_EXECUTABLE;
        if (envPath && fs.existsSync(envPath)) {
            console.log(`[DOSBOX] Using configured executable: ${envPath}`);
            return envPath;
        }

        // Prefer vanilla DOSBox (much lighter: ~30MB vs ~256MB RSS)
        // Try dosbox first, fall back to dosbox-x
        const candidates = process.platform === 'win32'
            ? ['dosbox.exe', 'dosbox-x.exe', 'c:\\dosbox\\dosbox.exe', 'c:\\dosbox-x\\dosbox-x.exe']
            : ['dosbox', 'dosbox-x'];

        // Check which executables exist in PATH
        for (const candidate of candidates) {
            try {
                const result = require('child_process').spawnSync(
                    process.platform === 'win32' ? 'where' : 'which',
                    [candidate],
                    { encoding: 'utf8' }
                );
                if (result.status === 0 && result.stdout.trim()) {
                    const exePath = result.stdout.trim().split('\n')[0];
                    console.log(`[DOSBOX] Found executable: ${exePath}`);
                    return candidate;
                }
            } catch (e) {
                // Command not found, try next
            }
        }

        // Fallback: assume dosbox is in PATH
        console.log('[DOSBOX] No DOSBox found in PATH, trying "dosbox"');
        return 'dosbox';
    }

    handleDosBoxConnection(socket, session) {
        console.log(`[DOSBOX] Connection received for session ${session.sessionId}`);

        // Remove from pending
        this.pendingDosBoxConnections.delete(session.tcpPort);

        // Store DOSBox socket
        session.dosboxSocket = socket;

        // Set up DOSBox handlers
        this.setupDosBoxHandlers(session);

        // Start multiplexing
        console.log(`[SESSION] Multiplexing started for session ${session.sessionId}`);
    }

    /**
     * Handle emulator connection (adapter-based, supports DOSBox and DOSEMU)
     */
    handleEmulatorConnection(socket, session) {
        const emulatorName = session.emulator.getName();
        console.log(`[${emulatorName}] Connection received for session ${session.sessionId}`);

        // Remove from pending (DOSBox only)
        if (session.tcpPort) {
            this.pendingDosBoxConnections.delete(session.tcpPort);
        }

        session.emulatorSocket = socket;
        this.setupEmulatorHandlers(session);

        console.log(`[SESSION] Multiplexing started for session ${session.sessionId}`);
    }

    /**
     * Set up emulator data handlers (adapter-based)
     */
    setupEmulatorHandlers(session) {
        const { sessionId } = session;
        const emulatorName = session.emulator.getName();

        // Set up data flow: Emulator -> WebSocket
        session.emulator.onData((data) => {
            session.bytesFromEmulator += data.length;

            try {
                // For DOSBox: convert CP437 to UTF-8
                // For DOSEMU: data is already in correct format from PTY
                const utf8Data = (emulatorName === 'DOSBox')
                    ? iconv.decode(data, 'cp437')
                    : data.toString('utf8');

                if (session.ws && session.ws.readyState === WebSocket.OPEN) {
                    session.ws.send(utf8Data);
                } else {
                    console.warn(`[${emulatorName}->WS] WebSocket not ready, dropping ${data.length} bytes`);
                }
            } catch (err) {
                console.error(`[${emulatorName}] Encoding error for session ${sessionId}:`, err.message);
            }
        });
    }

    setupDosBoxHandlers(session) {
        const { dosboxSocket, sessionId } = session;

        dosboxSocket.setNoDelay(true);

        dosboxSocket.on('data', (data) => {
            session.bytesFromDosbox += data.length;

            // Convert CP437 (DOS) to UTF-8
            try {
                const utf8Data = iconv.decode(data, 'cp437');

                // Forward to WebSocket client if connected
                if (session.ws && session.ws.readyState === WebSocket.OPEN) {
                    session.ws.send(utf8Data);
                } else {
                    console.warn(`[DOSBOX->WS] WebSocket not ready, dropping ${data.length} bytes`);
                }
            } catch (err) {
                console.error(`[DOSBOX] Encoding error for session ${sessionId}:`, err.message);
            }
        });

        dosboxSocket.on('close', () => {
            console.log(`[DOSBOX] Connection closed for session ${sessionId}`);
            this.handleDosBoxDisconnect(session);
        });

        dosboxSocket.on('error', (err) => {
            console.error(`[DOSBOX] Socket error for session ${sessionId}:`, err.message);
        });
    }

    setupWebSocketHandlers(session) {
        const { ws, sessionId } = session;

        ws.on('message', (data) => {
            session.bytesToEmulator += data.length;

            try {
                const dataStr = data.toString('utf8');

                // For DOSBox: convert UTF-8 to CP437
                // For DOSEMU: pass through as UTF-8
                const emulatorName = session.emulator ? session.emulator.getName() : 'Unknown';
                const emulatorData = (emulatorName === 'DOSBox')
                    ? iconv.encode(dataStr, 'cp437')
                    : Buffer.from(dataStr, 'utf8');

                // Forward to emulator
                if (session.emulator) {
                    session.emulator.write(emulatorData);
                } else {
                    console.warn(`[WS->EMULATOR] Emulator not ready, dropping ${data.length} bytes`);
                }
            } catch (err) {
                console.error(`[WS] Encoding error for session ${sessionId}:`, err.message);
            }
        });

        ws.on('close', (code, reason) => {
            console.log(`[WS] Client disconnected for session ${sessionId}: ${code} ${reason}`);
            this.handleWebSocketDisconnect(session);
        });

        ws.on('error', (err) => {
            console.error(`[WS] WebSocket error for session ${sessionId}:`, err.message);
        });
    }

    handleDosBoxExit(session) {
        console.log(`[SESSION] DOSBox exited for session ${session.sessionId}`);

        // Clear kill timeout since process exited gracefully
        if (session.killTimeout) {
            clearTimeout(session.killTimeout);
            session.killTimeout = null;
        }

        // Mark that DOSBox has exited (so WebSocket handler knows to clean up immediately)
        session.dosboxExited = true;

        // Close WebSocket - this will trigger handleWebSocketDisconnect which does cleanup
        if (session.ws && session.ws.readyState === WebSocket.OPEN) {
            session.ws.close(1000, 'Door session ended');
        } else {
            // WebSocket already closed, do cleanup directly
            this.removeSession(session);
        }
    }

    handleDosBoxDisconnect(session) {
        console.log(`[SESSION] DOSBox disconnected for session ${session.sessionId}`);

        // DOSBox TCP connection closed (usually means 'exit' command ran)
        // Give DOSBox a few seconds to exit gracefully, then force kill if needed
        if (session.dosboxProcess && session.dosboxPid) {
            console.log(`[SESSION] Waiting 3 seconds for DOSBox PID ${session.dosboxPid} to exit gracefully...`);

            session.killTimeout = setTimeout(() => {
                console.log(`[SESSION] DOSBox didn't exit gracefully, force killing PID ${session.dosboxPid}`);
                try {
                    if (process.platform === 'win32') {
                        require('child_process').execSync(`taskkill /F /PID ${session.dosboxPid}`, { stdio: 'ignore' });
                    } else {
                        process.kill(session.dosboxPid, 'SIGKILL');
                    }
                } catch (err) {
                    console.error(`[SESSION] Failed to kill DOSBox PID ${session.dosboxPid}:`, err.message);
                }
                // handleDosBoxExit will be called when process actually dies
            }, 3000); // 3 second grace period
        }
    }

    handleWebSocketDisconnect(session) {
        // If DOSBox already exited, clean up immediately (no grace period)
        if (session.dosboxExited) {
            console.log(`[WS] DOSBox exited, cleaning up session ${session.sessionId}`);
            this.removeSession(session);
            return;
        }

        // User closed browser but DOSBox still running
        if (DISCONNECT_TIMEOUT === 0) {
            // Immediate disconnect mode - kill DOSBox and clean up
            console.log(`[WS] Immediate disconnect mode - removing session ${session.sessionId}`);
            this.removeSession(session);
        } else {
            // Grace period mode - keep DOSBox running, allow reconnect
            console.log(`[WS] Disconnect grace period: ${DISCONNECT_TIMEOUT} minutes for session ${session.sessionId}`);

            session.disconnectTimer = setTimeout(() => {
                console.log(`[WS] Grace period expired - removing session ${session.sessionId}`);
                this.removeSession(session);
            }, DISCONNECT_TIMEOUT * 60 * 1000);
        }
    }

    removeSession(session) {
        // Prevent double cleanup
        if (session.isRemoving) {
            console.log(`[SESSION] Already removing session ${session.sessionId}, skipping`);
            return;
        }
        session.isRemoving = true;

        console.log(`[SESSION] Removing session ${session.sessionId}`);

        // Clear disconnect timer
        if (session.disconnectTimer) {
            clearTimeout(session.disconnectTimer);
        }

        // Close emulator TCP connection (simulates carrier loss)
        if (session.emulator) {
            session.emulator.close();
        }

        // Give emulator process time to detect carrier loss and exit gracefully
        // Then force kill if still running
        if (session.emulatorProcess && session.emulatorPid) {
            const timeoutSec = (CARRIER_LOSS_TIMEOUT / 1000).toFixed(1);
            console.log(`[SESSION] Waiting ${timeoutSec} seconds for emulator PID ${session.emulatorPid} to exit after carrier loss...`);
            setTimeout(() => {
                // Check if process still running
                try {
                    process.kill(session.emulatorPid, 0); // Signal 0 checks if process exists
                    console.log(`[SESSION] Emulator PID ${session.emulatorPid} still running, force killing`);
                    if (process.platform === 'win32') {
                        require('child_process').execSync(`taskkill /F /PID ${session.emulatorPid}`, { stdio: 'ignore' });
                    } else {
                        process.kill(session.emulatorPid, 'SIGKILL');
                    }
                } catch (err) {
                    // Process already exited, good
                    console.log(`[SESSION] Emulator PID ${session.emulatorPid} already exited`);
                }
            }, CARRIER_LOSS_TIMEOUT);
        }

        // Close WebSocket
        if (session.ws && session.ws.readyState === WebSocket.OPEN) {
            session.ws.close();
        }

        // Release port (DOSBox only)
        if (session.tcpPort) {
            this.portPool.release(session.tcpPort);
            this.sessionsByPort.delete(session.tcpPort);
            this.pendingDosBoxConnections.delete(session.tcpPort);
        }

        // Remove from maps
        if (session.sessionData && session.sessionData.ws_token) {
            this.sessionsByToken.delete(session.sessionData.ws_token);
        }

        // Clean up session files and directory
        this.cleanupSessionFiles(session);

        // Delete session from database
        this.deleteSession(session.sessionId);

        // Log statistics
        const uptime = Math.floor((Date.now() - session.startTime) / 1000);
        console.log(`[SESSION] Stats - ${session.sessionId}: Uptime ${uptime}s, From Emulator: ${session.bytesFromEmulator}, To Emulator: ${session.bytesToEmulator}`);
    }

    cleanupSessionFiles(session) {
        const { sessionData, sessionId } = session;

        if (!sessionData || !sessionData.session_path) {
            return;
        }

        const sessionPath = sessionData.session_path;

        try {
            // Skip cleanup if debug mode is enabled
            if (DEBUG_KEEP_FILES) {
                console.log(`[CLEANUP] Debug mode - keeping files for session ${sessionId}`);
                return;
            }

            // Remove DOOR.SYS file from drop directory
            const dropPath = path.join(BASE_PATH, 'dosbox-bridge', 'dos', 'DROPS', `NODE${sessionData.node_number}`);
            const doorSysPath = path.join(dropPath, 'DOOR.SYS');
            if (fs.existsSync(doorSysPath)) {
                fs.unlinkSync(doorSysPath);
                console.log(`[CLEANUP] Removed DOOR.SYS from drop directory for session ${sessionId}`);
            }

            // Check if session directory exists
            if (!fs.existsSync(sessionPath)) {
                console.log(`[CLEANUP] Session directory does not exist: ${sessionPath}`);
                return;
            }

            // Remove DOSBox config
            const configPath = path.join(sessionPath, 'dosbox.conf');
            if (fs.existsSync(configPath)) {
                fs.unlinkSync(configPath);
                console.log(`[CLEANUP] Removed dosbox.conf for session ${sessionId}`);
            }

            // Remove session directory (if empty or force remove all contents)
            const files = fs.readdirSync(sessionPath);
            if (files.length === 0) {
                fs.rmdirSync(sessionPath);
                console.log(`[CLEANUP] Removed session directory ${sessionId}`);
            } else {
                // Directory has other files - remove them all and the directory
                for (const file of files) {
                    const filePath = path.join(sessionPath, file);
                    fs.unlinkSync(filePath);
                }
                fs.rmdirSync(sessionPath);
                console.log(`[CLEANUP] Removed session directory ${sessionId} and ${files.length} remaining files`);
            }

        } catch (err) {
            console.error(`[CLEANUP] Error cleaning up session ${sessionId}:`, err.message);
            // Don't throw - cleanup should be best-effort
        }
    }

    getStats() {
        return {
            activeSessions: this.sessionsByToken.size,
            availablePorts: TCP_PORT_MAX - TCP_PORT_BASE - this.portPool.usedPorts.size,
            sessions: Array.from(this.sessionsByToken.values()).map(s => ({
                sessionId: s.sessionId,
                emulator: s.emulator ? s.emulator.getName() : 'Unknown',
                tcpPort: s.tcpPort,
                uptime: Math.floor((Date.now() - s.startTime) / 1000),
                bytesFromEmulator: s.bytesFromEmulator,
                bytesToEmulator: s.bytesToEmulator,
                wsConnected: s.ws && s.ws.readyState === WebSocket.OPEN,
                emulatorConnected: s.emulatorSocket != null,
                emulatorPid: s.emulatorPid
            }))
        };
    }
}

// Create port pool and session manager
const portPool = new PortPool(TCP_PORT_BASE, TCP_PORT_MAX);
const sessionManager = new SessionManager(portPool);

// Create WebSocket server
const wsServer = new WebSocket.Server({
    host: WS_BIND_HOST,
    port: WS_PORT,
    clientTracking: true
});

console.log(`[WS] Server listening on ${WS_BIND_HOST}:${WS_PORT}`);

// Clean up stale sessions from database on startup
// This handles sessions that weren't cleaned up if bridge crashed/was killed
(async () => {
    const client = new Client(DB_CONFIG);
    try {
        await client.connect();
        const result = await client.query(`
            UPDATE door_sessions
            SET ended_at = NOW(), exit_status = 'bridge_restart'
            WHERE ended_at IS NULL
        `);
        if (result.rowCount > 0) {
            console.log(`[STARTUP] Cleaned up ${result.rowCount} stale session(s) from database`);
        }
    } catch (err) {
        console.error('[STARTUP] Failed to clean up stale sessions:', err.message);
    } finally {
        await client.end();
    }
})();

console.log('[WS] Waiting for connections...');
console.log('');

wsServer.on('connection', async (ws, req) => {
    const clientIp = req.socket.remoteAddress;
    console.log('[WS] New connection from', clientIp);

    // Parse token from query string
    const queryParams = url.parse(req.url, true).query;
    const token = queryParams.token;

    if (!token) {
        console.warn('[WS] No token provided - rejecting connection');
        ws.close(1008, 'Authentication token required');
        return;
    }

    // Authenticate and get session from database
    const sessionData = await sessionManager.findSessionByToken(token);

    if (!sessionData) {
        console.warn('[WS] Authentication failed - rejecting connection');
        ws.close(1008, 'Invalid or expired token');
        return;
    }

    console.log('[WS] Authentication successful');

    // Handle WebSocket connection (this will allocate port and launch DOSBox)
    try {
        await sessionManager.handleWebSocketConnection(ws, sessionData);
    } catch (err) {
        console.error('[WS] Failed to handle connection:', err.message);
        ws.close(1011, 'Internal server error');
    }
});

wsServer.on('error', (err) => {
    console.error('[WS] Server error:', err.message);
});

// Status reporting (every 60 seconds) — only log when sessions are active
setInterval(() => {
    const stats = sessionManager.getStats();
    if (stats.activeSessions > 0) {
        console.log('[STATUS] Active sessions:', stats.activeSessions, '| Available ports:', stats.availablePorts);
        stats.sessions.forEach(s => {
            console.log(`  - ${s.sessionId} (port ${s.tcpPort}, PID ${s.emulatorPid}): ${s.uptime}s, WS:${s.wsConnected}, Emulator:${s.emulatorConnected}`);
        });
    }
}, 60000);

// Graceful shutdown
process.on('SIGINT', () => {
    console.log('\n[SHUTDOWN] Received SIGINT, closing all connections...');

    // Close all sessions
    for (const session of sessionManager.sessionsByToken.values()) {
        sessionManager.removeSession(session);
    }

    // Close WebSocket server
    wsServer.close(() => {
        console.log('[SHUTDOWN] Server closed');
        process.exit(0);
    });
});

process.on('SIGTERM', () => {
    console.log('\n[SHUTDOWN] Received SIGTERM, closing all connections...');

    // Close all sessions
    for (const session of sessionManager.sessionsByToken.values()) {
        sessionManager.removeSession(session);
    }

    // Close WebSocket server
    wsServer.close(() => {
        console.log('[SHUTDOWN] Server closed');
        process.exit(0);
    });
});

// Handle uncaught errors
process.on('uncaughtException', (err) => {
    console.error('[ERROR] Uncaught exception:', err);
});

process.on('unhandledRejection', (reason, promise) => {
    console.error('[ERROR] Unhandled rejection:', reason);
});

console.log('Bridge server started successfully!');
console.log('Press Ctrl+C to stop.');
