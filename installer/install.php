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

class Installer
{
    // ANSI color codes
    const RESET = "\033[0m";
    const BOLD = "\033[1m";
    const DIM = "\033[2m";

    // Foreground colors
    const BLACK = "\033[30m";
    const RED = "\033[31m";
    const GREEN = "\033[32m";
    const YELLOW = "\033[33m";
    const BLUE = "\033[34m";
    const MAGENTA = "\033[35m";
    const CYAN = "\033[36m";
    const WHITE = "\033[37m";

    // Bright foreground colors
    const BRIGHT_RED = "\033[91m";
    const BRIGHT_GREEN = "\033[92m";
    const BRIGHT_YELLOW = "\033[93m";
    const BRIGHT_BLUE = "\033[94m";
    const BRIGHT_MAGENTA = "\033[95m";
    const BRIGHT_CYAN = "\033[96m";
    const BRIGHT_WHITE = "\033[97m";

    // Background colors
    const BG_BLUE = "\033[44m";
    const BG_GREEN = "\033[42m";
    const BG_RED = "\033[41m";

    const GITHUB_REPO = 'awehttam/binkterm-php';
    const GITHUB_API = 'https://api.github.com/repos/';

    private $version = 'latest';
    private $installDir = '.';
    private $useColors = true;

    public function __construct()
    {
        // Detect if colors are supported
        $this->useColors = $this->supportsColors();
    }

    /**
     * Check if terminal supports colors
     */
    private function supportsColors(): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows: check for ConEmu, ANSICON, or Windows 10+
            return getenv('ANSICON') !== false
                || getenv('ConEmuANSI') === 'ON'
                || getenv('WT_SESSION') !== false  // Windows Terminal
                || (function_exists('sapi_windows_vt100_support') && sapi_windows_vt100_support(STDOUT));
        }
        return posix_isatty(STDOUT);
    }

    /**
     * Colorize text
     */
    private function color(string $text, string ...$codes): string
    {
        if (!$this->useColors) {
            return $text;
        }
        return implode('', $codes) . $text . self::RESET;
    }

    /**
     * Print a line
     */
    private function line(string $text = ''): void
    {
        echo $text . PHP_EOL;
    }

    /**
     * Print info message
     */
    private function info(string $text): void
    {
        $this->line($this->color('  ℹ ', self::CYAN) . $text);
    }

    /**
     * Print success message
     */
    private function success(string $text): void
    {
        $this->line($this->color('  ✓ ', self::BRIGHT_GREEN) . $text);
    }

    /**
     * Print warning message
     */
    private function warn(string $text): void
    {
        $this->line($this->color('  ⚠ ', self::BRIGHT_YELLOW) . $text);
    }

    /**
     * Print error message
     */
    private function error(string $text): void
    {
        $this->line($this->color('  ✗ ', self::BRIGHT_RED) . $text);
    }

    /**
     * Print a section header
     */
    private function section(string $title): void
    {
        $this->line();
        $this->line($this->color("  ▸ $title", self::BOLD, self::BRIGHT_BLUE));
        $this->line($this->color("  " . str_repeat('─', strlen($title) + 2), self::DIM));
    }

    /**
     * Print the banner
     */
    private function banner(): void
    {
        $banner = <<<'BANNER'

    ╔══════════════════════════════════════════════════════════════╗
    ║                                                              ║
    ║   ██████╗ ██╗███╗   ██╗██╗  ██╗████████╗███████╗██████╗ ███╗ ║
    ║   ██╔══██╗██║████╗  ██║██║ ██╔╝╚══██╔══╝██╔════╝██╔══██╗████╗║
    ║   ██████╔╝██║██╔██╗ ██║█████╔╝    ██║   █████╗  ██████╔╝██╔██║
    ║   ██╔══██╗██║██║╚██╗██║██╔═██╗    ██║   ██╔══╝  ██╔══██╗██║╚█║
    ║   ██████╔╝██║██║ ╚████║██║  ██╗   ██║   ███████╗██║  ██║██║ ╚║
    ║   ╚═════╝ ╚═╝╚═╝  ╚═══╝╚═╝  ╚═╝   ╚═╝   ╚══════╝╚═╝  ╚═╝╚═╝  ║
    ║                                                              ║
    ║              FidoNet Web Interface & Mailer                  ║
    ║                                                              ║
    ╚══════════════════════════════════════════════════════════════╝

BANNER;

        $this->line($this->color($banner, self::BRIGHT_CYAN));
    }

    /**
     * Prompt for user input
     */
    private function prompt(string $question, string $default = ''): string
    {
        $defaultHint = $default ? $this->color(" [$default]", self::DIM) : '';
        echo $this->color('  ? ', self::BRIGHT_MAGENTA) . $question . $defaultHint . ': ';

        $handle = fopen('php://stdin', 'r');
        $input = trim(fgets($handle));
        fclose($handle);

        return $input ?: $default;
    }

    /**
     * Prompt for yes/no
     */
    private function confirm(string $question, bool $default = true): bool
    {
        $hint = $default ? '[Y/n]' : '[y/N]';
        $input = strtolower($this->prompt("$question $hint"));

        if ($input === '') {
            return $default;
        }

        return in_array($input, ['y', 'yes', '1', 'true']);
    }

    /**
     * Show a spinner while executing callback
     */
    private function spinner(string $message, callable $callback)
    {
        $frames = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
        $i = 0;

        // Simple non-animated version for now
        echo $this->color("  ◌ ", self::CYAN) . $message . '... ';

        try {
            $result = $callback();
            echo $this->color("done", self::BRIGHT_GREEN) . PHP_EOL;
            return $result;
        } catch (\Exception $e) {
            echo $this->color("failed", self::BRIGHT_RED) . PHP_EOL;
            throw $e;
        }
    }

    /**
     * Display a progress bar
     */
    private function progressBar(int $current, int $total, int $width = 40): void
    {
        $percent = $total > 0 ? ($current / $total) : 0;
        $filled = (int)($percent * $width);
        $empty = $width - $filled;

        $bar = $this->color(str_repeat('█', $filled), self::BRIGHT_GREEN)
             . $this->color(str_repeat('░', $empty), self::DIM);

        $percentText = sprintf('%3d%%', $percent * 100);

        echo "\r  $bar " . $this->color($percentText, self::BRIGHT_CYAN);

        if ($current >= $total) {
            echo PHP_EOL;
        }
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
        $this->banner();
        $this->line($this->color('  Usage:', self::BOLD) . ' php install.php [options]');
        $this->line();
        $this->line($this->color('  Options:', self::BOLD));
        $this->line('    --version=X.X.X   Install specific version (default: latest)');
        $this->line('    --dir=/path       Installation directory (default: current)');
        $this->line('    --no-color        Disable colored output');
        $this->line('    --help, -h        Show this help');
        $this->line();
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
                $this->progressBar($downloaded, $size);
            }
        }

        fclose($source);
        fclose($dest);
    }

    /**
     * Run the installer
     */
    public function run(): int
    {
        $this->banner();

        $this->section('System Requirements');

        // Check PHP version
        $phpVersion = PHP_VERSION;
        if (version_compare($phpVersion, '8.0.0', '>=')) {
            $this->success("PHP $phpVersion");
        } else {
            $this->error("PHP 8.0+ required (found $phpVersion)");
            return 1;
        }

        // Check required extensions
        $requiredExtensions = ['pdo', 'pdo_pgsql', 'json', 'curl', 'mbstring', 'zip'];
        $errors=0;
        foreach ($requiredExtensions as $ext) {
            if (extension_loaded($ext)) {
                $this->success("Extension: $ext");
            } else {
                $this->error("Missing extension: $ext");
                $errors++;
            }
        }
        if($errors)
            return 1;

        $this->section('Installation Options');

        // Get installation directory
        $this->installDir = $this->prompt('Installation directory', $this->installDir);

        // Get version
        $this->version = $this->prompt('Version to install', $this->version);

        $this->section('Downloading');

        try {
            $this->info("Fetching release information...");
            $release = $this->fetchReleaseInfo();
            $this->success("Found version: " . $release['tag_name']);

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

            $this->info("Downloading from: $zipUrl");
            $tempFile = sys_get_temp_dir() . '/binkterm-' . uniqid() . '.zip';
            $this->downloadFile($zipUrl, $tempFile);
            $this->success("Download complete");

            // TODO: Extract and configure
            $this->info("Downloaded to: $tempFile");

        } catch (\Exception $e) {
            $this->error($e->getMessage());
            return 1;
        }


        $this->section('Configuration');

        // Database configuration
        $dbHost = $this->prompt('PostgreSQL host', 'localhost');
        $dbPort = $this->prompt('PostgreSQL port', '5432');
        $dbName = $this->prompt('Database name', 'binkterm');
        $dbUser = $this->prompt('Database user', 'binkterm');
        $dbPass = $this->prompt('Database password');

        // FidoNet configuration
        $this->section('FidoNet Configuration');
        $systemName = $this->prompt('System name', 'My BBS');
        $sysopName = $this->prompt('Sysop name');
        $ftnAddress = $this->prompt('FTN address (e.g., 1:234/567)');

        // Prompt whether to proceed with installation. If no, exit, otherwise proceed

        // Check for existence of install directory - make sure it's empty, exit if it isn't

        // Unzip the archive

        // Create a .env file using .env.example as the template

        // Create a config/binkp.json using config/binkp.json.example as the template

        // Provide user with example crontab for manual installation (TODO: create a crontab.example file)

        // Install done!
        $this->section('Complete');
        $this->success("BinktermPHP has been installed!");
        $this->line();
        $this->info("Next steps:");
        $this->line("    1. Run database migrations");
        $this->line("    2. Configure your web server");
        $this->line("    3. Set up your uplinks in binkp.json");
        $this->line();

        return 0;
    }
}

// Run installer
$installer = new Installer();
$installer->parseArgs($argv);
exit($installer->run());
