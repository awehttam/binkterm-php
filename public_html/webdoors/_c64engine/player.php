<?php
/**
 * C64 WebDoor Engine - player.php
 *
 * Shared jsc64 emulator template for C64 game WebDoors.
 * Include this file from a game door's index.php after setting $c64Config.
 *
 * Required $c64Config keys:
 *   'door_id'  string  Door identifier — must match the webdoor.json "id" field
 *   'title'    string  Game title shown in the loading spinner
 *
 * One of (required):
 *   'prg'      string  Filename of a .prg/.rom in the door's own directory  ← easiest
 *   'd64'      string  Filename of a .d64 in the door's own directory
 *   'prg_path' string  Absolute path to a .prg/.rom (if file lives elsewhere)
 *   'd64_path' string  Absolute path to a .d64 (if file lives elsewhere)
 *
 * Optional:
 *   'load_address' int    Override load address; file treated as raw binary (no 2-byte header).
 *                         Auto-detected by extension: .rom/.bin → 0x8000, .prg → reads header.
 *   'prg_name'     string PRG name to auto-select from a D64 (default: first entry)
 *
 * Minimal door example (index.php):
 *   <?php
 *   $c64Config = [
 *       'door_id' => 'mygame',
 *       'title'   => 'My C64 Game',
 *       'prg'     => 'mygame.prg',   // file sits next to index.php
 *   ];
 *   require __DIR__ . '/../_c64engine/player.php';
 */

require_once __DIR__ . '/../_doorsdk/php/helpers.php';

// Auth required
$user   = \WebDoorSDK\requireAuth();
$doorId = $c64Config['door_id'] ?? '';
$title  = htmlspecialchars($c64Config['title'] ?? 'C64 Game', ENT_QUOTES, 'UTF-8');

// Door must be enabled in webdoors.json
if ($doorId && !\WebDoorSDK\isDoorEnabled($doorId)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body style="color:#f66;background:#000;font-family:monospace;padding:2rem">This game is not enabled.</body></html>';
    exit;
}

// ---------------------------------------------------------------------------
// Resolve PRG bytes — support bare filename (relative to caller) or full path
// ---------------------------------------------------------------------------
$callerDir = dirname(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0]['file']);

// Resolve bare filenames to full paths; also auto-promote .d64 passed via 'prg' key
$prgFile = $c64Config['prg'] ?? null;
if ($prgFile && strtolower(pathinfo($prgFile, PATHINFO_EXTENSION)) === 'd64') {
    // .d64 passed via the wrong key — treat it as d64
    if (empty($c64Config['d64_path'])) {
        $c64Config['d64_path'] = $callerDir . '/' . $prgFile;
    }
    unset($c64Config['prg']);
} elseif ($prgFile && empty($c64Config['prg_path'])) {
    $c64Config['prg_path'] = $callerDir . '/' . $prgFile;
}

if (!empty($c64Config['d64']) && empty($c64Config['d64_path'])) {
    $c64Config['d64_path'] = $callerDir . '/' . $c64Config['d64'];
}

// Also handle absolute prg_path pointing to a .d64
if (!empty($c64Config['prg_path']) && empty($c64Config['d64_path'])) {
    if (strtolower(pathinfo($c64Config['prg_path'], PATHINFO_EXTENSION)) === 'd64') {
        $c64Config['d64_path'] = $c64Config['prg_path'];
        unset($c64Config['prg_path']);
    }
}

$loadAddress = 0x0801;
$prgData     = '';
$prgName     = '';

if (!empty($c64Config['prg_path'])) {
    $raw = @file_get_contents($c64Config['prg_path']);
    if ($raw === false || strlen($raw) < 1) {
        http_response_code(500);
        echo '<!DOCTYPE html><html><body style="color:#f66;background:#000;font-family:monospace;padding:2rem">PRG file not found.</body></html>';
        exit;
    }
    $ext = strtolower(pathinfo($c64Config['prg_path'], PATHINFO_EXTENSION));
    // Default load addresses by extension; explicit load_address always wins
    $extDefaults = ['rom' => 0x8000, 'bin' => 0x8000];
    if (isset($c64Config['load_address'])) {
        // Caller-supplied address — treat whole file as raw binary
        $loadAddress = (int)$c64Config['load_address'];
        $prgData     = base64_encode($raw);
    } elseif ($ext === 'p00') {
        // P00 container: 26-byte header ("C64File\0" + 16-char name + 2 bytes),
        // followed by a standard PRG (2-byte load address + program data)
        if (strlen($raw) < 28) {
            http_response_code(500);
            echo '<!DOCTYPE html><html><body style="color:#f66;background:#000;font-family:monospace;padding:2rem">P00 file too small.</body></html>';
            exit;
        }
        $prg         = substr($raw, 26);
        $loadAddress = ord($prg[0]) | (ord($prg[1]) << 8);
        $prgData     = base64_encode(substr($prg, 2));
    } elseif (isset($extDefaults[$ext])) {
        // Raw binary at a known address (no header)
        $loadAddress = $extDefaults[$ext];
        $prgData     = base64_encode($raw);
    } else {
        // .prg and anything else: read 2-byte load address header
        $loadAddress = ord($raw[0]) | (ord($raw[1]) << 8);
        $prgData     = base64_encode(substr($raw, 2));
    }
    $prgName = basename($c64Config['prg_path']);

} elseif (!empty($c64Config['d64_path'])) {
    $raw = @file_get_contents($c64Config['d64_path']);
    if ($raw === false) {
        http_response_code(500);
        echo '<!DOCTYPE html><html><body style="color:#f66;background:#000;font-family:monospace;padding:2rem">D64 file not found.</body></html>';
        exit;
    }
    $parser = new \BinktermPHP\D64Parser($raw);
    $prgs   = $parser->extractPrgs();
    if (empty($prgs)) {
        http_response_code(500);
        echo '<!DOCTYPE html><html><body style="color:#f66;background:#000;font-family:monospace;padding:2rem">No PRG files found in D64 image.</body></html>';
        exit;
    }
    $selected = $prgs[0];
    if (!empty($c64Config['prg_name'])) {
        foreach ($prgs as $p) {
            if ($p['name'] === $c64Config['prg_name']) {
                $selected = $p;
                break;
            }
        }
    }
    $loadAddress = $selected['load_address'];
    $prgData     = $selected['data_b64'];
    $prgName     = $selected['name'];

} else {
    http_response_code(500);
    echo '<!DOCTYPE html><html><body style="color:#f66;background:#000;font-family:monospace;padding:2rem">No PRG or D64 configured in $c64Config.</body></html>';
    exit;
}

$loadAddressJs = json_encode($loadAddress);
$prgDataJs     = json_encode($prgData);
$prgNameJs     = json_encode($prgName);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $title ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
html, body {
    background: #000;
    width: 100%; height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    font-family: monospace;
    color: #aaa;
    overflow: hidden;
}
#status {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    text-align: center;
    font-size: 14px;
    color: #6af;
    z-index: 10;
}
#status .spinner {
    display: inline-block;
    width: 20px; height: 20px;
    border: 2px solid #333;
    border-top-color: #6af;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    margin-bottom: 8px;
}
@keyframes spin { to { transform: rotate(360deg); } }
#c64container {
    position: relative;
    width: 403px;
    height: 284px;
    display: none;
    transform-origin: center center;
}
#c64container canvas {
    image-rendering: pixelated;
}
#toolbar {
    position: absolute;
    top: 6px;
    right: 6px;
    display: flex;
    gap: 4px;
    z-index: 20;
}
#toolbar button {
    background: rgba(0,0,0,0.7);
    border: 1px solid #444;
    color: #aaa;
    font-size: 10px;
    padding: 2px 6px;
    cursor: pointer;
    border-radius: 2px;
}
#toolbar button:hover { border-color: #888; color: #fff; }
#error {
    display: none;
    text-align: center;
    color: #f66;
    padding: 20px;
}
</style>
</head>
<body>

<div id="status">
    <div class="spinner"></div><br>
    <span id="status-text">Starting C64&hellip;</span>
</div>

<div id="error"></div>

<div id="c64container" tabindex="0">
    <div id="toolbar">
        <button id="btn-reset" title="Reset C64">RST</button>
        <button id="btn-pause" title="Pause/Resume">&#9646;&#9646;</button>
    </div>
</div>

<script>
var JSC64_BASEPATH = '/vendor/jsc64/js/';
</script>
<script src="/vendor/jquery-3.7.1/jquery-3.7.1.min.js"></script>
<script src="/vendor/jsc64/js/jquery.jsc64classes.js"></script>
<script src="/vendor/jsc64/js/jquery.jsc64.js"></script>

<script>
(function () {
    var PRG_DATA        = <?= $prgDataJs ?>;
    var PRG_LOAD_ADDR   = <?= $loadAddressJs ?>;
    var PRG_NAME        = <?= $prgNameJs ?>;
    var paused   = false;
    var instance = null;

    function scaleToFit() {
        var c = document.getElementById('c64container');
        if (c.style.display === 'none') return;
        var scale = Math.min(window.innerWidth / 403, window.innerHeight / 284) * 0.99;
        c.style.transform = 'scale(' + scale + ')';
    }

    function setStatus(msg) {
        document.getElementById('status-text').textContent = msg;
    }

    function showError(msg) {
        document.getElementById('status').style.display = 'none';
        var err = document.getElementById('error');
        err.textContent = msg;
        err.style.display = 'block';
    }

    function loadPrg() {
        try {
            var binary = atob(PRG_DATA);
            var bytes  = new Uint8Array(binary.length);
            for (var i = 0; i < binary.length; i++) {
                bytes[i] = binary.charCodeAt(i);
            }

            var mem = instance._mem;
            var cpu = instance._cpu;
            var addr = PRG_LOAD_ADDR;
            for (var j = 0; j < bytes.length; j++) {
                mem.write(addr++, bytes[j]);
            }

            if (PRG_LOAD_ADDR === 0x0801) {
                // BASIC program: update end-of-BASIC pointers and inject RUN
                var endAddr = PRG_LOAD_ADDR + bytes.length;
                mem.write(0x002d, endAddr & 0xff);
                mem.write(0x002e, (endAddr >> 8) & 0xff);
                mem.write(0x002f, endAddr & 0xff);
                mem.write(0x0030, (endAddr >> 8) & 0xff);
                mem.write(0x0031, endAddr & 0xff);
                mem.write(0x0032, (endAddr >> 8) & 0xff);

                var charsInBuffer = mem.read(0xc6);
                if (charsInBuffer < mem.read(0x0289) - 4) {
                    var kbBuf = 0x0277 + charsInBuffer + 1;
                    mem.write(kbBuf++, 82);  // R
                    mem.write(kbBuf++, 85);  // U
                    mem.write(kbBuf++, 78);  // N
                    mem.write(kbBuf++, 13);  // Return
                    mem.write(0xc6, charsInBuffer + 5);
                }
            } else {
                // Machine code: jump to load address
                cpu.pc = PRG_LOAD_ADDR;
            }

            document.getElementById('status').style.display = 'none';
            document.getElementById('c64container').style.display = 'block';
            scaleToFit();
            document.getElementById('c64container').focus();
            document.title = PRG_NAME || <?= json_encode($c64Config['title'] ?? 'C64 Game') ?>;
        } catch (e) {
            showError('Failed to load PRG: ' + e.message);
        }
    }

    $(document).ready(function () {
        $('#c64container').jsc64($('#c64container'));
        instance = $('#c64container').jsc64GetInstance();

        setStatus('Booting C64\u2026');
        setTimeout(function () {
            setStatus('Loading <?= addslashes($title) ?>\u2026');
            loadPrg();
        }, 2000);

        window.addEventListener('resize', scaleToFit);

        // Intercept keyboard events at window level and feed them directly into
        // jsc64's keyboard handler. This bypasses the need for #c64container to
        // have DOM focus (which is unreliable in iframes) and avoids the broken
        // keyCode property on synthetic KeyboardEvent objects.
        var navKeys = [32, 37, 38, 39, 40]; // Space, Left, Up, Right, Down
        window.addEventListener('keydown', function (e) {
            if (navKeys.indexOf(e.keyCode) !== -1) {
                e.preventDefault();
            }
            var c = document.getElementById('c64container');
            if (c.style.display === 'none' || !instance) return;
            instance._mem.cia1.keyboard.keyDown(e);
        }, { passive: false });

        window.addEventListener('keyup', function (e) {
            var c = document.getElementById('c64container');
            if (c.style.display === 'none' || !instance) return;
            instance._mem.cia1.keyboard.keyUp(e);
        });

        // Re-focus the container on click so keyboard input is never lost
        document.getElementById('c64container').addEventListener('click', function () {
            this.focus();
        });

        document.getElementById('btn-reset').addEventListener('click', function () {
            instance._cpu.reset();
        });
        document.getElementById('btn-pause').addEventListener('click', function () {
            $('#c64container').jsc64Pause();
            paused = !paused;
            this.textContent = paused ? '\u25BA' : '\u2016';
        });
    });
})();
</script>
</body>
</html>
