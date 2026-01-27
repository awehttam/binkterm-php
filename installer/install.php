#!/usr/bin/env php
<?php
/**
 * BinktermPHP Installer
 *
 * Downloads and configures BinktermPHP from GitHub releases.
 *
 * Usage: php install.php [options]
 *   --version=X.X.X   Install specific version (default: latest)
 *   --dir=/path       Installation directory (default: current)
 *   --help            Show this help
 */

require_once(__DIR__."/../src/ansicliconsole.php");

class Installer
{
    const GITHUB_REPO = 'awehttam/binkterm-php';
    const GITHUB_API = 'https://api.github.com/repos/';

    private $version = 'latest';
    private $installDir = '.';
    private $ansi;

    function __construct()
    {
        $this->ansi = new AnsiCliConsole();;
    }
    /**
     * Parse command line arguments
     */
    public function parseArgs(array $argv): void
    {
        foreach ($argv as $arg) {
            if (strpos($arg, '--version=') === 0) {
                $this->version = substr($arg, 10);
            } elseif (strpos($arg, '--dir=') === 0) {
                $this->installDir = substr($arg, 6);
            } elseif ($arg === '--no-color') {
                $this->useColors = false;
            } elseif ($arg === '--help' || $arg === '-h') {
                $this->showHelp();
                exit(0);
            }
        }
    }

    /**
     * Show help
     */
    private function showHelp(): void
    {
        $this->ansi->banner();
        $this->ansi->line($this->ansi->color('  Usage:', Ansi::BOLD) . ' php install.php [options]');
        $this->ansi->line();
        $this->ansi->line($this->ansi->color('  Options:', Ansi::BOLD));
        $this->ansi->line('    --version=X.X.X   Install specific version (default: latest)');
        $this->ansi->line('    --dir=/path       Installation directory (default: current)');
        $this->ansi->line('    --no-color        Disable colored output');
        $this->ansi->line('    --help, -h        Show this help');
        $this->ansi->line();
    }

    /**
     * Fetch latest release info from GitHub
     */
    private function fetchReleaseInfo(): array
    {
        $url = self::GITHUB_API . self::GITHUB_REPO . '/releases';

        if ($this->version === 'latest') {
            $url .= '/latest';
        } else {
            $url .= '/tags/v' . ltrim($this->version, 'v');
        }

        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: BinktermPHP-Installer\r\n",
                'timeout' => 30
            ]
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new \Exception("Failed to fetch release info from GitHub");
        }

        return json_decode($response, true);
    }

    /**
     * Download file with progress
     */
    private function downloadFile(string $url, string $destination): void
    {
        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: BinktermPHP-Installer\r\n",
                'timeout' => 300
            ]
        ]);

        // Get file size first
        $headers = get_headers($url, true);
        $size = isset($headers['Content-Length']) ? (int)$headers['Content-Length'] : 0;

        $source = fopen($url, 'rb', false, $context);
        $dest = fopen($destination, 'wb');

        if (!$source || !$dest) {
            throw new \Exception("Failed to open file for download");
        }

        $downloaded = 0;
        while (!feof($source)) {
            $chunk = fread($source, 8192);
            fwrite($dest, $chunk);
            $downloaded += strlen($chunk);

            if ($size > 0) {
                $this->ansi->progressBar($downloaded, $size);
            }
        }

        fclose($source);
        fclose($dest);
    }

    function setupCron($installPath)
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->ansi->error("Windows does not support a cron facility, configure tasks manually.");
            return;
        }
        $check = trim(`crontab -l |grep binkp_poll`);

        $contents=trim(file_get_contents(__DIR__."/../cron.example"));
        if(!$contents){
            echo "Could not find a cron sample to work with\n";
        }

        if(strrpos($installPath,-1)!='/'){
            $installPath.='/';
        }
        $contents = str_replace("/home/binkweb/binkterm-php/", $installPath, $contents);

        $temppath = tempnam(sys_get_temp_dir(), "binkweb");
        file_put_contents($temppath, $contents);

        system("crontab ".escapeshellarg($temppath));
        $this->ansi->success("Cron configured");
        return;
    }
    /**
     * Run the installer
     */
    public function run(): int
    {
        $this->ansi->banner();

        $this->ansi->section('System Requirements');

        // Check PHP version
        $phpVersion = PHP_VERSION;
        if (version_compare($phpVersion, '8.0.0', '>=')) {
            $this->ansi->success("PHP $phpVersion");
        } else {
            $this->ansi->error("PHP 8.0+ required (found $phpVersion)");
            return 1;
        }

        // Check required extensions
        $requiredExtensions = ['pdo', 'pdo_pgsql', 'json', 'curl', 'mbstring', 'zip'];
        $errors=0;
        foreach ($requiredExtensions as $ext) {
            if (extension_loaded($ext)) {
                $this->ansi->success("Extension: $ext");
            } else {
                $this->ansi->error("Missing extension: $ext");
                $errors++;
            }
        }
        if($errors)
            return 1;

        $this->ansi->section('Installation Options');

        // Get installation directory
        $this->installDir = $this->ansi->prompt('Installation directory', $this->installDir);

        // Get version
        $this->version = $this->ansi->prompt('Version to install', $this->version);

        $this->ansi->section('Downloading');

        try {
            $this->ansi->info("Fetching release information...");
            $release = $this->fetchReleaseInfo();
            $this->ansi->success("Found version: " . $release['tag_name']);

            // Find the zip asset
            $zipUrl = null;
            foreach ($release['assets'] ?? [] as $asset) {
                if (preg_match('/\.zip$/i', $asset['name'])) {
                    $zipUrl = $asset['browser_download_url'];
                    break;
                }
            }

            // Fall back to zipball_url if no asset found
            if (!$zipUrl && isset($release['zipball_url'])) {
                $zipUrl = $release['zipball_url'];
            }

            if (!$zipUrl) {
                throw new \Exception("No download URL found in release");
            }

            $this->ansi->info("Downloading from: $zipUrl");
            $tempFile = sys_get_temp_dir() . '/binkterm-' . uniqid() . '.zip';
            $this->downloadFile($zipUrl, $tempFile);
            $this->ansi->success("Download complete");

            // TODO: Extract and configure
            $this->ansi->info("Downloaded to: $tempFile");

        } catch (\Exception $e) {
            $this->ansi->error($e->getMessage());
            return 1;
        }


        $this->ansi->section('Configuration');

        // Database configuration
        $dbHost = $this->ansi->prompt('PostgreSQL host', 'localhost');
        $dbPort = $this->ansi->prompt('PostgreSQL port', '5432');
        $dbName = $this->ansi->prompt('Database name', 'binkterm');
        $dbUser = $this->ansi->prompt('Database user', 'binkterm');
        $dbPass = $this->ansi->prompt('Database password');

        // FidoNet configuration
        $this->ansi->section('FidoNet Configuration');
        $systemName = $this->ansi->prompt('System name', 'My BBS');
        $sysopName = $this->ansi->prompt('Sysop name');
        $ftnAddress = $this->ansi->prompt('FTN address (e.g., 1:234/567)');

        // Prompt whether to proceed with installation. If no, exit, otherwise proceed

        // Check for existence of install directory - make sure it's empty, exit if it isn't

        // Unzip the archive

        // Create a .env file using .env.example as the template

        // Create a config/binkp.json using config/binkp.json.example as the template

        // Provide user with example crontab for manual installation (TODO: create a crontab.example file)

        // Install done!
        $this->ansi->section('Complete');
        $this->ansi->success("BinktermPHP has been installed!");
        $this->ansi->line();
        $this->ansi->info("Next steps:");
        $this->ansi->line("    1. Run database migrations");
        $this->ansi->line("    2. Configure your web server");
        $this->ansi->line("    3. Set up your uplinks in binkp.json");
        $this->ansi->line();

        return 0;
    }
}

// Run installer
$installer = new Installer();
$installer->parseArgs($argv);
exit($installer->run());
