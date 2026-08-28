/**
 * Emulator Adapter Pattern
 * Supports multiple DOS emulators (DOSBox, DOSEMU) with a common interface
 */

const net = require('net');
const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');
const pty = require('node-pty');
const { Client } = require('pg');

/**
 * Build a pg connection config from process.env. Mirrors the DB_CONFIG
 * construction in multiplexing-server.js exactly. Read lazily (inside a
 * function, not at module load time) since this module is required before
 * multiplexing-server.js calls dotenv.config().
 */
function buildDbConfig() {
    return {
        host: process.env.DB_HOST || 'localhost',
        port: parseInt(process.env.DB_PORT) || 5432,
        database: process.env.DB_NAME || 'binktest',
        user: process.env.DB_USER || 'binktest',
        password: process.env.DB_PASS || 'binktest',
        ssl: process.env.DB_SSL === 'true' ? { rejectUnauthorized: false } : false
    };
}

/**
 * Base Emulator Adapter
 * Defines the interface all emulators must implement
 */
class EmulatorAdapter {
    constructor(basePath) {
        this.basePath = basePath;
    }

    /**
     * Launch the emulator with the door game
     * @param {Object} session - Session object
     * @param {Object} sessionData - Session data from database
     * @returns {Promise<Object>} - { process, pid, connection }
     */
    async launch(session, sessionData) {
        throw new Error('launch() must be implemented by subclass');
    }

    /**
     * Set up data handlers (emulator -> WebSocket)
     * @param {Function} onData - Callback for data from emulator
     */
    onData(onData) {
        throw new Error('onData() must be implemented by subclass');
    }

    /**
     * Set up exit handler (called when emulator process exits)
     * @param {Function} onExit - Callback for process exit (code, signal)
     */
    onExit(onExit) {
        this.exitCallback = onExit;
    }

    /**
     * Write data to emulator (WebSocket -> emulator)
     * @param {Buffer} data - Data to write
     */
    write(data) {
        throw new Error('write() must be implemented by subclass');
    }

    /**
     * Close/cleanup the emulator connection
     */
    close() {
        throw new Error('close() must be implemented by subclass');
    }

    /**
     * Resize the terminal (no-op for adapters that don't support it)
     * @param {number} cols
     * @param {number} rows
     */
    resize(cols, rows) {
        // No-op by default; overridden by NativeAdapter
    }

    /**
     * Get emulator name for logging
     */
    getName() {
        return 'Unknown';
    }
}

/**
 * DOSBox Adapter
 * Uses TCP nullmodem connection
 */
class DOSBoxAdapter extends EmulatorAdapter {
    constructor(basePath) {
        super(basePath);
        this.tcpServer = null;
        this.socket = null;
        this.process = null;
    }

    getName() {
        return 'DOSBox';
    }

    async launch(session, sessionData) {
        const { session_id, node_number, door_id, session_path } = sessionData;
        const slog = this.slog || console;

        // Allocate TCP port (handled by caller, passed in session.tcpPort)
        const tcpPort = session.tcpPort;

        slog.log(`[${this.getName()}] Launching for session ${session_id} on port ${tcpPort}`);

        // Generate DOSBox config
        const configPath = this.generateConfig(sessionData, session_path, tcpPort);
        slog.log(`[${this.getName()}] Generated config: ${configPath}`);

        // Find DOSBox executable
        const dosboxExe = this.findExecutable();
        if (!dosboxExe) {
            throw new Error('DOSBox executable not found');
        }

        // Build launch arguments
        const headless = process.env.DOSDOOR_HEADLESS !== 'false';
        const isLinux = process.platform !== 'win32';

        let args;
        if (headless) {
            args = isLinux
                ? ['-noconsole', '-conf', configPath, '-exit']
                : ['-nogui', '-conf', configPath, '-exit'];
        } else {
            args = ['-conf', configPath, '-exit'];
        }

        // Spawn options
        const spawnOptions = {
            cwd: this.basePath,
            detached: false,
            stdio: 'ignore'
        };

        // Linux headless: set SDL_VIDEODRIVER=dummy
        if (isLinux && headless) {
            spawnOptions.env = {
                ...process.env,
                SDL_VIDEODRIVER: 'dummy'
            };
        }

        slog.log(`[${this.getName()}] Spawning: ${dosboxExe} ${args.join(' ')}`);

        // Launch DOSBox
        this.process = spawn(dosboxExe, args, spawnOptions);

        this.process.on('error', (err) => {
            slog.error(`[${this.getName()}] Process error:`, err.message);
        });

        this.process.on('exit', (code, signal) => {
            slog.log(`[${this.getName()}] Process exited: code=${code}, signal=${signal}`);
            if (this.exitCallback) {
                this.exitCallback(code, signal);
            }
        });

        return {
            process: this.process,
            pid: this.process.pid,
            tcpPort: tcpPort
        };
    }

    /**
     * Set up TCP server to accept DOSBox connection
     */
    async createTCPListener(tcpPort, onConnection) {
        const slog = this.slog || console;
        return new Promise((resolve, reject) => {
            this.tcpServer = net.createServer((socket) => {
                slog.log(`[${this.getName()}] Connection received on port ${tcpPort}`);
                this.socket = socket;
                socket.setNoDelay(true);
                onConnection(socket);
            });

            this.tcpServer.listen(tcpPort, '127.0.0.1', () => {
                slog.log(`[${this.getName()}] TCP listener ready on port ${tcpPort}`);
                resolve();
            });

            this.tcpServer.on('error', (err) => {
                slog.error(`[${this.getName()}] TCP server error:`, err.message);
                reject(err);
            });
        });
    }

    onData(callback) {
        if (this.socket) {
            this.socket.on('data', callback);
        }
    }

    write(data) {
        if (this.socket && !this.socket.destroyed) {
            this.socket.write(data);
        }
    }

    close() {
        const slog = this.slog || console;
        // Close TCP socket first to simulate carrier loss (lost carrier event)
        // This allows the door game to detect the disconnection and exit gracefully
        if (this.socket) {
            slog.log(`[${this.getName()}] Closing TCP socket (simulating carrier loss)`);
            this.socket.destroy();
            this.socket = null;
        }
        if (this.tcpServer) {
            this.tcpServer.close();
            this.tcpServer = null;
        }

        // Don't kill process immediately - let it detect carrier loss
        // The process exit handler or removeSession will handle killing if needed
        // Process will be killed by timeout if it doesn't exit on carrier loss
    }

    generateConfig(sessionData, sessionPath, tcpPort) {
        const { door_id, node_number } = sessionData;

        // Read base config template
        const headless = process.env.DOSDOOR_HEADLESS !== 'false';
        const configTemplate = headless
            ? path.join(this.basePath, 'dosbox-bridge', 'dosbox-bridge-production.conf')
            : path.join(this.basePath, 'dosbox-bridge', 'dosbox-bridge-test.conf');

        let config = fs.readFileSync(configTemplate, 'utf8');

        // Replace port placeholder
        config = config.replace(/port:5000/g, `port:${tcpPort}`);

        // Get door manifest to build launch command
        // DOORS directory is uppercase - Linux filesystem is case-sensitive
        const manifestPath = path.join(this.basePath, 'dosbox-bridge', 'dos', 'DOORS', door_id.toUpperCase(), 'dosdoor.jsn');
        const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));

        // Apply the manifest's configured CPU cycles (defaults to the template's value if unset)
        const cpuCycles = manifest.config && manifest.config.cpu_cycles;
        if (cpuCycles) {
            config = config.replace(/cycles=\d+/, `cycles=${cpuCycles}`);
        }

        // Build door launch command
        const doorDir = manifest.door.directory.replace('dosbox-bridge/dos', '').replace(/\//g, '\\');

        // Use custom dropfile_path if specified, otherwise use node-based default
        const dropDir = manifest.door.dropfile_path
            ? manifest.door.dropfile_path
            : `\\DROPS\\NODE${node_number}`;

        let launchCmd = manifest.door.launch_command || `call ${manifest.door.executable}`;
        launchCmd = launchCmd.replace('{node}', node_number);
        launchCmd = launchCmd.replace('{dropfile}', 'DOOR.SYS');
        launchCmd = launchCmd.replace('{user_number}', String(sessionData.user_id || ''));

        // Check if door requires FOSSIL driver (default true for backwards compatibility)
        const fossilRequired = manifest.door.fossil_required !== false;
        const fossilCmd = fossilRequired ? '\\FOSSIL\\BNU.COM\n' : '';

        // Only copy DOOR.SYS if drop directory is different from door directory
        // (when using custom dropfile_path, they may be the same)
        const copyCmd = (dropDir !== doorDir) ? `copy ${dropDir}\\DOOR.SYS ${doorDir}\\DOOR.SYS\n` : '';

        // Build autoexec commands
        const autoexecCommands = `${fossilCmd}${copyCmd}cd ${doorDir}\n${launchCmd}\necho.\necho Door exited\nexit`;

        // Replace autoexec placeholder
        config = config.replace('# Door-specific commands will be appended here', autoexecCommands);

        // Write session-specific config
        const configPath = path.join(sessionPath, 'dosbox.conf');
        fs.writeFileSync(configPath, config);

        // Log the generated [autoexec] section for diagnostics
        const slog = this.slog || console;
        const autoexecIndex = config.indexOf('[autoexec]');
        const autoexecSection = autoexecIndex !== -1 ? config.slice(autoexecIndex) : config;
        slog.log(`[${this.getName()}] ${autoexecSection}`);

        return configPath;
    }

    findExecutable() {
        const slog = this.slog || console;
        const envPath = process.env.DOSBOX_EXECUTABLE;
        if (envPath && fs.existsSync(envPath)) {
            slog.log(`[${this.getName()}] Using configured executable: ${envPath}`);
            return envPath;
        }

        const candidates = process.platform === 'win32'
            ? ['dosbox.exe', 'dosbox-x.exe', 'c:\\dosbox\\dosbox.exe', 'c:\\dosbox-x\\dosbox-x.exe']
            : ['dosbox', 'dosbox-x'];

        for (const candidate of candidates) {
            try {
                const result = require('child_process').spawnSync(
                    process.platform === 'win32' ? 'where' : 'which',
                    [candidate],
                    { encoding: 'utf8' }
                );
                if (result.status === 0 && result.stdout.trim()) {
                    const exePath = result.stdout.trim().split('\n')[0];
                    slog.log(`[${this.getName()}] Found executable: ${exePath}`);
                    return candidate;
                }
            } catch (e) {
                // Command not found, try next
            }
        }

        slog.log(`[${this.getName()}] No executable found in PATH, trying "dosbox"`);
        return 'dosbox';
    }
}

/**
 * DOSEMU Adapter
 * Uses PTY (pseudo-terminal) connection
 */
class DOSEMUAdapter extends EmulatorAdapter {
    constructor(basePath) {
        super(basePath);
        this.pty = null;
        this.process = null;
    }

    getName() {
        return 'DOSEMU';
    }

    async launch(session, sessionData) {
        const { session_id, node_number, door_id, session_path } = sessionData;
        const slog = this.slog || console;
        const tcpPort = session.tcpPort; // Port allocated by bridge

        slog.log(`[${this.getName()}] Launching for session ${session_id} on port ${tcpPort}`);

        // Generate DOSEMU config with TCP port
        const configPath = this.generateConfigWithPort(sessionData, session_path, tcpPort);
        slog.log(`[${this.getName()}] Generated config: ${configPath}`);

        // Find DOSEMU executable
        const dosemuExe = this.findExecutable();
        if (!dosemuExe) {
            throw new Error('DOSEMU executable not found');
        }

        // Build DOSEMU command
        const doorScript = this.generateDoorScript(session_id, door_id, node_number, session_path, sessionData);

        const args = [
            '-f', configPath,  // Use our config file
            '-E', doorScript  // Execute script
        ];

        slog.log(`[${this.getName()}] Spawning: ${dosemuExe} ${args.join(' ')}`);

        // Spawn DOSEMU with normal spawn (not PTY)
        const dosemuCwd = path.join(this.basePath, 'dosbox-bridge', 'dos');

        this.process = spawn(dosemuExe, args, {
            cwd: dosemuCwd,
            env: process.env,
            stdio: 'ignore'
        });

        this.process.on('exit', (code, signal) => {
            slog.log(`[${this.getName()}] Process exited: code=${code}, signal=${signal}`);
            if (this.exitCallback) {
                this.exitCallback(code, signal);
            }
        });

        return {
            process: this.process,
            pid: this.process.pid,
            tcpPort: tcpPort
        };
    }

    /**
     * Create TCP listener (same as DOSBox)
     */
    async createTCPListener(tcpPort, onConnection) {
        const slog = this.slog || console;
        return new Promise((resolve, reject) => {
            this.tcpServer = net.createServer((socket) => {
                slog.log(`[${this.getName()}] Connection received on port ${tcpPort}`);
                this.socket = socket;
                socket.setNoDelay(true);
                onConnection(socket);
            });

            this.tcpServer.listen(tcpPort, '127.0.0.1', () => {
                slog.log(`[${this.getName()}] TCP listener ready on port ${tcpPort}`);
                resolve();
            });

            this.tcpServer.on('error', (err) => {
                slog.error(`[${this.getName()}] TCP server error:`, err.message);
                reject(err);
            });
        });
    }

    onData(callback) {
        if (this.socket) {
            this.socket.on('data', callback);
        }
    }

    write(data) {
        if (this.socket && !this.socket.destroyed) {
            this.socket.write(data);
        }
    }

    close() {
        const slog = this.slog || console;
        // Close TCP socket first to simulate carrier loss (lost carrier event)
        // This allows the door game to detect the disconnection and exit gracefully
        if (this.socket) {
            slog.log(`[${this.getName()}] Closing TCP socket (simulating carrier loss)`);
            this.socket.destroy();
            this.socket = null;
        }
        if (this.tcpServer) {
            this.tcpServer.close();
            this.tcpServer = null;
        }

        // Don't kill process immediately - let it detect carrier loss
        // The process exit handler or removeSession will handle killing if needed
        // Process will be killed by timeout if it doesn't exit on carrier loss
    }

    generateConfigWithPort(sessionData, sessionPath, tcpPort) {
        const configPath = path.join(sessionPath, 'dosemu.conf');
        const dosDir = path.join(this.basePath, 'dosbox-bridge', 'dos');

        // DOSEMU config with TCP serial port
        const config = `
# DOSEMU Configuration for Door Session
$_cpu = "80486"
$_hogthreshold = (10)
$_external_charset = "utf8"
$_internal_charset = "cp437"

# Allow lredir to access our directory
$_lredir_paths = [ "${dosDir}" ]

# Serial port configuration - TCP connection (like DOSBox)
$_com1 = "tcp:127.0.0.1:${tcpPort}"

# Video settings
$_console = "0"
$_graphics = "0"
$_X = "0"

# Disable sound
$_sound = "0"
`;

        fs.writeFileSync(configPath, config);
        return configPath;
    }

    generateConfig(sessionData, sessionPath) {
        const configPath = path.join(sessionPath, 'dosemu.conf');

        // Path to our door files
        const dosDir = path.join(this.basePath, 'dosbox-bridge', 'dos');

        // DOSEMU config - allow lredir to our directory
        const config = `
# DOSEMU Configuration for Door Session
$_cpu = "80486"
$_hogthreshold = (10)
$_external_charset = "utf8"
$_internal_charset = "cp437"

# Allow lredir to access our directory
$_lredir_paths = [ "${dosDir}" ]

# Serial port configuration - use TCP for COM1 (same as DOSBox)
# This will be replaced with actual port number
$_com1 = "tcp:127.0.0.1:__PORT__"

# Video settings
$_console = "0"
$_graphics = "0"
$_X = "0"

# Disable sound
$_sound = "0"
`;

        fs.writeFileSync(configPath, config);
        return configPath;
    }

    generateDoorScript(sessionId, doorId, nodeNumber, sessionPath, sessionData) {
        const door_id = doorId;
        const node_number = nodeNumber;

        // Get door manifest
        // DOORS directory is uppercase - Linux filesystem is case-sensitive
        const manifestPath = path.join(this.basePath, 'dosbox-bridge', 'dos', 'DOORS', door_id.toUpperCase(), 'dosdoor.jsn');
        const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));

        // Build door launch command
        const doorDir = manifest.door.directory.replace('dosbox-bridge/dos/', '');
        const dropDir = `DROPS/NODE${node_number}`;

        let launchCmd = manifest.door.launch_command || manifest.door.executable;
        launchCmd = launchCmd.replace('{node}', node_number);
        launchCmd = launchCmd.replace('{dropfile}', 'DOOR.SYS');
        launchCmd = launchCmd.replace('{user_number}', String(sessionData.user_id || ''));

        // Create DOS batch file to launch door
        // First use lredir to map our Linux directory, then run door commands
        const dosDir = path.join(this.basePath, 'dosbox-bridge', 'dos');
        const scriptContent = `@echo off
lredir c: linux\\fs${dosDir}
c:
copy ${dropDir}\\DOOR.SYS ${doorDir}\\DOOR.SYS
cd ${doorDir}
${launchCmd}
`;

        // Put script in dosbox-bridge/dos so DOSEMU finds it as C:\launch.bat
        const scriptPath = path.join(this.basePath, 'dosbox-bridge', 'dos', `launch-${sessionId}.bat`);
        fs.writeFileSync(scriptPath, scriptContent);

        // Log the generated launch script for diagnostics
        const slog = this.slog || console;
        slog.log(`[${this.getName()}] Generated launch script:\n${scriptContent}`);

        // Return DOS path for DOSEMU
        return `C:\\launch-${sessionId}.bat`;
    }

    findExecutable() {
        const slog = this.slog || console;
        const envPath = process.env.DOSEMU_EXECUTABLE;
        if (envPath && fs.existsSync(envPath)) {
            slog.log(`[${this.getName()}] Using configured executable: ${envPath}`);
            return envPath;
        }

        const candidates = ['/usr/bin/dosemu', '/usr/local/bin/dosemu', 'dosemu'];

        for (const candidate of candidates) {
            if (fs.existsSync(candidate)) {
                slog.log(`[${this.getName()}] Found executable: ${candidate}`);
                return candidate;
            }

            try {
                const result = require('child_process').spawnSync('which', [candidate], { encoding: 'utf8' });
                if (result.status === 0 && result.stdout.trim()) {
                    const exePath = result.stdout.trim();
                    slog.log(`[${this.getName()}] Found executable: ${exePath}`);
                    return exePath;
                }
            } catch (e) {
                // Command not found, try next
            }
        }

        return null;
    }
}

/**
 * Native Linux Door Adapter
 * Runs native Linux binaries directly via PTY (pseudo-terminal)
 * User data is passed via DOOR.SYS drop file and environment variables
 */
class NativeAdapter extends EmulatorAdapter {
    constructor(basePath) {
        super(basePath);
        this.ptyProcess = null;
        this.outputEncoding = 'utf8';
        this.proxyForwarder = null;
    }

    getName() {
        return 'Native';
    }

    /**
     * For the pubterm door only: create a single-use loopback TCP forwarder that
     * writes a PROXY protocol v1 header (naming the real browser-side client) to
     * the terminal server, then splices the two sockets. Returns an env override
     * ({ PUBTERM_HOST, PUBTERM_PORT }) pointing the door at the forwarder, or an
     * empty object when no forwarding is needed/possible.
     */
    async _setupPubtermProxyForwarder(doorId, clientIp, slog) {
        // The Windows launch path (plink) resolves ${PUBTERM_*} from process.env,
        // not the door env, so the override would not take effect there.
        if (doorId !== 'pubterm' || process.platform === 'win32'
            || !clientIp || net.isIP(clientIp) === 0) {
            return {};
        }

        // Dial whichever address the terminal daemon listens on. It shares this
        // .env, so TELNET_BIND_HOST tells us: a wildcard bind means loopback is
        // reachable; a specific external IP means we must use that (the daemon
        // now implicitly trusts its own bind address as a proxy source, so no
        // TELNET_TRUSTED_PROXIES entry is needed for the common single-host
        // setup). The daemon's --host arg defaults to TELNET_BIND_HOST, so set
        // that in .env if the daemon binds a specific address. PUBTERM_HOST is
        // only for the non-forwarded fallback / Windows path.
        let targetHost = process.env.TELNET_BIND_HOST
            || '127.0.0.1';
        if (targetHost === '0.0.0.0' || targetHost === '::' || targetHost === '') {
            targetHost = '127.0.0.1';
        }
        const targetPort = parseInt(process.env.PUBTERM_PORT || process.env.TELNET_PORT, 10) || 2323;
        const isV6 = net.isIP(clientIp) === 6;
        const proxyLine = `PROXY ${isV6 ? 'TCP6' : 'TCP4'} ${clientIp} ${isV6 ? '::1' : '127.0.0.1'} 0 ${targetPort}\r\n`;

        const server = net.createServer((client) => {
            // Only the first connection is expected — stop listening immediately.
            try { server.close(); } catch (_) {}

            const upstream = net.connect(targetPort, targetHost, () => {
                upstream.write(proxyLine);
                client.pipe(upstream);
                upstream.pipe(client);
            });
            const bail = () => { try { client.destroy(); } catch (_) {} try { upstream.destroy(); } catch (_) {} };
            client.on('error', bail);
            upstream.on('error', bail);
            client.on('close', () => { try { upstream.end(); } catch (_) {} });
            upstream.on('close', () => { try { client.end(); } catch (_) {} });
        });

        try {
            await new Promise((resolve, reject) => {
                server.once('error', reject);
                server.listen(0, '127.0.0.1', resolve);
            });
        } catch (e) {
            slog.warn(`[${this.getName()}] PubTerm PROXY forwarder failed to start: ${e.message}`);
            return {};
        }

        this.proxyForwarder = server;
        const port = server.address().port;
        slog.log(`[${this.getName()}] PubTerm PROXY forwarder 127.0.0.1:${port} -> ${targetHost}:${targetPort} (client ${clientIp})`);
        return { PUBTERM_HOST: '127.0.0.1', PUBTERM_PORT: String(port) };
    }

    async launch(session, sessionData) {
        const { session_id, node_number, door_id } = sessionData;
        const slog = this.slog || console;

        slog.log(`[${this.getName()}] Launching door '${door_id}' for session ${session_id} on node ${node_number}`);

        // Load nativedoor.json manifest
        const manifestPath = path.join(this.basePath, 'native-doors', 'doors', door_id, 'nativedoor.json');
        if (!fs.existsSync(manifestPath)) {
            throw new Error(`Native door manifest not found: ${manifestPath}`);
        }
        const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));

        // Build drop file path
        const dropPath = path.join(this.basePath, 'native-doors', 'drops', `NODE${node_number}`);

        // Determine drop file filename from manifest format
        const dropfileFormat = (manifest.door && manifest.door.dropfile_format) || 'DOOR.SYS';
        const dropfileName = dropfileFormat === 'DOOR32.SYS' ? 'DOOR32.SYS' : 'DOOR.SYS';
        const dropfileFull = path.join(dropPath, dropfileName);

        // Per-user, per-door private directory for doors that keep their own state
        // (save games, per-user config overlays, etc.), e.g. SyncDOOM's "-home".
        // Created on demand — a door should never have to check for its own existence.
        const homeDir = path.join(this.basePath, 'data', 'users', String(sessionData.user_id || 'guest'), door_id);
        fs.mkdirSync(homeDir, { recursive: true });

        // Determine output encoding (cp437 for legacy DOS-style doors, utf8 for modern)
        this.outputEncoding = (manifest.door && manifest.door.output_encoding) || 'utf8';
        const ptyEncoding = this.outputEncoding === 'cp437' ? 'binary' : 'utf8';

        // Parse user data for environment variables and launch placeholders.
        const userData = typeof sessionData.user_data === 'string'
            ? JSON.parse(sessionData.user_data)
            : (sessionData.user_data || {});

        // Build launch command - replace generic native-door placeholders.
        // On Windows, prefer launch_command_windows (e.g. WSL via wsl.exe) over the Linux bash command
        const isWindows = process.platform === 'win32';
        let launchCmd = (isWindows && manifest.door.launch_command_windows)
            ? manifest.door.launch_command_windows
            : (manifest.door.launch_command || manifest.door.executable);

        const userName = userData.handle || userData.username || userData.real_name || 'Guest';
        const userRealName = userData.real_name || 'Guest';

        launchCmd = launchCmd.replace(/\{node\}/g, String(node_number));
        launchCmd = launchCmd.replace(/\{dropfile\}/g, dropfileFull);
        launchCmd = launchCmd.replace(/\{user_number\}/g, String(sessionData.user_id || ''));
        launchCmd = launchCmd.replace(/\{user_name\}/g, userName);
        launchCmd = launchCmd.replace(/\{user_real_name\}/g, userRealName);
        launchCmd = launchCmd.replace(/\{homedir\}/g, homeDir);

        // Resolve ${VAR:-default} environment variable references in the launch command.
        // This lets manifest commands reference .env variables without shell interpretation.
        launchCmd = launchCmd.replace(/\$\{([A-Z_][A-Z0-9_]*):-([^}]*)\}/g, (_, varName, defaultVal) => {
            return process.env[varName] || defaultVal;
        });

        // Shell-style tokenizer: respects single/double-quoted segments so paths
        // with spaces (e.g. basePath or substituted {dropfile}) are kept intact.
        const argv = [];
        let token = '';
        let inSingle = false;
        let inDouble = false;
        for (let i = 0; i < launchCmd.length; i++) {
            const ch = launchCmd[i];
            if (inSingle) {
                if (ch === "'") inSingle = false; else token += ch;
            } else if (inDouble) {
                if (ch === '"') inDouble = false; else token += ch;
            } else if (ch === "'") {
                inSingle = true;
            } else if (ch === '"') {
                inDouble = true;
            } else if (ch === ' ' || ch === '\t') {
                if (token.length > 0) { argv.push(token); token = ''; }
            } else {
                token += ch;
            }
        }
        if (token.length > 0) argv.push(token);
        let cmd = argv.shift();
        const args = argv;
        const doorDir = path.join(this.basePath, 'native-doors', 'doors', door_id);

        // A bare command name (no path separator) is not resolved against `cwd`
        // by node-pty/execvp — only against PATH. Since a door's executable
        // normally lives alongside its manifest, check the door's own directory
        // first so a manifest can say "syncdoom" instead of "./syncdoom". Fall
        // back to a PATH search (Windows only, via `where.exe`) if it's not there.
        if (!cmd.includes('/') && !cmd.includes('\\')) {
            const localPath = path.join(doorDir, cmd);
            if (fs.existsSync(localPath)) {
                cmd = localPath;
            } else if (isWindows) {
                try {
                    const whereResult = require('child_process').spawnSync('where', [cmd], { encoding: 'utf8' });
                    if (whereResult.status === 0) {
                        cmd = whereResult.stdout.split(/\r?\n/)[0].trim();
                    }
                } catch (e) {
                    slog.warn(`[${this.getName()}] Could not resolve path for '${cmd}':`, e.message);
                }
            }
        }


        // Real originating (browser-side) client IP, resolved from X-Forwarded-For
        // by the multiplexing server.
        const clientIp = (session && session.clientIp) ? String(session.clientIp) : '';

        // PubTerm relays into the local terminal server, which would otherwise see
        // 127.0.0.1 for every browser visitor. Stand up a per-session loopback
        // forwarder that prepends a HAProxy PROXY v1 header naming the real client,
        // and point the door at it. The door keeps using the ordinary telnet
        // client, so TELNET option negotiation is unaffected.
        const proxyEnvOverride = await this._setupPubtermProxyForwarder(door_id, clientIp, slog);

        const env = {
            ...process.env,
            DOOR_USER_NAME: userData.handle || userData.username || userData.real_name || 'Guest',
            DOOR_USER_REAL_NAME: userData.real_name || 'Guest',
            DOOR_USER_NUMBER: String(sessionData.user_id || ''),
            DOOR_NODE: String(node_number),
            DOOR_BBS_NAME: userData.bbs_name || 'BinktermPHP BBS',
            DOOR_DROPFILE: dropfileFull,
            DOOR_HOME: homeDir,
            DOOR_ANSI: '1',
            DOOR_CLIENT_IP: clientIp,
            TERM: 'xterm-256color',
            ...proxyEnvOverride
        };

        slog.log(`[${this.getName()}] Spawning: ${cmd} ${args.join(' ')} in ${doorDir} (output_encoding=${this.outputEncoding})`);

        // Read terminal size from admin config (config/nativedoors.json)
        let initCols = 80, initRows = 25;
        const doorConfigPath = path.join(this.basePath, 'config', 'nativedoors.json');
        try {
            if (fs.existsSync(doorConfigPath)) {
                const doorConfigs = JSON.parse(fs.readFileSync(doorConfigPath, 'utf8'));
                const doorCfg = doorConfigs[door_id] || {};
                const sizeStr = doorCfg.terminal_size || '80x25';
                if (sizeStr !== 'autofit') {
                    const sizeParts = sizeStr.split('x').map(Number);
                    if (sizeParts[0] > 0) initCols = Math.max(20, Math.min(500, sizeParts[0]));
                    if (sizeParts[1] > 0) initRows = Math.max(5,  Math.min(200, sizeParts[1]));
                }
            }
        } catch (_) {}
        slog.log(`[${this.getName()}] Spawning pty at ${initCols}x${initRows}`);

        this.ptyProcess = pty.spawn(cmd, args, {
            name: 'xterm-256color',
            cols: initCols,
            rows: initRows,
            cwd: doorDir,
            // node-pty on Windows (conpty) does not support encoding — omit it
            ...(isWindows ? {} : { encoding: ptyEncoding }),
            env
        });

        this.ptyProcess.onExit(({ exitCode, signal }) => {
            slog.log(`[${this.getName()}] Process exited: code=${exitCode}, signal=${signal}`);
            if (this.exitCallback) {
                this.exitCallback(exitCode, signal);
            }
        });

        return { process: this.ptyProcess, pid: this.ptyProcess.pid };
    }

    onData(callback) {
        if (this.ptyProcess) {
            this.ptyProcess.onData(callback);
        }
    }

    write(data) {
        if (this.ptyProcess) {
            this.ptyProcess.write(data.toString('utf8'));
        }
    }

    resize(cols, rows) {
        const slog = this.slog || console;
        if (this.ptyProcess) {
            slog.log(`[${this.getName()}] Resizing pty to ${cols}x${rows}`);
            this.ptyProcess.resize(cols, rows);
        } else {
            slog.log(`[${this.getName()}] resize() called but ptyProcess is null`);
        }
    }

    close() {
        const slog = this.slog || console;
        if (this.proxyForwarder) {
            try { this.proxyForwarder.close(); } catch (_) {}
            this.proxyForwarder = null;
        }
        if (this.ptyProcess) {
            slog.log(`[${this.getName()}] Killing PTY process`);
            this.ptyProcess.kill();
            this.ptyProcess = null;
        }
    }
}

/**
 * RLogin Adapter
 *
 * Connects out to a remote host over the rlogin protocol (RFC 1282) instead
 * of spawning a local process. There is no PTY and no local PID — the
 * "connection" is a plain outbound TCP socket, so this adapter is structurally
 * closer to DOSBoxAdapter's socket plumbing than to NativeAdapter's PTY, even
 * though it plugs into the bridge the same way NativeAdapter does (immediate
 * connect, no TCP listener/port pool).
 */
class RloginAdapter extends EmulatorAdapter {
    constructor(basePath) {
        super(basePath);
        this.socket = null;
        this.outputEncoding = 'utf8';
        this.handshakeAcked = false;
        this.pendingData = [];
        this.dataCallback = null;
    }

    getName() {
        return 'Rlogin';
    }

    async launch(session, sessionData) {
        const { session_id, door_id } = sessionData;
        const slog = this.slog || console;

        slog.log(`[${this.getName()}] Launching door '${door_id}' for session ${session_id}`);

        // RLogin doors have no filesystem footprint - the door definition
        // lives entirely in Postgres (rlogin_doors table), not a manifest file.
        const dbClient = new Client(buildDbConfig());
        let doorCfg;
        try {
            await dbClient.connect();
            const result = await dbClient.query('SELECT * FROM rlogin_doors WHERE door_id = $1', [door_id]);
            if (result.rows.length === 0) {
                throw new Error(`RLogin door '${door_id}' not found in rlogin_doors table`);
            }
            doorCfg = result.rows[0];
        } finally {
            await dbClient.end();
        }

        const host = doorCfg.host;
        const port = parseInt(doorCfg.port, 10) || 513;
        if (!host) {
            throw new Error(`RLogin door '${door_id}' has no host configured`);
        }

        this.outputEncoding = doorCfg.output_encoding || 'utf8';

        // Parse user data and any pre-login overrides for placeholder substitution
        const userData = typeof sessionData.user_data === 'string'
            ? JSON.parse(sessionData.user_data)
            : (sessionData.user_data || {});

        const placeholders = {
            '{user_name}': userData.rlogin_remote_username || userData.handle || userData.username || userData.real_name || 'guest',
            '{real_name}': userData.real_name || 'Guest',
            '{user_number}': String(sessionData.user_id || ''),
        };

        const substitute = (template) => {
            let result = String(template || '');
            for (const [key, value] of Object.entries(placeholders)) {
                result = result.split(key).join(value);
            }
            return result;
        };

        // Blank is a deliberate, valid choice for these two fields (some
        // rlogin daemons accept an empty username field) -- unlike the other
        // fields, an empty client/server username is not defaulted to
        // {user_name} here.
        const clientUsername = substitute(doorCfg.client_username || '');
        const serverUsername = substitute(doorCfg.server_username || '');
        // doorCfg.terminal_type is null when the sysop left it blank, meaning
        // "use the connecting user's own terminal type" - PHP already resolved
        // that (from their last telnet/SSH TERM, or xterm-256color for web
        // launches with nothing to inherit) into user_data before this session
        // was created.
        const terminalType = doorCfg.terminal_type || userData.rlogin_terminal_type || 'xterm-256color';
        const terminalSpeed = parseInt(doorCfg.terminal_speed, 10) || 38400;
        const termString = `${terminalType}/${terminalSpeed}`;

        slog.log(`[${this.getName()}] Connecting to ${host}:${port} as client='${clientUsername}' server='${serverUsername}' term='${termString}'`);

        return new Promise((resolve, reject) => {
            const socket = net.connect({ host, port }, () => {
                slog.log(`[${this.getName()}] TCP connected, sending rlogin handshake`);
                // RFC 1282 handshake: \0 clientUsername \0 serverUsername \0 term/speed \0
                const handshake = Buffer.concat([
                    Buffer.from([0]),
                    Buffer.from(clientUsername, 'utf8'),
                    Buffer.from([0]),
                    Buffer.from(serverUsername, 'utf8'),
                    Buffer.from([0]),
                    Buffer.from(termString, 'utf8'),
                    Buffer.from([0]),
                ]);
                socket.write(handshake);
            });

            socket.setNoDelay(true);
            this.socket = socket;

            const onFirstData = (chunk) => {
                // The server acks the handshake with a single 0x00 byte before the
                // session stream begins. It may arrive alone or prefixed to the
                // first real data — strip at most one leading null byte, once.
                if (!this.handshakeAcked) {
                    this.handshakeAcked = true;
                    if (chunk.length > 0 && chunk[0] === 0) {
                        chunk = chunk.slice(1);
                    }
                    resolve({ process: this.socket, pid: null });
                }
                if (chunk.length > 0) {
                    if (this.dataCallback) {
                        this.dataCallback(chunk);
                    } else {
                        this.pendingData.push(chunk);
                    }
                }
            };

            socket.on('data', onFirstData);

            socket.on('error', (err) => {
                slog.error(`[${this.getName()}] Socket error: ${err.message}`);
                if (!this.handshakeAcked) {
                    reject(err);
                }
                if (this.exitCallback) {
                    this.exitCallback(1, null);
                }
            });

            socket.on('close', () => {
                slog.log(`[${this.getName()}] Connection closed`);
                if (this.exitCallback) {
                    this.exitCallback(0, null);
                }
            });
        });
    }

    onData(callback) {
        this.dataCallback = callback;
        if (this.pendingData.length > 0) {
            const queued = this.pendingData;
            this.pendingData = [];
            queued.forEach(chunk => callback(chunk));
        }
    }

    write(data) {
        if (this.socket && !this.socket.destroyed) {
            this.socket.write(data);
        }
    }

    resize(cols, rows) {
        // No-op: RFC 1282 has no standard live-resize mechanism. Only the
        // initial terminal_type/terminal_speed sent at handshake time applies.
    }

    close() {
        const slog = this.slog || console;
        if (this.socket) {
            slog.log(`[${this.getName()}] Closing socket`);
            this.socket.destroy();
            this.socket = null;
        }
    }
}

/**
 * Factory function to select appropriate emulator
 *
 * @param {string} basePath - Base path for BinktermPHP
 * @param {string} [doorType='dos'] - Door type: 'dos', 'native', or 'rlogin'
 */
function createEmulatorAdapter(basePath, doorType) {
    // Native Linux doors use PTY directly - no emulator needed
    if (doorType === 'native') {
        console.log('[EMULATOR] Door type: native, using NativeAdapter');
        return new NativeAdapter(basePath);
    }

    // RLogin doors connect out to a remote host - no local process/emulator
    if (doorType === 'rlogin') {
        console.log('[EMULATOR] Door type: rlogin, using RloginAdapter');
        return new RloginAdapter(basePath);
    }

    // DOS doors: check environment variable for emulator preference
    const preferredEmulator = process.env.DOOR_EMULATOR || 'auto';

    // Windows: only DOSBox supported
    if (process.platform === 'win32') {
        console.log('[EMULATOR] Platform: Windows, using DOSBox');
        return new DOSBoxAdapter(basePath);
    }

    // Linux: check preference
    if (preferredEmulator === 'dosemu') {
        // DOSEMU explicitly requested
        const dosemuAdapter = new DOSEMUAdapter(basePath);
        if (dosemuAdapter.findExecutable()) {
            console.log('[EMULATOR] Using DOSEMU (explicitly requested)');
            return dosemuAdapter;
        } else {
            console.log('[EMULATOR] DOSEMU requested but not found, falling back to DOSBox');
        }
    }

    // Default to DOSBox (reliable, proven)
    console.log('[EMULATOR] Using DOSBox (default)');
    return new DOSBoxAdapter(basePath);
}

module.exports = {
    EmulatorAdapter,
    DOSBoxAdapter,
    DOSEMUAdapter,
    NativeAdapter,
    RloginAdapter,
    createEmulatorAdapter
};
