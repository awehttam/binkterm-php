#!/usr/bin/env php
<?php

/**
 * download_jsdos.php
 *
 * Downloads the js-dos 6.22 library files from the official CDN and places them
 * under public_html/js/jsdos/. The wdosbox.js file is patched to set
 * Module.locateFile so that its companion WASM file is resolved from /js/jsdos/
 * rather than relative to the page URL (which breaks when js-dos creates a Blob
 * worker from the downloaded content).
 *
 * Usage:  php scripts/download_jsdos.php
 */

$baseDir = __DIR__ . '/../public_html/js/jsdos';

if (!is_dir($baseDir) && !mkdir($baseDir, 0755, true)) {
    fwrite(STDERR, "ERROR: Could not create directory: $baseDir\n");
    exit(1);
}

$cdnBase = 'https://js-dos.com/6.22/current';

$files = [
    'js-dos.js',
    'wdosbox.js',
    'wdosbox.wasm.js',
];

foreach ($files as $file) {
    $url     = "$cdnBase/$file";
    $destPath = "$baseDir/$file";

    echo "Downloading $url ...\n";

    $content = @file_get_contents($url, false, stream_context_create([
        'http' => ['timeout' => 60],
        'ssl'  => ['verify_peer' => true],
    ]));

    if ($content === false) {
        fwrite(STDERR, "ERROR: Failed to download $url\n");
        fwrite(STDERR, "Check your internet connection and that the URL is reachable.\n");
        exit(1);
    }

    // Patch wdosbox.js: prepend a Module.locateFile override so that the
    // Emscripten runtime resolves its WASM companion from /js/jsdos/ regardless
    // of the Blob worker context in which it executes.
    if ($file === 'wdosbox.js') {
        $patch  = "/* js-dos path patch */\n";
        $patch .= "if (typeof Module === 'undefined') { Module = {}; }\n";
        $patch .= "Module['locateFile'] = function(path) { return '/js/jsdos/' + path; };\n";
        $patch .= "/* end patch */\n\n";
        $content = $patch . $content;
        echo "  -> Patched with Module.locateFile override.\n";
    }

    if (file_put_contents($destPath, $content) === false) {
        fwrite(STDERR, "ERROR: Could not write $destPath\n");
        exit(1);
    }

    echo "  -> Saved to $destPath (" . number_format(strlen($content)) . " bytes)\n";
}

echo "\nDone. Files installed to public_html/js/jsdos/.\n";
echo "You can now visit /games/jsdos/<game-id> to play a JS-DOS door.\n";
