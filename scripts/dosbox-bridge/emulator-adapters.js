/**
 * Emulator Adapter Pattern
 * Supports multiple DOS emulators (DOSBox, DOSEMU) with a common interface
 */

const net = require('net');
const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');
const pty = require('node-pty');

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
        const { sessionId, node_number, door_id, session_path } = sessionData;

        // Allocate TCP port (handled by caller, passed in session.tcpPort)
        const tcpPort = session.tcpPort;

        console.log(`[${this.getName()}] Launching for session ${sessionId} on port ${tcpPort}`);

        // Generate DOSBox config
        const configPath = this.generateConfig(sessionData, session_path, tcpPort);
        console.log(`[${this.getName()}] Generated config: ${configPath}`);

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

        console.log(`[${this.getName()}] Spawning: ${dosboxExe} ${args.join(' ')}`);

        // Launch DOSBox
        this.process = spawn(dosboxExe, args, spawnOptions);

        this.process.on('error', (err) => {
            console.error(`[${this.getName()}] Process error:`, err.message);
        });

        this.process.on('exit', (code, signal) => {
            console.log(`[${this.getName()}] Process exited: code=${code}, signal=${signal}`);
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
        return new Promise((resolve, reject) => {
            this.tcpServer = net.createServer((socket) => {
                console.log(`[${this.getName()}] Connection received on port ${tcpPort}`);
                this.socket = socket;
                socket.setNoDelay(true);
                onConnection(socket);
            });

            this.tcpServer.listen(tcpPort, '127.0.0.1', () => {
                console.log(`[${this.getName()}] TCP listener ready on port ${tcpPort}`);
                resolve();
            });

            this.tcpServer.on('error', (err) => {
                console.error(`[${this.getName()}] TCP server error:`, err.message);
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
        if (this.socket) {
            this.socket.destroy();
        }
        if (this.tcpServer) {
            this.tcpServer.close();
        }
        if (this.process && !this.process.killed) {
            this.process.kill('SIGTERM');
        }
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
        const manifestPath = path.join(this.basePath, 'dosbox-bridge', 'dos', 'doors', door_id, 'dosdoor.json');
        const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));

        // Build door launch command
        const doorDir = manifest.door.directory.replace('dosbox-bridge/dos', '').replace(/\//g, '\\');
        const dropDir = `\\drops\\node${node_number}`;

        let launchCmd = manifest.door.launch_command || `call ${manifest.door.executable}`;
        launchCmd = launchCmd.replace('{node}', node_number);
        launchCmd = launchCmd.replace('{dropfile}', 'DOOR.SYS');

        // Build autoexec commands
        const autoexecCommands = `copy ${dropDir}\\DOOR.SYS ${doorDir}\\DOOR.SYS\ncd ${doorDir}\n${launchCmd}`;

        // Replace autoexec placeholder
        config = config.replace('# Door-specific commands will be appended here', autoexecCommands);

        // Write session-specific config
        const configPath = path.join(sessionPath, 'dosbox.conf');
        fs.writeFileSync(configPath, config);

        return configPath;
    }

    findExecutable() {
        const envPath = process.env.DOSBOX_EXECUTABLE;
        if (envPath && fs.existsSync(envPath)) {
            console.log(`[${this.getName()}] Using configured executable: ${envPath}`);
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
                    console.log(`[${this.getName()}] Found executable: ${exePath}`);
                    return candidate;
                }
            } catch (e) {
                // Command not found, try next
            }
        }

        console.log(`[${this.getName()}] No executable found in PATH, trying "dosbox"`);
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
        const { sessionId, node_number, door_id, session_path } = sessionData;

        console.log(`[${this.getName()}] Launching for session ${sessionId}`);

        // Generate DOSEMU config
        const configPath = this.generateConfig(sessionData, session_path);
        console.log(`[${this.getName()}] Generated config: ${configPath}`);

        // Find DOSEMU executable
        const dosemuExe = this.findExecutable();
        if (!dosemuExe) {
            throw new Error('DOSEMU executable not found');
        }

        // Build DOSEMU command
        // DOSEMU will execute the door launch script
        const doorScript = this.generateDoorScript(sessionData, session_path);

        const args = [
            '-n',  // No banner
            '-I', `dosdebug { -D+M }`,  // Minimal debug
            '-E', doorScript  // Execute script
        ];

        console.log(`[${this.getName()}] Spawning: ${dosemuExe} ${args.join(' ')}`);

        // Spawn DOSEMU with PTY
        this.pty = pty.spawn(dosemuExe, args, {
            name: 'xterm-color',
            cols: 80,
            rows: 25,
            cwd: this.basePath,
            env: process.env
        });

        this.process = this.pty;

        this.pty.onExit(({ code, signal }) => {
            console.log(`[${this.getName()}] Process exited: code=${code}, signal=${signal}`);
        });

        return {
            process: this.pty,
            pid: this.pty.pid,
            connection: this.pty
        };
    }

    onData(callback) {
        if (this.pty) {
            this.pty.onData(callback);
        }
    }

    write(data) {
        if (this.pty) {
            this.pty.write(data);
        }
    }

    close() {
        if (this.pty) {
            this.pty.kill();
        }
    }

    generateConfig(sessionData, sessionPath) {
        // DOSEMU uses ~/.dosemu or specified config directory
        // For now, we'll use default DOSEMU config and rely on command-line args

        const configPath = path.join(sessionPath, 'dosemu.conf');

        // Minimal DOSEMU config
        const config = `
# DOSEMU Configuration for Door Session
$_cpu = "80486"
$_hogthreshold = (10)
$_external_charset = "utf8"
$_internal_charset = "cp437"

# Serial port configuration
$_com1 = "virtual"
$_com1_virtual = "pty"

# Video settings
$_console = "0"
$_graphics = "0"
$_X = "0"
`;

        fs.writeFileSync(configPath, config);
        return configPath;
    }

    generateDoorScript(sessionData, sessionPath) {
        const { door_id, node_number } = sessionData;

        // Get door manifest
        const manifestPath = path.join(this.basePath, 'dosbox-bridge', 'dos', 'doors', door_id, 'dosdoor.json');
        const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));

        // Build door launch command
        const doorDir = manifest.door.directory.replace('dosbox-bridge/dos/', '');
        const dropDir = `drops/node${node_number}`;

        let launchCmd = manifest.door.launch_command || manifest.door.executable;
        launchCmd = launchCmd.replace('{node}', node_number);
        launchCmd = launchCmd.replace('{dropfile}', 'DOOR.SYS');

        // Create DOS batch file to launch door
        const scriptContent = `@echo off
copy ${dropDir}\\DOOR.SYS ${doorDir}\\DOOR.SYS
cd ${doorDir}
${launchCmd}
`;

        const scriptPath = path.join(sessionPath, 'launch.bat');
        fs.writeFileSync(scriptPath, scriptContent);

        // Return DOS path for DOSEMU
        return 'C:\\launch.bat';
    }

    findExecutable() {
        const envPath = process.env.DOSEMU_EXECUTABLE;
        if (envPath && fs.existsSync(envPath)) {
            console.log(`[${this.getName()}] Using configured executable: ${envPath}`);
            return envPath;
        }

        const candidates = ['/usr/bin/dosemu', '/usr/local/bin/dosemu', 'dosemu'];

        for (const candidate of candidates) {
            if (fs.existsSync(candidate)) {
                console.log(`[${this.getName()}] Found executable: ${candidate}`);
                return candidate;
            }

            try {
                const result = require('child_process').spawnSync('which', [candidate], { encoding: 'utf8' });
                if (result.status === 0 && result.stdout.trim()) {
                    const exePath = result.stdout.trim();
                    console.log(`[${this.getName()}] Found executable: ${exePath}`);
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
 * Factory function to select appropriate emulator
 */
function createEmulatorAdapter(basePath) {
    // Check environment variable
    const preferredEmulator = process.env.DOOR_EMULATOR || 'auto';

    // Windows: only DOSBox supported
    if (process.platform === 'win32') {
        console.log('[EMULATOR] Platform: Windows, using DOSBox');
        return new DOSBoxAdapter(basePath);
    }

    // Linux: check preference
    if (preferredEmulator === 'dosemu' || preferredEmulator === 'auto') {
        // Check if DOSEMU exists
        const dosemuAdapter = new DOSEMUAdapter(basePath);
        if (dosemuAdapter.findExecutable()) {
            console.log('[EMULATOR] Using DOSEMU (preferred)');
            return dosemuAdapter;
        }
    }

    // Fallback to DOSBox
    console.log('[EMULATOR] Using DOSBox (fallback)');
    return new DOSBoxAdapter(basePath);
}

module.exports = {
    EmulatorAdapter,
    DOSBoxAdapter,
    DOSEMUAdapter,
    createEmulatorAdapter
};
