#!/usr/bin/env node
/**
 * DOSBox Door Bridge - Multiplexing Server v3
 *
 * Architecture:
 * - PHP creates session record in database (with ws_token, session_path, etc.)
 * - Browser connects to WebSocket with auth token
 * - Bridge authenticates, allocates port, creates listener, generates config, launches DOSBox
 * - DOSBox connects to bridge's allocated port
 * - Bridge multiplexes data between WebSocket and DOSBox
 *
 * This gives bridge full control over lifecycle and eliminates port conflicts.
 */

const net = require('net');
const WebSocket = require('ws');
const iconv = require('iconv-lite');
const url = require('url');
const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');
const { Client } = require('pg');
require('dotenv').config({ path: __dirname + '/../../.env' });

// Configuration from environment
const WS_PORT = parseInt(process.env.DOSDOOR_WS_PORT) || 6001;
const WS_BIND_HOST = process.env.DOSDOOR_WS_BIND_HOST || '127.0.0.1';
const DISCONNECT_TIMEOUT = parseInt(process.env.DOSDOOR_DISCONNECT_TIMEOUT) || 0;
const DEBUG_KEEP_FILES = process.env.DOSDOOR_DEBUG_KEEP_FILES === 'true'; // Set to 'true' to disable cleanup
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
            dosboxSocket: null,
            tcpPort: null,
            tcpServer: null,
            dosboxProcess: null,
            dosboxPid: null,
            bytesFromDosbox: 0,
            bytesToDosbox: 0,
            startTime: Date.now(),
            disconnectTimer: null,
            isRemoving: false
        };

        this.sessionsByToken.set(sessionData.ws_token, session);

        // Set up WebSocket handlers
        this.setupWebSocketHandlers(session);

        // Allocate port and launch DOSBox
        try {
            await this.allocatePortAndLaunchDosBox(session);
        } catch (err) {
            console.error(`[SESSION] Failed to launch DOSBox for ${sessionId}:`, err.message);
            ws.close(1011, 'Failed to start door session');
            this.removeSession(session);
        }
    }

    async allocatePortAndLaunchDosBox(session) {
        const { sessionId, sessionData } = session;

        // Allocate TCP port
        const tcpPort = this.portPool.allocate();
        session.tcpPort = tcpPort;
        this.sessionsByPort.set(tcpPort, session);

        console.log(`[SESSION] Allocated port ${tcpPort} for session ${sessionId}`);

        // Create session directory
        const sessionPath = path.join(BASE_PATH, 'data', 'run', 'door_sessions', sessionId);
        if (!fs.existsSync(sessionPath)) {
            fs.mkdirSync(sessionPath, { recursive: true });
            console.log(`[SESSION] Created session directory: ${sessionPath}`);
        }

        // Update session with path
        session.sessionData.session_path = sessionPath;

        // Generate DOOR.SYS drop file from user_data in node-specific drop directory
        if (sessionData.user_data) {
            // Parse user_data if it's a JSON string
            let userData = sessionData.user_data;
            if (typeof userData === 'string') {
                userData = JSON.parse(userData);
            }

            // Create drop directory for this node: dosbox-bridge/dos/drops/node{X}/
            const dropPath = path.join(BASE_PATH, 'dosbox-bridge', 'dos', 'drops', `node${sessionData.node_number}`);
            if (!fs.existsSync(dropPath)) {
                fs.mkdirSync(dropPath, { recursive: true });
                console.log(`[DROPFILE] Created drop directory: ${dropPath}`);
            }

            console.log(`[DROPFILE] Writing DOOR.SYS to: ${dropPath}`);
            console.log(`[DROPFILE] User data:`, userData);

            // Write DOOR.SYS (without node number in filename)
            this.generateDoorSys(dropPath, userData, sessionData.node_number);
            console.log(`[DROPFILE] Generated DOOR.SYS in ${dropPath}`);
        } else {
            console.warn(`[DROPFILE] No user_data found for session ${sessionId}`);
        }

        // Update database with session_path
        await this.updateSessionPath(sessionId, sessionPath);

        // Create TCP listener for DOSBox connection
        session.tcpServer = net.createServer((socket) => {
            this.handleDosBoxConnection(socket, session);
        });

        // Listen on allocated port
        await new Promise((resolve, reject) => {
            session.tcpServer.listen(tcpPort, '127.0.0.1', () => {
                console.log(`[TCP] Listening on port ${tcpPort} for session ${sessionId}`);
                resolve();
            });

            session.tcpServer.on('error', (err) => {
                console.error(`[TCP] Server error on port ${tcpPort}:`, err.message);
                reject(err);
            });
        });

        // Mark as pending DOSBox connection
        this.pendingDosBoxConnections.set(tcpPort, session);

        // Generate DOSBox config
        const configPath = this.generateDosBoxConfig(session, tcpPort);
        console.log(`[CONFIG] Generated DOSBox config: ${configPath}`);

        // Launch DOSBox
        const dosboxPid = await this.launchDosBox(session, configPath);
        session.dosboxPid = dosboxPid;

        console.log(`[DOSBOX] Launched DOSBox PID ${dosboxPid} for session ${sessionId}`);

        // Update database with tcp_port and dosbox_pid
        await this.updateSessionPorts(sessionId, tcpPort, dosboxPid);
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
        fs.writeFileSync(doorSysPath, content, 'ascii');
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
        const manifestPath = path.join(BASE_PATH, 'dosbox-bridge', 'dos', 'doors', sessionData.door_id, 'dosdoor.json');
        const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));

        // Build door launch command
        const doorDir = manifest.door.directory.replace('dosbox-bridge/dos', '').replace(/\//g, '\\');
        const dropDir = `\\drops\\node${sessionData.node_number}`;

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
        const args = headless
            ? ['-nogui', '-conf', configPath, '-exit']
            : ['-conf', configPath, '-exit'];

        console.log(`[DOSBOX] Spawning: ${dosboxExe} ${args.join(' ')}`);

        const dosboxProcess = spawn(dosboxExe, args, {
            cwd: BASE_PATH,
            detached: false,
            stdio: 'ignore'
        });

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
            return envPath;
        }

        // Windows default
        if (process.platform === 'win32') {
            const defaultPath = 'c:\\dosbox-x\\dosbox-x.exe';
            if (fs.existsSync(defaultPath)) {
                return defaultPath;
            }
        }

        // Try PATH
        return 'dosbox-x'; // Hope it's in PATH
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
            session.bytesToDosbox += data.length;

            // Convert UTF-8 to CP437 for DOSBox
            try {
                const dataStr = data.toString('utf8');
                const cp437Data = iconv.encode(dataStr, 'cp437');

                // Forward to DOSBox
                if (session.dosboxSocket && !session.dosboxSocket.destroyed) {
                    session.dosboxSocket.write(cp437Data);
                } else {
                    console.warn(`[WS->DOSBOX] DOSBox not ready, dropping ${data.length} bytes`);
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

        // Notify WebSocket client
        if (session.ws && session.ws.readyState === WebSocket.OPEN) {
            session.ws.close(1000, 'DOSBox session ended');
        }

        // Clean up session
        this.removeSession(session);
    }

    handleDosBoxDisconnect(session) {
        console.log(`[SESSION] DOSBox disconnected for session ${session.sessionId}`);

        // If DOSBox disconnects but process still running, it exited normally
        // Wait for process exit event to clean up
    }

    handleWebSocketDisconnect(session) {
        if (DISCONNECT_TIMEOUT === 0) {
            // Immediate disconnect mode
            console.log(`[WS] Immediate disconnect mode - removing session ${session.sessionId}`);
            this.removeSession(session);
        } else {
            // Grace period mode
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

        // Kill DOSBox process if still running
        if (session.dosboxProcess && !session.dosboxProcess.killed) {
            console.log(`[DOSBOX] Killing process PID ${session.dosboxPid}`);
            try {
                session.dosboxProcess.kill('SIGTERM');
            } catch (err) {
                console.warn(`[DOSBOX] Failed to kill process:`, err.message);
            }
        }

        // Close DOSBox socket
        if (session.dosboxSocket && !session.dosboxSocket.destroyed) {
            session.dosboxSocket.destroy();
        }

        // Close TCP server
        if (session.tcpServer) {
            session.tcpServer.close();
        }

        // Close WebSocket
        if (session.ws && session.ws.readyState === WebSocket.OPEN) {
            session.ws.close();
        }

        // Release port
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
        console.log(`[SESSION] Stats - ${session.sessionId}: Uptime ${uptime}s, From DOSBox: ${session.bytesFromDosbox}, To DOSBox: ${session.bytesToDosbox}`);
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
            const dropPath = path.join(BASE_PATH, 'dosbox-bridge', 'dos', 'drops', `node${sessionData.node_number}`);
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
                tcpPort: s.tcpPort,
                uptime: Math.floor((Date.now() - s.startTime) / 1000),
                bytesFromDosbox: s.bytesFromDosbox,
                bytesToDosbox: s.bytesToDosbox,
                wsConnected: s.ws && s.ws.readyState === WebSocket.OPEN,
                dosboxConnected: s.dosboxSocket && !s.dosboxSocket.destroyed,
                dosboxPid: s.dosboxPid
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

// Status reporting (every 60 seconds)
setInterval(() => {
    const stats = sessionManager.getStats();
    console.log('[STATUS] Active sessions:', stats.activeSessions, '| Available ports:', stats.availablePorts);
    if (stats.activeSessions > 0) {
        stats.sessions.forEach(s => {
            console.log(`  - ${s.sessionId} (port ${s.tcpPort}, PID ${s.dosboxPid}): ${s.uptime}s, WS:${s.wsConnected}, DOS:${s.dosboxConnected}`);
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
