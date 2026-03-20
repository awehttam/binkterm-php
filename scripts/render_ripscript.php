#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\RipScriptRenderer;

function printUsage(): void
{
    fwrite(STDOUT, "Usage: php scripts/render_ripscript.php [--html|--plain] [file]\n");
    fwrite(STDOUT, "Reads RIPscrip source from a file or stdin and renders a readable view.\n");
}

$args = $argv;
array_shift($args);

$mode = 'ansi';
$path = null;

foreach ($args as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        printUsage();
        exit(0);
    }

    if ($arg === '--html') {
        $mode = 'html';
        continue;
    }

    if ($arg === '--plain') {
        $mode = 'plain';
        continue;
    }

    if ($path === null) {
        $path = $arg;
        continue;
    }

    fwrite(STDERR, "Unexpected argument: {$arg}\n");
    printUsage();
    exit(1);
}

if ($path !== null) {
    $source = @file_get_contents($path);
    if ($source === false) {
        fwrite(STDERR, "Unable to read file: {$path}\n");
        exit(1);
    }
} else {
    $source = stream_get_contents(STDIN);
    if ($source === false) {
        fwrite(STDERR, "Unable to read stdin\n");
        exit(1);
    }
}

$renderer = new RipScriptRenderer($source);

switch ($mode) {
    case 'html':
        fwrite(STDOUT, $renderer->getHTML() . PHP_EOL);
        break;

    case 'plain':
        fwrite(STDOUT, $renderer->getPlainText());
        if ($source !== '' && !str_ends_with($source, "\n")) {
            fwrite(STDOUT, PHP_EOL);
        }
        break;

    default:
        fwrite(STDOUT, $renderer->getAnsi() . PHP_EOL);
        break;
}
