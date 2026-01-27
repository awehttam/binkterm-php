<?php

class AnsiCliConsole
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
    public function color(string $text, string ...$codes): string
    {
        if (!$this->useColors) {
            return $text;
        }
        return implode('', $codes) . $text . self::RESET;
    }

    /**
     * Print a line
     */
    public function line(string $text = ''): void
    {
        echo $text . PHP_EOL;
    }

    /**
     * Print info message
     */
    public function info(string $text): void
    {
        $this->line($this->color('  ℹ ', self::CYAN) . $text);
    }

    /**
     * Print success message
     */
    public function success(string $text): void
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
    public function error(string $text): void
    {
        $this->line($this->color('  ✗ ', self::BRIGHT_RED) . $text);
    }

    /**
     * Print a section header
     */
    public function section(string $title): void
    {
        $this->line();
        $this->line($this->color("  ▸ $title", self::BOLD, self::BRIGHT_BLUE));
        $this->line($this->color("  " . str_repeat('─', strlen($title) + 2), self::DIM));
    }

    /**
     * Print the banner
     */
    public function banner(): void
    {
        $banner = <<<'BANNER'

╔═════════════════════════════════════════════════════════════════════╗
║                                                                     ║
║   ██████╗ ██╗███╗   ██╗██╗  ██╗████████╗███████╗██████╗ ███╗   ███╗ ║
║   ██╔══██╗██║████╗  ██║██║ ██╔╝╚══██╔══╝██╔════╝██╔══██╗████╗ ████║ ║
║   ██████╔╝██║██╔██╗ ██║█████╔╝    ██║   █████╗  ██████╔╝██╔████╔██║ ║
║   ██╔══██╗██║██║╚██╗██║██╔═██╗    ██║   ██╔══╝  ██╔══██╗██║╚██╔╝██║ ║
║   ██████╔╝██║██║ ╚████║██║  ██╗   ██║   ███████╗██║  ██║██║ ╚═╝ ██║ ║
║   ╚═════╝ ╚═╝╚═╝  ╚═══╝╚═╝  ╚═╝   ╚═╝   ╚══════╝╚═╝  ╚═╝╚═╝     ╚═╝ ║
║                                                                     ║
║                     FTN Mailer and BBS Platform                     ║
║              https://github.com/awehttam/binkterm-php               ║
╚═════════════════════════════════════════════════════════════════════╝

BANNER;

        $this->line($this->color($banner, self::BRIGHT_CYAN));
    }

    /**
     * Prompt for user input
     */
    public function prompt(string $question, string $default = ''): string
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
    public function progressBar(int $current, int $total, int $width = 40): void
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

}// ANSI color codes
