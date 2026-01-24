#!/usr/bin/php
<?php
/**
 * Command-line nodelist import script for binkterm-php
 * Usage: php import_nodelist.php <nodelist_file> [--force]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;
use BinktermPHP\Nodelist\NodelistManager;

function initializeLogging() {
    $logDir = __DIR__ . '/../data/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    //$logFile = $logDir . '/nodelist_import_' . date('Y-m-d') . '.log';
    $logFile = $logDir . '/nodelist_import.log';
    return $logFile;
}

function writeLog($message, $logFile = null) {
    static $defaultLogFile = null;
    
    if ($defaultLogFile === null) {
        $defaultLogFile = initializeLogging();
    }
    
    $logFile = $logFile ?: $defaultLogFile;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";
    
    // Write to log file
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    
    // Also echo to console
    echo $message . "\n";
}

function printUsage() {
    echo "Usage: php import_nodelist.php <nodelist_file> <domain> [--force]\n";
    echo "\nSupported formats:\n";
    echo "  - Plain text: NODELIST.xxx\n";
    echo "  - ZIP: NODELIST.Zxx (requires zip extension)\n";
    echo "  - ARC: NODELIST.Axx (requires external arc command)\n";
    echo "  - ARJ: NODELIST.Jxx (requires external arj command)\n";
    echo "  - LZH: NODELIST.Lxx (requires external lha command)\n";
    echo "  - RAR: NODELIST.Rxx (requires external rar command)\n";
    echo "\nOptions:\n";
    echo "  --force     Skip confirmation prompts\n";
    echo "  --help      Show this help message\n";
    echo "\nExamples:\n";
    echo "  php import_nodelist.php NODELIST.001 fidonet \n";
    echo "  php import_nodelist.php NODELIST.Z150 fidonet --force\n";
    echo "  php import_nodelist.php NODELIST.A365 fidonet\n";
}

function detectArchiveType($filename) {
    $ext = strtoupper(pathinfo($filename, PATHINFO_EXTENSION));
    
    if (preg_match('/^Z(\d+)$/', $ext, $matches)) {
        return 'ZIP';
    } elseif (preg_match('/^A(\d+)$/', $ext, $matches)) {
        return 'ARC';
    } elseif (preg_match('/^J(\d+)$/', $ext, $matches)) {
        return 'ARJ';
    } elseif (preg_match('/^L(\d+)$/', $ext, $matches)) {
        return 'LZH';
    } elseif (preg_match('/^R(\d+)$/', $ext, $matches)) {
        return 'RAR';
    }
    
    return 'PLAIN';
}

function extractArchive($archiveFile, $type, $tempDir) {
    $extractedFile = null;
    
    switch ($type) {
        case 'ZIP':
            if (!extension_loaded('zip')) {
                throw new Exception("ZIP extension not available");
            }
            
            $zip = new ZipArchive;
            if ($zip->open($archiveFile) === TRUE) {
                // Look for nodelist file inside zip
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $filename = $zip->getNameIndex($i);
                    if (preg_match('/nodelist/i', $filename) || preg_match('/\.(\d{3})$/', $filename)) {
                        $extractedFile = $tempDir . DIRECTORY_SEPARATOR . basename($filename);
                        $zip->extractTo($tempDir, $filename);
                        if (basename($filename) !== basename($extractedFile)) {
                            rename($tempDir . DIRECTORY_SEPARATOR . $filename, $extractedFile);
                        }
                        break;
                    }
                }
                $zip->close();
            } else {
                throw new Exception("Could not open ZIP file");
            }
            break;
            
        case 'ARC':
            $extractedFile = $tempDir . DIRECTORY_SEPARATOR . 'NODELIST.TXT';
            $cmd = "arc e \"$archiveFile\" \"$tempDir\"";
            exec($cmd, $output, $returnCode);
            if ($returnCode !== 0) {
                throw new Exception("ARC extraction failed. Make sure 'arc' command is available.");
            }
            // Find the extracted nodelist file
            $files = glob($tempDir . DIRECTORY_SEPARATOR . '*');
            foreach ($files as $file) {
                if (is_file($file) && (preg_match('/nodelist/i', basename($file)) || preg_match('/\.(\d{3})$/', basename($file)))) {
                    $extractedFile = $file;
                    break;
                }
            }
            break;
            
        case 'ARJ':
            $extractedFile = $tempDir . DIRECTORY_SEPARATOR . 'NODELIST.TXT';
            $cmd = "arj e \"$archiveFile\" \"$tempDir\"";
            exec($cmd, $output, $returnCode);
            if ($returnCode !== 0) {
                throw new Exception("ARJ extraction failed. Make sure 'arj' command is available.");
            }
            // Find the extracted nodelist file
            $files = glob($tempDir . DIRECTORY_SEPARATOR . '*');
            foreach ($files as $file) {
                if (is_file($file) && (preg_match('/nodelist/i', basename($file)) || preg_match('/\.(\d{3})$/', basename($file)))) {
                    $extractedFile = $file;
                    break;
                }
            }
            break;
            
        case 'LZH':
            $extractedFile = $tempDir . DIRECTORY_SEPARATOR . 'NODELIST.TXT';
            $cmd = "lha e \"$archiveFile\" \"$tempDir\"";
            exec($cmd, $output, $returnCode);
            if ($returnCode !== 0) {
                throw new Exception("LZH extraction failed. Make sure 'lha' command is available.");
            }
            // Find the extracted nodelist file
            $files = glob($tempDir . DIRECTORY_SEPARATOR . '*');
            foreach ($files as $file) {
                if (is_file($file) && (preg_match('/nodelist/i', basename($file)) || preg_match('/\.(\d{3})$/', basename($file)))) {
                    $extractedFile = $file;
                    break;
                }
            }
            break;
            
        case 'RAR':
            $extractedFile = $tempDir . DIRECTORY_SEPARATOR . 'NODELIST.TXT';
            $cmd = "rar e \"$archiveFile\" \"$tempDir\"";
            exec($cmd, $output, $returnCode);
            if ($returnCode !== 0) {
                throw new Exception("RAR extraction failed. Make sure 'rar' command is available.");
            }
            // Find the extracted nodelist file
            $files = glob($tempDir . DIRECTORY_SEPARATOR . '*');
            foreach ($files as $file) {
                if (is_file($file) && (preg_match('/nodelist/i', basename($file)) || preg_match('/\.(\d{3})$/', basename($file)))) {
                    $extractedFile = $file;
                    break;
                }
            }
            break;
            
        default:
            return $archiveFile; // Plain text file
    }
    
    if (!$extractedFile || !file_exists($extractedFile)) {
        throw new Exception("Could not find nodelist file in archive");
    }
    
    return $extractedFile;
}

function cleanupTempFiles($tempDir) {
    if (is_dir($tempDir)) {
        $files = glob($tempDir . DIRECTORY_SEPARATOR . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($tempDir);
    }
}

function main($argc, $argv) {
    if ($argc < 2 || in_array('--help', $argv)) {
        printUsage();
        exit(0);
    }


    $nodelistFile = $argv[1];
    $domain=$argv[2];

    if(!$domain) {
        echo "You must specify the network domain, eg: fidonet, fsxnet, testnet, etc\n";
        exit(-1);
    }
    $force = in_array('--force', $argv);

    if (!file_exists($nodelistFile)) {
        echo "Error: Nodelist file not found: {$nodelistFile}\n";
        exit(1);
    }
    
    if (!is_readable($nodelistFile)) {
        echo "Error: Cannot read nodelist file: {$nodelistFile}\n";
        exit(1);
    }
    
    writeLog("BinkTerm-PHP Nodelist Importer");
    writeLog("==============================");
    writeLog("File: {$nodelistFile}");
    writeLog("Size: " . number_format(filesize($nodelistFile)) . " bytes");
    
    // Detect archive type and extract if needed
    $archiveType = detectArchiveType($nodelistFile);
    $actualNodelistFile = $nodelistFile;
    $tempDir = null;
    
    if ($archiveType !== 'PLAIN') {
        writeLog("Format: {$archiveType} archive");
        writeLog("Extracting archive...");
        
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nodelist_' . uniqid();
        mkdir($tempDir);
        
        try {
            $actualNodelistFile = extractArchive($nodelistFile, $archiveType, $tempDir);
            writeLog("Extracted to: " . basename($actualNodelistFile));
            writeLog("Extracted size: " . number_format(filesize($actualNodelistFile)) . " bytes");
        } catch (Exception $e) {
            cleanupTempFiles($tempDir);
            writeLog("Error: " . $e->getMessage());
            exit(1);
        }
    } else {
        writeLog("Format: Plain text");
    }
    writeLog("");
    
    try {
        $nodelistManager = new NodelistManager();
        
        // Check for existing nodelist
        $activeNodelist = $nodelistManager->getActiveNodelist();
        if ($activeNodelist && !$force) {
            writeLog("Warning: An active nodelist already exists:");
            writeLog("  File: {$activeNodelist['filename']}");
            writeLog("  Date: {$activeNodelist['release_date']}");
            writeLog("  Nodes: " . number_format($activeNodelist['total_nodes']));
            writeLog("");
            writeLog("This will archive the current nodelist and import the new one.");
            echo "Continue? (y/N): ";
            
            $response = trim(fgets(STDIN));
            if (strtolower($response) !== 'y' && strtolower($response) !== 'yes') {
                writeLog("Import cancelled by user.");
                exit(0);
            }
            writeLog("User confirmed import continuation.");
            writeLog("");
        }
        
        writeLog("Starting import...");
        $startTime = microtime(true);
        
        $result = $nodelistManager->importNodelist($actualNodelistFile,$domain, true);
        
        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);
        
        writeLog("Import completed successfully!");
        writeLog("");
        writeLog("Import Results:");
        writeLog("==============");
        writeLog("  Filename: {$result['filename']}");
        writeLog("  Total nodes processed: " . number_format($result['total_nodes']));
        writeLog("  Successfully inserted: " . number_format($result['inserted_nodes']));
        writeLog("  Failed/skipped: " . number_format($result['total_nodes'] - $result['inserted_nodes']));
        writeLog("  Duration: {$duration} seconds");
        writeLog("  Processing rate: " . number_format($result['total_nodes'] / max($duration, 0.001), 0) . " nodes/sec");
        writeLog("");
        
        // Show detailed statistics
        $stats = $nodelistManager->getNodelistStats();
        writeLog("Nodelist Statistics:");
        writeLog("===================");
        writeLog("  Total nodes: " . number_format($stats['total_nodes']));
        writeLog("  Zones: " . $stats['total_zones']);
        writeLog("  Networks: " . $stats['total_nets']);
        writeLog("  Point systems: " . number_format($stats['point_nodes']));
        writeLog("  Special nodes: " . number_format($stats['special_nodes']) . " (PVT, HOLD, DOWN, etc.)");
        writeLog("  Regular nodes: " . number_format($stats['total_nodes'] - $stats['point_nodes'] - $stats['special_nodes']));
        
        // Calculate success rate
        $successRate = ($result['inserted_nodes'] / max($result['total_nodes'], 1)) * 100;
        writeLog("");
        writeLog("Import Summary:");
        writeLog("==============");
        writeLog("  Success rate: " . number_format($successRate, 1) . "%");
        writeLog("  Import completed at: " . date('Y-m-d H:i:s'));
        
    } catch (Exception $e) {
        writeLog("Error: " . $e->getMessage());
        if ($tempDir) {
            cleanupTempFiles($tempDir);
        }
        exit(1);
    } finally {
        // Clean up temporary files
        if ($tempDir) {
            writeLog("Cleaning up temporary files...");
            cleanupTempFiles($tempDir);
        }
    }
}

// Run the script
main($argc, $argv);