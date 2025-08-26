<?php
/**
 * Command-line nodelist import script for binkterm-php
 * Usage: php import_nodelist.php <nodelist_file> [--force]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BinktermPHP\Database;
use BinktermPHP\Nodelist\NodelistManager;

function printUsage() {
    echo "Usage: php import_nodelist.php <nodelist_file> [--force]\n";
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
    echo "  php import_nodelist.php NODELIST.001\n";
    echo "  php import_nodelist.php NODELIST.Z150 --force\n";
    echo "  php import_nodelist.php NODELIST.A365\n";
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
    $force = in_array('--force', $argv);
    
    if (!file_exists($nodelistFile)) {
        echo "Error: Nodelist file not found: {$nodelistFile}\n";
        exit(1);
    }
    
    if (!is_readable($nodelistFile)) {
        echo "Error: Cannot read nodelist file: {$nodelistFile}\n";
        exit(1);
    }
    
    echo "BinkTerm-PHP Nodelist Importer\n";
    echo "==============================\n";
    echo "File: {$nodelistFile}\n";
    echo "Size: " . number_format(filesize($nodelistFile)) . " bytes\n";
    
    // Detect archive type and extract if needed
    $archiveType = detectArchiveType($nodelistFile);
    $actualNodelistFile = $nodelistFile;
    $tempDir = null;
    
    if ($archiveType !== 'PLAIN') {
        echo "Format: {$archiveType} archive\n";
        echo "Extracting archive...\n";
        
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nodelist_' . uniqid();
        mkdir($tempDir);
        
        try {
            $actualNodelistFile = extractArchive($nodelistFile, $archiveType, $tempDir);
            echo "Extracted to: " . basename($actualNodelistFile) . "\n";
            echo "Extracted size: " . number_format(filesize($actualNodelistFile)) . " bytes\n";
        } catch (Exception $e) {
            cleanupTempFiles($tempDir);
            echo "Error: " . $e->getMessage() . "\n";
            exit(1);
        }
    } else {
        echo "Format: Plain text\n";
    }
    echo "\n";
    
    try {
        $nodelistManager = new NodelistManager();
        
        // Check for existing nodelist
        $activeNodelist = $nodelistManager->getActiveNodelist();
        if ($activeNodelist && !$force) {
            echo "Warning: An active nodelist already exists:\n";
            echo "  File: {$activeNodelist['filename']}\n";
            echo "  Date: {$activeNodelist['release_date']}\n";
            echo "  Nodes: " . number_format($activeNodelist['total_nodes']) . "\n\n";
            echo "This will archive the current nodelist and import the new one.\n";
            echo "Continue? (y/N): ";
            
            $response = trim(fgets(STDIN));
            if (strtolower($response) !== 'y' && strtolower($response) !== 'yes') {
                echo "Import cancelled.\n";
                exit(0);
            }
            echo "\n";
        }
        
        echo "Starting import...\n";
        $startTime = microtime(true);
        
        $result = $nodelistManager->importNodelist($actualNodelistFile, true);
        
        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);
        
        echo "Import completed successfully!\n\n";
        echo "Results:\n";
        echo "  Filename: {$result['filename']}\n";
        echo "  Total nodes: " . number_format($result['total_nodes']) . "\n";
        echo "  Inserted nodes: " . number_format($result['inserted_nodes']) . "\n";
        echo "  Duration: {$duration} seconds\n\n";
        
        // Show statistics
        $stats = $nodelistManager->getNodelistStats();
        echo "Current nodelist statistics:\n";
        echo "  Total nodes: " . number_format($stats['total_nodes']) . "\n";
        echo "  Zones: " . $stats['total_zones'] . "\n";
        echo "  Nets: " . $stats['total_nets'] . "\n";
        echo "  Points: " . number_format($stats['point_nodes']) . "\n";
        echo "  Special nodes: " . number_format($stats['special_nodes']) . "\n";
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        if ($tempDir) {
            cleanupTempFiles($tempDir);
        }
        exit(1);
    } finally {
        // Clean up temporary files
        if ($tempDir) {
            cleanupTempFiles($tempDir);
        }
    }
}

// Run the script
main($argc, $argv);