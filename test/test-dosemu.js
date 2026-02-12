#!/usr/bin/env node
/**
 * DOSEMU Test Script
 *
 * Standalone test to verify DOSEMU works with door games before full integration.
 * This script launches LORD via DOSEMU with PTY and allows manual testing.
 *
 * Usage:
 *   node test-dosemu.js
 *
 * Requirements:
 *   - dosemu2 installed (apt-get install dosemu2)
 *   - node-pty installed (npm install in scripts/dosbox-bridge)
 */

const pty = require('node-pty');
const fs = require('fs');
const path = require('path');

const BASE_PATH = path.resolve(__dirname, '..');

console.log('=== DOSEMU Door Game Test ===');
console.log('');

// Check if DOSEMU is installed
function findDOSEMU() {
    const candidates = ['/usr/bin/dosemu', '/usr/local/bin/dosemu'];

    for (const candidate of candidates) {
        if (fs.existsSync(candidate)) {
            console.log(`✓ Found DOSEMU: ${candidate}`);
            return candidate;
        }
    }

    console.error('✗ DOSEMU not found. Install with: sudo apt-get install dosemu2');
    process.exit(1);
}

// Generate DOOR.SYS for testing
function generateTestDoorSys() {
    const dropDir = path.join(BASE_PATH, 'dosbox-bridge', 'dos', 'drops', 'node1');

    if (!fs.existsSync(dropDir)) {
        fs.mkdirSync(dropDir, { recursive: true });
    }

    const doorSysPath = path.join(dropDir, 'DOOR.SYS');

    const lines = [
        'COM1:',           // 1: Comm port
        '115200',          // 2: Baud rate
        '8',               // 3: Parity
        '1',               // 4: Node number
        '115200',          // 5: DTE rate
        'Y',               // 6: Screen display
        'Y',               // 7: Printer toggle
        'Y',               // 8: Page bell
        'Y',               // 9: Caller alarm
        'Test User',       // 10: User's name
        'Test Location',   // 11: Location
        '555-1234',        // 12: Phone
        '555-1234',        // 13: Phone (again)
        'PASSWORD',        // 14: Password
        '255',             // 15: Security level
        '100',             // 16: Times on
        '01/01/2026',      // 17: Last date
        '7200',            // 18: Seconds remaining
        '120',             // 19: Minutes remaining
        'GR',              // 20: Graphics mode
        '23',              // 21: Page length
        'Y',               // 22: User mode (expert)
        '1,2,3,4,5,6,7',   // 23: Conferences
        '1',               // 24: Conference
        '0',               // 25: Upload KB
        '0',               // 26: Download KB
        '0',               // 27: Daily DL limit
        '0',               // 28: Daily DL total
        '01/01/90',        // 29: Birthdate
        '',                // 30: Registration path
        '',                // 31: Door path
        'Sysop',           // 32: Sysop name
        'Test User',       // 33: Alias
        '00:00',           // 34: Event time
        'Y',               // 35: Error-free connection
        'Y',               // 36: ANSI supported
        'Y',               // 37: Record locking
        '7',               // 38: Text color
        '120',             // 39: Time limit
        '7200',            // 40: Time remaining
        '0',               // 41: Fossil port
        '0',               // 42: Fossil IRQ
        '0',               // 43: Fossil base
        'BinktermPHP BBS', // 44: BBS name
        'System',          // 45: Sysop first
        'Operator',        // 46: Sysop last
        '0',               // 47: Fossil port
        '0',               // 48: Fossil IRQ
        '0',               // 49: Fossil base
        'DOOR.SYS',        // 50: Drop file type
        '1',               // 51: Node number
        '115200'           // 52: DTE rate
    ];

    const content = lines.join('\r\n') + '\r\n';
    fs.writeFileSync(doorSysPath, content, 'ascii');

    console.log(`✓ Generated DOOR.SYS: ${doorSysPath}`);
    return doorSysPath;
}

// Generate DOS batch file to launch LORD
function generateLaunchScript() {
    const scriptPath = path.join(BASE_PATH, 'test', 'launch-lord.bat');

    const script = `@echo off
echo.
echo ========================================
echo   DOSEMU LORD Test
echo ========================================
echo.
copy \\drops\\node1\\DOOR.SYS \\doors\\lord\\DOOR.SYS
cd \\doors\\lord
echo Loading FOSSIL driver...
\\fossil\\bnu.com
echo.
echo Launching LORD...
echo.
call start.bat 1
`;

    fs.writeFileSync(scriptPath, script);
    console.log(`✓ Generated launch script: ${scriptPath}`);
    return scriptPath;
}

// Main test function
async function testDOSEMU() {
    const dosemuExe = findDOSEMU();

    console.log('');
    console.log('Setting up test environment...');

    // Generate DOOR.SYS
    generateTestDoorSys();

    // Generate launch script
    const launchScript = generateLaunchScript();

    console.log('');
    console.log('Launching DOSEMU...');
    console.log('Note: You should see LORD load. Type commands to play.');
    console.log('Press Ctrl+C to exit.');
    console.log('');
    console.log('-----------------------------------');

    // Set up DOSEMU environment
    const dosemuDir = path.join(BASE_PATH, 'dosbox-bridge', 'dos');

    // Launch DOSEMU with PTY
    const dosemuProcess = pty.spawn(dosemuExe, [
        '-dumb',  // Dumb terminal mode
        '-E', 'C:\\launch-lord.bat'  // Execute our launch script
    ], {
        name: 'xterm-color',
        cols: 80,
        rows: 25,
        cwd: dosemuDir,
        env: {
            ...process.env,
            HOME: dosemuDir,
            DOSEMU_LIB_DIR: '/usr/share/dosemu',
            DOSEMU_CONF_DIR: dosemuDir
        }
    });

    console.log(`DOSEMU PID: ${dosemuProcess.pid}`);

    // Check memory usage
    setTimeout(() => {
        try {
            const { execSync } = require('child_process');
            const ps = execSync(`ps aux | grep ${dosemuProcess.pid} | grep -v grep`).toString();
            const match = ps.match(/\s+(\d+)\s+(\d+\.\d+)\s+(\d+\.\d+)\s+(\d+)/);
            if (match) {
                const rss = parseInt(match[4]); // RSS in KB
                const rssMB = (rss / 1024).toFixed(1);
                console.log(`\n[MEMORY] DOSEMU RSS: ${rssMB} MB`);
            }
        } catch (e) {
            // Ignore errors
        }
    }, 3000);

    // Forward DOSEMU output to console
    dosemuProcess.onData((data) => {
        process.stdout.write(data);
    });

    // Forward stdin to DOSEMU
    process.stdin.setRawMode(true);
    process.stdin.on('data', (data) => {
        // Handle Ctrl+C
        if (data[0] === 0x03) {
            console.log('\n\nShutting down DOSEMU...');
            dosemuProcess.kill();
            process.exit(0);
        }
        dosemuProcess.write(data);
    });

    // Handle DOSEMU exit
    dosemuProcess.onExit(({ exitCode, signal }) => {
        console.log(`\n\nDOSEMU exited: code=${exitCode}, signal=${signal}`);
        process.exit(exitCode || 0);
    });
}

// Run test
testDOSEMU().catch((err) => {
    console.error('Error:', err.message);
    process.exit(1);
});
