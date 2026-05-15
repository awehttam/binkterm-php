#!/usr/bin/env php
<?php

/**
 * jsdosdoor_createmanifest.php
 *
 * Interactive wizard to generate a jsdosdoor.json manifest for a JS-DOS door.
 * Run this script from within the door directory (the one containing assets/).
 * Optionally pass the door directory path as the first argument.
 *
 * Usage:
 *   php scripts/jsdosdoor_createmanifest.php
 *   php scripts/jsdosdoor_createmanifest.php /path/to/door-directory
 */

// Capture the door directory before any chdir()
$doorDir = isset($argv[1]) ? realpath($argv[1]) : getcwd();
if ($doorDir === false || !is_dir($doorDir)) {
    fwrite(STDERR, "Error: Door directory not found: " . ($argv[1] ?? getcwd()) . "\n");
    exit(1);
}

// Change to the binkterm root so Config and autoload work
chdir(__DIR__ . "/../");

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\AI\AiRequest;
use BinktermPHP\AI\AiService;
use BinktermPHP\Config;

// ─── Helpers ────────────────────────────────────────────────────────────────

/**
 * Print a line to stdout.
 */
function out(string $line = ''): void
{
    echo $line . "\n";
}

/**
 * Prompt the user for input, returning the trimmed response.
 * $default is shown in brackets and used when the user presses Enter.
 */
function prompt(string $question, string $default = ''): string
{
    $hint = $default !== '' ? " [{$default}]" : '';
    echo $question . $hint . ': ';
    $line = fgets(STDIN);
    if ($line === false) {
        return $default;
    }
    $input = trim($line);
    return $input !== '' ? $input : $default;
}

/**
 * Prompt for a yes/no answer. Returns true for yes.
 */
function promptBool(string $question, bool $default = true): bool
{
    $hint = $default ? '[Y/n]' : '[y/N]';
    echo $question . ' ' . $hint . ': ';
    $line = fgets(STDIN);
    if ($line === false) {
        return $default;
    }
    $input = strtolower(trim($line));
    if ($input === '') {
        return $default;
    }
    return in_array($input, ['y', 'yes'], true);
}

/**
 * Recursively scan a directory and return all file paths relative to $base.
 *
 * @return array<int, string>
 */
function scanFiles(string $dir, string $base): array
{
    $files = [];
    if (!is_dir($dir)) {
        return $files;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $rel = ltrim(str_replace($base, '', $file->getPathname()), DIRECTORY_SEPARATOR . '/');
            $rel = str_replace('\\', '/', $rel);
            $files[] = $rel;
        }
    }
    sort($files);
    return $files;
}

/**
 * Derive a safe uppercase DOS directory name from a string (max 8 chars, alphanumeric).
 */
function toDosName(string $name): string
{
    $upper = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name) ?? $name);
    return substr($upper, 0, 8) ?: 'GAME';
}

/**
 * Generate icon.png using GD if available.
 * Creates a retro-style 96×96 image with the game name on a dark background.
 */
function generateIcon(string $outputPath, string $gameTitle): bool
{
    if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
        return false;
    }

    $size = 96;
    $img = imagecreatetruecolor($size, $size);
    if ($img === false) {
        return false;
    }

    // Background gradient (dark blue to dark purple)
    for ($y = 0; $y < $size; $y++) {
        $ratio = $y / $size;
        $r = (int)(20 + $ratio * 20);
        $g = (int)(10 + $ratio * 5);
        $b = (int)(60 + $ratio * 40);
        $col = imagecolorallocate($img, $r, $g, $b);
        if ($col !== false) {
            imageline($img, 0, $y, $size - 1, $y, $col);
        }
    }

    // Border
    $borderColor = imagecolorallocate($img, 100, 80, 200);
    if ($borderColor !== false) {
        imagerectangle($img, 1, 1, $size - 2, $size - 2, $borderColor);
        imagerectangle($img, 3, 3, $size - 4, $size - 4, $borderColor);
    }

    // Game icon placeholder — draw a simple pixel-art monitor shape
    $screenColor  = imagecolorallocate($img, 30, 180, 255);
    $outlineColor = imagecolorallocate($img, 180, 160, 255);

    if ($screenColor !== false && $outlineColor !== false) {
        // Monitor body
        imagefilledrectangle($img, 22, 18, 73, 58, $outlineColor);
        imagefilledrectangle($img, 25, 21, 70, 55, $screenColor);
        // Stand
        imagefilledrectangle($img, 42, 58, 53, 64, $outlineColor);
        imagefilledrectangle($img, 35, 64, 60, 67, $outlineColor);
    }

    // Game title text — wrap to fit in ~80px wide area
    $textColor = imagecolorallocate($img, 255, 240, 100);
    if ($textColor !== false) {
        $words = explode(' ', strtoupper($gameTitle));
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $test = $current !== '' ? $current . ' ' . $word : $word;
            // Built-in font 2 is approx 6px wide per char; font 5 is 9px wide
            if (strlen($test) <= 13) {
                $current = $test;
            } else {
                if ($current !== '') {
                    $lines[] = $current;
                }
                $current = $word;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }
        // Use built-in font 2 (small, always available)
        $lineHeight = 9;
        $startY = 73;
        foreach (array_slice($lines, 0, 2) as $lineText) {
            $textWidth = strlen($lineText) * 6;
            $x = (int)(($size - $textWidth) / 2);
            imagestring($img, 2, $x, $startY, $lineText, $textColor);
            $startY += $lineHeight;
        }
    }

    $result = imagepng($img, $outputPath);
    imagedestroy($img);
    return $result !== false;
}

/**
 * Try to call the AI service to get game metadata.
 * Returns an array with 'author', 'description', 'version' keys (or empty strings).
 *
 * @return array{author: string, description: string, version: string}
 */
function fetchAiMetadata(string $gameTitle, string $executable): array
{
    $empty = ['author' => '', 'description' => '', 'version' => ''];

    try {
        $ai = AiService::create();
        if (empty($ai->getConfiguredProviders())) {
            return $empty;
        }
    } catch (\Throwable $e) {
        return $empty;
    }

    $request = new AiRequest(
        feature: 'jsdos_manifest',
        systemPrompt: 'You are an assistant that provides accurate metadata for classic DOS games. '
            . 'Return only valid JSON with no markdown or extra commentary.',
        userPrompt: sprintf(
            'Provide metadata for a classic DOS game with the title "%s" and main executable "%s". '
            . 'Return a JSON object with exactly these fields: '
            . '"author" (the original developer or publisher, e.g. "id Software"), '
            . '"description" (a 1-2 sentence description suitable for a BBS games listing), '
            . '"version" (best-known release version string, e.g. "1.0" or "1.9", or "" if unknown). '
            . 'If you are unsure about any field, leave it as an empty string.',
            $gameTitle,
            $executable
        ),
        temperature: 0.2,
        maxOutputTokens: 256,
        timeoutSeconds: 30
    );

    try {
        $response = $ai->generateJson($request);
        $data = $response->getParsedJson();
        return [
            'author'      => isset($data['author'])      && is_string($data['author'])      ? trim($data['author'])      : '',
            'description' => isset($data['description']) && is_string($data['description']) ? trim($data['description']) : '',
            'version'     => isset($data['version'])     && is_string($data['version'])     ? trim($data['version'])     : '',
        ];
    } catch (\Throwable $e) {
        return $empty;
    }
}

// ─── Main ────────────────────────────────────────────────────────────────────

out();
out("=== JS-DOS Door Manifest Creator ===");
out();

// Verify assets directory exists
$assetsDir = $doorDir . '/assets';
if (!is_dir($assetsDir)) {
    fwrite(STDERR, "Error: No assets/ directory found in: {$doorDir}\n");
    fwrite(STDERR, "Please run this script from within the door directory that contains an assets/ folder.\n");
    exit(1);
}

// Derive defaults from the door directory name
$dirName  = basename($doorDir);
$gameId   = preg_replace('/[^a-z0-9_-]/', '', strtolower($dirName)) ?: 'mygame';
$dosName  = toDosName($dirName);

out("Door directory : {$doorDir}");
out("Detected ID    : {$gameId}");
out();

// ─── User prompts ────────────────────────────────────────────────────────────

$gameTitle  = prompt("Game title", ucwords(str_replace(['-', '_'], ' ', $dirName)));
$executable = prompt("Executable (relative to assets/, e.g. DOOM.EXE or QUAKE/QUAKE.EXE)");

if ($executable === '') {
    fwrite(STDERR, "Error: Executable is required.\n");
    exit(1);
}

// Normalize executable separators
$executable = str_replace('\\', '/', $executable);

// Derive DOS directory from executable path, or use dosName
$exeParts = explode('/', $executable);
if (count($exeParts) > 1) {
    $dosDirName = strtoupper($exeParts[0]);
} else {
    $dosDirName = $dosName;
}
$exeFilename = strtoupper(end($exeParts));

// Prompt for CPU cycles
out();
out("CPU cycles presets:");
out("  1) max 90%   (modern fast games, 3D engines)");
out("  2) max 50%   (medium-speed games)");
out("  3) fixed 3000 (speed-sensitive games like Wolfenstein 3D, early 90s)");
out("  4) auto       (DOSBox decides)");
out("  5) custom");
$cyclesChoice = prompt("Select preset", "1");

$cpuCycles = match($cyclesChoice) {
    '2'     => 'max 50%',
    '3'     => 'fixed 3000',
    '4'     => 'auto',
    '5'     => prompt("Enter custom cycles value", 'max 90%'),
    default => 'max 90%',
};

// Machine type
out();
out("Machine type:");
out("  1) svga_s3   (default, most late-DOS games)");
out("  2) vga       (standard VGA)");
out("  3) ega       (EGA games)");
out("  4) cga       (CGA games)");
out("  5) hercules  (Hercules monochrome)");
out("  6) custom");
$machineChoice = prompt("Select machine type", "1");

$machine = match($machineChoice) {
    '2'     => 'vga',
    '3'     => 'ega',
    '4'     => 'cga',
    '5'     => 'hercules',
    '6'     => prompt("Enter custom machine type", 'svga_s3'),
    default => 'svga_s3',
};

$memoryMb = (int)prompt("Emulated memory (MB)", "16");
if ($memoryMb < 1) {
    $memoryMb = 16;
}

// Sound Blaster setup
$useBlaster = promptBool("Add Sound Blaster environment variable (SET BLASTER=...)?", true);
$blasterLine = $useBlaster ? 'SET BLASTER=A220 I7 D1 T4' : '';

// Saves
$enableSaves = promptBool("Enable per-user save files?", true);
$savePaths   = [];
if ($enableSaves) {
    out("Enter save file glob patterns (DOS paths, one per line, blank to finish).");
    out("Example: C:/{$dosDirName}/SAVE*.DAT");
    out("         C:/{$dosDirName}/*.CFG");
    while (true) {
        $sp = prompt("  Save path (or blank to finish)", '');
        if ($sp === '') {
            break;
        }
        $savePaths[] = $sp;
    }
    if (empty($savePaths)) {
        // Default to common save patterns
        $savePaths = ["C:/{$dosDirName}/*.SAV", "C:/{$dosDirName}/*.CFG"];
        out("Using defaults: " . implode(', ', $savePaths));
    }
}

// Config mode shared file (for admin setup)
$sharedCfgPath = "C:/{$dosDirName}/DEFAULT.CFG";
$sharedCfgPath = prompt("Shared config file path for admin mode saves", $sharedCfgPath);

// ─── Scan assets ─────────────────────────────────────────────────────────────

out();
out("Scanning assets/ ...");

$allFiles = scanFiles($assetsDir, $doorDir . '/');

if (empty($allFiles)) {
    out("Warning: No files found in assets/. The manifest will have empty game_files.");
}

// Build game_files for play mode
$gameFiles = [];
foreach ($allFiles as $relPath) {
    // relPath is like "assets/DOOM.EXE"
    $parts        = explode('/', $relPath, 2);
    $afterAssets  = isset($parts[1]) ? $parts[1] : $relPath;  // e.g. "DOOM.EXE" or "subdir/FILE.EXT"
    $dosPath      = 'C:/' . $dosDirName . '/' . strtoupper($afterAssets);

    $gameFiles[] = [
        'asset_path' => $relPath,
        'dos_path'   => $dosPath,
    ];
}

out("Found " . count($gameFiles) . " file(s) in assets/.");

// Detect common setup executables for admin config mode
$setupExe = null;
$setupCandidates = ['SETUP.EXE', 'INSTALL.EXE', 'INSTALL.BAT', 'CONFIG.EXE', 'SETSOUND.EXE'];
foreach ($allFiles as $f) {
    $basename = strtoupper(basename($f));
    if (in_array($basename, $setupCandidates, true)) {
        $setupExe = $f;
        out("Detected setup executable: {$f}");
        break;
    }
}

// ─── AI metadata ─────────────────────────────────────────────────────────────

out();
out("Fetching AI-generated metadata (author, description, version) ...");
out("(This may take a few seconds. Requires AI API key in .env)");

$aiMeta = fetchAiMetadata($gameTitle, $exeFilename);

$author      = $aiMeta['author']      !== '' ? $aiMeta['author']      : '';
$description = $aiMeta['description'] !== '' ? $aiMeta['description'] : '';
$version     = $aiMeta['version']     !== '' ? $aiMeta['version']     : '';

if ($author !== '' || $description !== '') {
    out("AI suggested:");
    if ($author !== '')      out("  Author     : {$author}");
    if ($description !== '') out("  Description: {$description}");
    if ($version !== '')     out("  Version    : {$version}");
    out();
}

// Allow overrides
$author      = prompt("Author / developer", $author);
$description = prompt("Description", $description);
$version     = prompt("Version", $version !== '' ? $version : '1.0');

// ─── Build autoexec ──────────────────────────────────────────────────────────

$autoexec = ['C:', "cd {$dosDirName}"];
if ($blasterLine !== '') {
    $autoexec[] = $blasterLine;
}
$autoexec[] = $exeFilename;

// Admin config autoexec — same but without launching the game, so admin lands at a prompt
$configAutoexec = ['C:', "cd {$dosDirName}"];
if ($blasterLine !== '') {
    $configAutoexec[] = $blasterLine;
}
// If a setup exe was found, run it; otherwise leave at DOS prompt
if ($setupExe !== null) {
    $configAutoexec[] = strtoupper(basename($setupExe));
}

// Build config-mode game_files (all files marked optional so partial installs work)
$configGameFiles = array_map(function ($gf) {
    return array_merge($gf, ['optional' => true]);
}, $gameFiles);

// ─── Build the manifest ──────────────────────────────────────────────────────

$manifest = [
    'id'          => $gameId,
    'name'        => $gameTitle,
    'version'     => $version,
    'author'      => $author,
    'description' => $description,
    'icon'        => 'icon.png',
    'emulator'    => 'jsdos',
    'emulator_config' => [
        'cpu_cycles' => $cpuCycles,
        'memory_mb'  => $memoryMb,
        'machine'    => $machine,
        'output'     => 'openglnb',
        'scaler'     => 'normal3x forced',
        'autolock'   => true,
        'game_files' => $gameFiles,
        'autoexec'   => $autoexec,
    ],
    'modes' => [
        'config' => [
            'label'       => 'Admin Setup',
            'description' => 'Admin-only configuration mode. Launches to a DOS prompt so you can run setup utilities and create or update shared defaults.',
            'admin_only'  => true,
            'keep_open'   => true,
            'emulator_config' => [
                'cpu_cycles' => $cpuCycles,
                'memory_mb'  => $memoryMb,
                'machine'    => $machine,
                'output'     => 'surface',
                'autolock'   => true,
                'game_files' => $configGameFiles,
                'autoexec'   => $configAutoexec,
            ],
            'saves' => [
                'enabled'    => true,
                'scope'      => 'shared',
                'save_paths' => [$sharedCfgPath],
                'max_size_kb' => 512,
            ],
        ],
    ],
    'credits' => [
        'session_cost' => 0,
    ],
];

// Add saves section if enabled
if ($enableSaves && !empty($savePaths)) {
    $manifest['saves'] = [
        'enabled'     => true,
        'scope'       => 'user',
        'save_paths'  => $savePaths,
        'max_size_kb' => 512,
    ];
}

// ─── Remove empty strings from top-level optional fields ────────────────────

foreach (['version', 'author', 'description'] as $field) {
    if ($manifest[$field] === '') {
        unset($manifest[$field]);
    }
}

// ─── Preview ─────────────────────────────────────────────────────────────────

$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

out();
out("=== Generated jsdosdoor.json ===");
out($json);
out();

// ─── Confirm write ────────────────────────────────────────────────────────────

$outputFile = $doorDir . '/jsdosdoor.json';
$willOverwrite = file_exists($outputFile);
if ($willOverwrite) {
    out("WARNING: {$outputFile} already exists.");
}

if (!promptBool("Write jsdosdoor.json to {$doorDir}?", true)) {
    out("Aborted. No files written.");
    exit(0);
}

if (file_put_contents($outputFile, $json . "\n") === false) {
    fwrite(STDERR, "Error: Could not write {$outputFile}\n");
    exit(1);
}

out("Written: {$outputFile}");

// ─── Icon generation ─────────────────────────────────────────────────────────

$iconFile = $doorDir . '/icon.png';
if (file_exists($iconFile)) {
    $generateIcon = promptBool("icon.png already exists. Overwrite with a generated placeholder?", false);
} else {
    $generateIcon = promptBool("Generate a placeholder icon.png?", true);
}

if ($generateIcon) {
    if (generateIcon($iconFile, $gameTitle)) {
        out("Written: {$iconFile}");
    } else {
        out("Warning: GD extension not available — icon.png was not generated.");
        out("         Place a 96x96 PNG at: {$iconFile}");
    }
}

// ─── Next steps ──────────────────────────────────────────────────────────────

out();
out("=== Next steps ===");
out("1. Review and edit {$outputFile} as needed.");
out("   - Adjust emulator_config settings (cpu_cycles, machine, scaler) for the game.");
out("   - Add or remove entries from game_files and autoexec.");
out("2. Ensure all files listed in game_files exist under assets/.");
out("3. Enable the game in config/jsdosdoors.json:");
out("     {");
out("       \"{$gameId}\": { \"enabled\": true }");
out("     }");
out("   (Use the admin interface at /admin/jsdosdoors to create/edit this file.)");
out("4. Visit /games to verify the door appears with the [JSDOS] badge.");
if ($icon = ($generateIcon ? $iconFile : null)) {
    // already generated
} else {
    out("5. Place a 96x96 icon.png at: {$iconFile}");
}
out();
