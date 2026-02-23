#!/usr/bin/env php
<?php

/*
 * File Area Rule Testing Tool
 *
 * Tests file area rules against a given filename and area tag to see
 * which rules match and what actions would be executed.
 *
 * Usage: php test_filearea_rules.php [options] <filename> <areatag>
 *
 * Options:
 *   --dry-run         Don't execute scripts, just show what would run (default)
 *   --execute         Actually execute the scripts
 *   --create-file     Create a temporary test file (for testing with real files)
 *   --domain=DOMAIN   Specify domain (optional, auto-detected if not provided)
 *   --verbose         Show detailed output including substituted commands
 *   --help            Show this help message
 *
 * Examples:
 *   php test_filearea_rules.php test.zip FILES
 *   php test_filearea_rules.php --domain=fidonet virus.exe FILES
 *   php test_filearea_rules.php --verbose --execute archive.tar.gz UPLOADS
 *   php test_filearea_rules.php --create-file --execute test.zip FILES
 */

chdir(__DIR__ . "/../");

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/functions.php';

use BinktermPHP\FileArea\FileAreaRuleProcessor;
use BinktermPHP\FileAreaManager;

/**
 * Test version of FileAreaRuleProcessor that provides dry-run mode
 * and exposes internal details for testing.
 */
class TestFileAreaRuleProcessor extends FileAreaRuleProcessor
{
    private bool $dryRun = true;
    private bool $verbose = false;
    private array $matchedRules = [];
    private array $executionResults = [];
    private ?string $forceDomain = null;

    public function setDryRun(bool $dryRun): void
    {
        $this->dryRun = $dryRun;
    }

    public function setVerbose(bool $verbose): void
    {
        $this->verbose = $verbose;
    }

    public function setForceDomain(?string $domain): void
    {
        $this->forceDomain = $domain;
    }

    public function getMatchedRules(): array
    {
        return $this->matchedRules;
    }

    public function getExecutionResults(): array
    {
        return $this->executionResults;
    }

    /**
     * Override processFile to capture matched rules and control execution
     */
    public function testFile(string $filename, string $areatag): array
    {
        // Create a temporary file path for testing
        $tempFilepath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename;

        // If testing with a real file path that exists, use it
        if (file_exists($filename)) {
            $tempFilepath = $filename;
        }

        // Use reflection to access private methods
        $reflection = new ReflectionClass(FileAreaRuleProcessor::class);

        // Load rules
        $loadRulesMethod = $reflection->getMethod('loadRules');
        $loadRulesMethod->setAccessible(true);
        $rulesData = $loadRulesMethod->invoke($this);

        // Determine domain
        $areaDomain = $this->forceDomain ?? $this->getDomainForTestFile($tempFilepath, $areatag);

        // Get rules for this area
        $areaKey = strtoupper($areatag);
        $domainKey = strtoupper($areatag . '@' . $areaDomain);
        $globalRules = $rulesData['global_rules'] ?? [];
        $areaRules = $rulesData['area_rules'][$domainKey]
            ?? ($rulesData['area_rules'][$areaKey] ?? []);
        $allRules = array_merge($globalRules, $areaRules);

        // Match rules
        $matchRulesMethod = $reflection->getMethod('matchRules');
        $matchRulesMethod->setAccessible(true);
        $this->matchedRules = $matchRulesMethod->invoke($this, basename($filename), $allRules, $areaDomain);

        // Build context
        $buildContextMethod = $reflection->getMethod('buildContext');
        $buildContextMethod->setAccessible(true);
        $context = $buildContextMethod->invoke($this, $tempFilepath, $areatag);

        // Override domain if forced
        if ($this->forceDomain !== null) {
            $context['domain'] = $this->forceDomain;
        }

        // For each matched rule, show what would happen
        foreach ($this->matchedRules as $rule) {
            $result = $this->testRule($rule, $context, $tempFilepath, $areatag);
            $this->executionResults[] = $result;
        }

        return [
            'rules_data' => $rulesData,
            'domain' => $areaDomain,
            'matched' => count($this->matchedRules),
            'results' => $this->executionResults
        ];
    }

    private function testRule(array $rule, array $context, string $filepath, string $areatag): array
    {
        $ruleName = $rule['name'] ?? 'Unnamed Rule';
        $scriptTemplate = $rule['script'] ?? '';
        $timeout = (int)($rule['timeout'] ?? 600);
        $successAction = $rule['success_action'] ?? 'none';
        $failAction = $rule['fail_action'] ?? 'none';

        // Use reflection to substitute macros
        $reflection = new ReflectionClass(FileAreaRuleProcessor::class);
        $substituteMethod = $reflection->getMethod('substituteMacros');
        $substituteMethod->setAccessible(true);
        $command = $substituteMethod->invoke($this, $scriptTemplate, $context);

        $result = [
            'rule_name' => $ruleName,
            'pattern' => $rule['pattern'] ?? '',
            'domain' => $rule['domain'] ?? null,
            'template' => $scriptTemplate,
            'command' => $command,
            'timeout' => $timeout,
            'success_action' => $successAction,
            'fail_action' => $failAction,
            'executed' => false,
            'exit_code' => null,
            'stdout' => null,
            'stderr' => null
        ];

        // If not dry-run, actually execute (file existence is the script's concern)
        if (!$this->dryRun) {
            if (!file_exists($filepath)) {
                $result['skipped'] = true;
                $result['skip_reason'] = "File not found: {$filepath} (use --create-file to create a temporary test file)";
            } else {
                $executeMethod = $reflection->getMethod('executeScript');
                $executeMethod->setAccessible(true);
                $execResult = $executeMethod->invoke($this, $command, $timeout);

                $result['executed'] = true;
                $result['exit_code'] = $execResult['exit_code'];
                $result['stdout'] = $execResult['stdout'];
                $result['stderr'] = $execResult['stderr'];
                $result['timed_out'] = $execResult['timed_out'];
                $result['success'] = !$execResult['timed_out'] && $execResult['exit_code'] === 0;
            }
        }

        return $result;
    }

    private function getDomainForTestFile(string $filepath, string $areatag): string
    {
        $fileAreaManager = new FileAreaManager();
        return $fileAreaManager->getDomainForArea($areatag);
    }
}

function showUsage()
{
    echo "Usage: php test_filearea_rules.php [options] <filename> <areatag>\n\n";
    echo "Options:\n";
    echo "  --dry-run         Don't execute scripts, just show what would run (default)\n";
    echo "  --execute         Actually execute the scripts\n";
    echo "  --create-file     Create a temporary test file\n";
    echo "  --domain=DOMAIN   Specify domain (optional)\n";
    echo "  --verbose         Show detailed output including substituted commands\n";
    echo "  --help            Show this help message\n\n";
    echo "Examples:\n";
    echo "  php test_filearea_rules.php test.zip FILES\n";
    echo "  php test_filearea_rules.php --domain=fidonet virus.exe FILES\n";
    echo "  php test_filearea_rules.php --verbose --execute archive.tar.gz UPLOADS\n";
    echo "  php test_filearea_rules.php --create-file --execute test.zip FILES\n\n";
}

function parseArgs($argv)
{
    $args = [
        'dry_run' => true,
        'verbose' => false,
        'create_file' => false,
        'domain' => null,
        'filename' => null,
        'areatag' => null
    ];

    $positional = [];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];

        if ($arg === '--help' || $arg === '-h') {
            showUsage();
            exit(0);
        } elseif ($arg === '--dry-run') {
            $args['dry_run'] = true;
        } elseif ($arg === '--execute') {
            $args['dry_run'] = false;
        } elseif ($arg === '--create-file') {
            $args['create_file'] = true;
        } elseif ($arg === '--verbose' || $arg === '-v') {
            $args['verbose'] = true;
        } elseif (strpos($arg, '--domain=') === 0) {
            $args['domain'] = substr($arg, 9);
        } elseif (strpos($arg, '--') === 0) {
            echo "Unknown option: $arg\n\n";
            showUsage();
            exit(1);
        } else {
            $positional[] = $arg;
        }
    }

    if (count($positional) < 2) {
        echo "Error: filename and areatag are required\n\n";
        showUsage();
        exit(1);
    }

    $args['filename'] = $positional[0];
    $args['areatag'] = strtoupper($positional[1]);

    return $args;
}


// Main execution
try {
    $args = parseArgs($argv);
    $filename = $args['filename'];
    $areatag = $args['areatag'];
    $verbose = $args['verbose'];
    $dryRun = $args['dry_run'];
    $createFile = $args['create_file'];

    // Create temporary file if requested
    $testFilePath = $filename;
    if ($createFile) {
        $testFilePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($testFilePath, "Test file for rule testing\n");
        echo "Created temporary test file: {$testFilePath}\n\n";
    }

    // Create test processor
    $processor = new TestFileAreaRuleProcessor();
    $processor->setDryRun($dryRun);
    $processor->setVerbose($verbose);
    $processor->setForceDomain($args['domain']);

    // Run test
    $result = $processor->testFile($filename, $areatag);
    $rulesData = $result['rules_data'];
    $domain = $result['domain'];
    $matchedCount = $result['matched'];
    $executionResults = $result['results'];

    // Display results
    echo "╔══════════════════════════════════════════════════════════════════════\n";
    echo "║ File Area Rule Test\n";
    echo "╠══════════════════════════════════════════════════════════════════════\n";
    echo "║ Filename: {$filename}\n";
    echo "║ Area Tag: {$areatag}\n";
    echo "║ Domain:   {$domain}\n";
    echo "║ Mode:     " . ($dryRun ? "DRY RUN (no execution)" : "EXECUTE") . "\n";
    echo "╚══════════════════════════════════════════════════════════════════════\n\n";

    $globalRules = $rulesData['global_rules'] ?? [];
    $areaKey = strtoupper($areatag);
    $domainKey = strtoupper($areatag . '@' . $domain);
    $areaRules = $rulesData['area_rules'][$domainKey]
        ?? ($rulesData['area_rules'][$areaKey] ?? []);

    echo "Loaded Rules:\n";
    echo "  Global rules: " . count($globalRules) . "\n";
    echo "  Area rules ({$areaKey}): " . count($areaRules) . "\n\n";

    if (count($globalRules) + count($areaRules) === 0) {
        echo "⚠ No rules configured for this area.\n";
        exit(0);
    }

    echo "═══════════════════════════════════════════════════════════════════════\n";
    echo "Testing Pattern Matches...\n";
    echo "═══════════════════════════════════════════════════════════════════════\n\n";

    if ($matchedCount === 0) {
        echo "✗ No matching rules found.\n";
        exit(0);
    }

    echo "✓ Found {$matchedCount} matching rule(s):\n\n";

    // Show each matched rule
    foreach ($executionResults as $index => $execResult) {
        $ruleNum = $index + 1;
        $ruleName = $execResult['rule_name'];
        $pattern = $execResult['pattern'];
        $ruleDomain = $execResult['domain'];
        $successAction = $execResult['success_action'];
        $failAction = $execResult['fail_action'];
        $timeout = $execResult['timeout'];

        echo "┌─────────────────────────────────────────────────────────────────────\n";
        echo "│ Rule #{$ruleNum}: {$ruleName}\n";
        echo "├─────────────────────────────────────────────────────────────────────\n";
        echo "│ Pattern: {$pattern}\n";
        if ($ruleDomain) {
            echo "│ Domain Filter: {$ruleDomain}\n";
        }
        echo "│ Timeout: {$timeout} seconds\n";
        echo "│ Success Action: {$successAction}\n";
        echo "│ Fail Action: {$failAction}\n";

        echo "├─────────────────────────────────────────────────────────────────────\n";
        echo "│ Command:\n";
        if ($verbose) {
            echo "│   Template: {$execResult['template']}\n";
            echo "│   Substituted: {$execResult['command']}\n";
        } else {
            echo "│   {$execResult['command']}\n";
        }

        // Show skip reason if file was not found
        if (!empty($execResult['skipped'])) {
            echo "├─────────────────────────────────────────────────────────────────────\n";
            echo "│ ⚠ Skipped: {$execResult['skip_reason']}\n";
        }

        // Show execution results if not dry-run
        if ($execResult['executed']) {
            echo "├─────────────────────────────────────────────────────────────────────\n";
            echo "│ Execution Result:\n";
            echo "│   Exit Code: {$execResult['exit_code']}\n";
            echo "│   Success: " . ($execResult['success'] ? 'YES' : 'NO') . "\n";
            if ($execResult['timed_out']) {
                echo "│   ⚠ TIMED OUT\n";
            }
            if (!empty($execResult['stdout'])) {
                echo "│   Stdout:\n";
                foreach (explode("\n", trim($execResult['stdout'])) as $line) {
                    echo "│     {$line}\n";
                }
            }
            if (!empty($execResult['stderr'])) {
                echo "│   Stderr:\n";
                foreach (explode("\n", trim($execResult['stderr'])) as $line) {
                    echo "│     {$line}\n";
                }
            }
            $actionToTake = $execResult['success'] ? $successAction : $failAction;
            echo "│   Action to Take: {$actionToTake}\n";
        }

        echo "└─────────────────────────────────────────────────────────────────────\n\n";
    }

    // Summary
    echo "═══════════════════════════════════════════════════════════════════════\n";
    echo "Summary\n";
    echo "═══════════════════════════════════════════════════════════════════════\n";
    echo "Total rules matched: {$matchedCount}\n";

    if ($dryRun) {
        echo "\n⚠ DRY RUN mode - no scripts were executed.\n";
        echo "  Use --execute flag to actually run the scripts.\n";
        if (!$createFile && !file_exists($testFilePath)) {
            echo "  Use --create-file to create a temporary test file.\n";
        }
    } else {
        $executedCount = count(array_filter($executionResults, fn($r) => $r['executed']));
        $successCount = count(array_filter($executionResults, fn($r) => $r['executed'] && $r['success']));
        $failCount = count(array_filter($executionResults, fn($r) => $r['executed'] && !$r['success']));
        $skippedCount = count(array_filter($executionResults, fn($r) => !empty($r['skipped'])));
        if ($executedCount > 0) {
            echo "\n✓ Executed {$executedCount} rule(s): {$successCount} succeeded, {$failCount} failed.\n";
        }
        if ($skippedCount > 0) {
            echo "\n⚠ Skipped {$skippedCount} rule(s) — file not found. Use --create-file to create a temporary test file.\n";
        }
    }

    echo "\n✓ Test complete.\n";

    // Cleanup temporary file if created
    if ($createFile && file_exists($testFilePath)) {
        unlink($testFilePath);
        echo "\nCleaned up temporary file.\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
