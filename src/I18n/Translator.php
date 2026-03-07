<?php

namespace BinktermPHP\I18n;

use BinktermPHP\Config;

class Translator
{
    private string $basePath;
    private string $defaultLocale;
    private bool $logMissingKeys;
    private ?string $missingKeysLogFile;
    /** @var array<string, bool> */
    private array $supportedLocales = [];
    /** @var array<string, array<string, array<string, string>>> */
    private array $catalogCache = [];
    /** @var array<string, bool> */
    private array $missingKeyLogCache = [];

    public function __construct(?string $basePath = null, ?string $defaultLocale = null, ?array $supportedLocales = null)
    {
        $this->basePath = $basePath ?? (__DIR__ . '/../../config/i18n');
        $this->defaultLocale = $this->normalizeLocale($defaultLocale ?? (string)Config::env('I18N_DEFAULT_LOCALE', 'en'));
        $this->logMissingKeys = $this->parseBooleanEnv((string)Config::env('I18N_LOG_MISSING_KEYS', 'false'));
        $logFile = trim((string)Config::env('I18N_MISSING_KEYS_LOG_FILE', ''));
        $this->missingKeysLogFile = ($logFile !== '') ? $logFile : null;

        if (is_array($supportedLocales) && !empty($supportedLocales)) {
            foreach ($supportedLocales as $locale) {
                $normalized = $this->normalizeLocale((string)$locale);
                if ($normalized !== '') {
                    $this->supportedLocales[$normalized] = true;
                }
            }
        } else {
            $configured = (string)Config::env('I18N_SUPPORTED_LOCALES', '');
            if ($configured !== '') {
                $parts = preg_split('/\s*,\s*/', $configured) ?: [];
                foreach ($parts as $part) {
                    $normalized = $this->normalizeLocale($part);
                    if ($normalized !== '') {
                        $this->supportedLocales[$normalized] = true;
                    }
                }
            }

            // Auto-discover locales from filesystem when env config is absent.
            // Only directories whose names look like locale codes (e.g. "en", "es", "zh-CN")
            // are considered; utility directories like "overrides" are ignored.
            if (empty($this->supportedLocales) && is_dir($this->basePath)) {
                $dirs = glob($this->basePath . '/*', GLOB_ONLYDIR) ?: [];
                foreach ($dirs as $dir) {
                    $name = basename($dir);
                    if (!preg_match('/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/', $name)) {
                        continue;
                    }
                    $normalized = $this->normalizeLocale($name);
                    if ($normalized !== '') {
                        $this->supportedLocales[$normalized] = true;
                    }
                }
            }
        }

        // Always include default locale even if not explicitly listed.
        $this->supportedLocales[$this->defaultLocale] = true;
    }

    public function getDefaultLocale(): string
    {
        return $this->defaultLocale;
    }

    public function isSupportedLocale(string $locale): bool
    {
        $normalized = $this->normalizeLocale($locale);
        return isset($this->supportedLocales[$normalized]);
    }

    /**
     * @return string[]
     */
    public function getSupportedLocales(): array
    {
        $locales = array_keys($this->supportedLocales);
        sort($locales, SORT_STRING);
        return $locales;
    }

    public function translate(string $key, array $params = [], ?string $locale = null, array $namespaces = ['common']): string
    {
        $resolvedLocale = $this->resolveToSupportedLocale($locale);
        $namespaces = $this->normalizeNamespaces($namespaces);

        $value = $this->lookupInNamespaces($key, $resolvedLocale, $namespaces);
        if ($value === null && $resolvedLocale !== $this->defaultLocale) {
            $value = $this->lookupInNamespaces($key, $this->defaultLocale, $namespaces);
        }
        if ($value === null) {
            $this->logMissingKey($key, $resolvedLocale, $namespaces);
            $value = $key;
        }

        return $this->interpolate($value, $params);
    }

    /**
     * Returns a merged catalog for a locale/namespace with fallback from default locale.
     *
     * @return array<string, string>
     */
    /**
     * Returns the path to the base PHP catalog file for a locale/namespace.
     */
    public function getPhpCatalogPath(string $locale, string $namespace): string
    {
        return $this->basePath . '/' . $locale . '/' . $namespace . '.php';
    }

    /**
     * Returns the path to the JSON overlay file for a locale/namespace.
     * The overlay file does not need to exist; the path is for reading or writing.
     */
    public function getOverlayPath(string $locale, string $namespace): string
    {
        return $this->basePath . '/overrides/' . $locale . '/' . $namespace . '.json';
    }

    /**
     * Returns catalog namespaces available for a locale (based on .php files present).
     *
     * @return string[]
     */
    public function getAvailableNamespaces(string $locale): array
    {
        $locale = $this->resolveToSupportedLocale($locale);
        $dir = $this->basePath . '/' . $locale;
        $namespaces = [];
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.php') ?: [] as $file) {
                $namespaces[] = basename($file, '.php');
            }
        }
        sort($namespaces);
        return $namespaces;
    }

    public function getCatalog(string $locale, string $namespace = 'common'): array
    {
        $resolvedLocale = $this->resolveToSupportedLocale($locale);
        $namespace = trim($namespace);
        if ($namespace === '') {
            $namespace = 'common';
        }

        $defaultCatalog = $this->loadCatalog($this->defaultLocale, $namespace);
        $localeCatalog = $resolvedLocale === $this->defaultLocale
            ? []
            : $this->loadCatalog($resolvedLocale, $namespace);

        /** @var array<string, string> $merged */
        $merged = array_merge($defaultCatalog, $localeCatalog);
        return $merged;
    }

    private function resolveToSupportedLocale(?string $locale): string
    {
        $normalized = $this->normalizeLocale((string)$locale);
        if ($normalized !== '' && isset($this->supportedLocales[$normalized])) {
            return $normalized;
        }

        $base = $this->getBaseLanguage($normalized);
        if ($base !== '' && isset($this->supportedLocales[$base])) {
            return $base;
        }

        return $this->defaultLocale;
    }

    /**
     * @param string[] $namespaces
     * @return string[]
     */
    private function normalizeNamespaces(array $namespaces): array
    {
        $normalized = [];
        foreach ($namespaces as $namespace) {
            $ns = trim((string)$namespace);
            if ($ns !== '') {
                $normalized[] = $ns;
            }
        }

        if (empty($normalized)) {
            $normalized[] = 'common';
        }

        return array_values(array_unique($normalized));
    }

    private function lookupInNamespaces(string $key, string $locale, array $namespaces): ?string
    {
        foreach ($namespaces as $namespace) {
            $catalog = $this->loadCatalog($locale, $namespace);
            if (isset($catalog[$key])) {
                return (string)$catalog[$key];
            }
        }
        return null;
    }

    /**
     * @return array<string, string>
     */
    private function loadCatalog(string $locale, string $namespace): array
    {
        if (isset($this->catalogCache[$locale][$namespace])) {
            return $this->catalogCache[$locale][$namespace];
        }

        $path = $this->basePath . '/' . $locale . '/' . $namespace . '.php';
        if (!is_file($path)) {
            $this->catalogCache[$locale][$namespace] = [];
            return [];
        }

        $data = include $path;
        if (!is_array($data)) {
            $this->catalogCache[$locale][$namespace] = [];
            return [];
        }

        $catalog = [];
        foreach ($data as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $catalog[$k] = $v;
            }
        }

        // Apply JSON overlay overrides (sysop-customized phrases)
        $overlayPath = $this->getOverlayPath($locale, $namespace);
        if (is_file($overlayPath)) {
            $overlayRaw  = file_get_contents($overlayPath);
            $overlayData = ($overlayRaw !== false) ? json_decode($overlayRaw, true) : null;
            if (is_array($overlayData)) {
                foreach ($overlayData as $k => $v) {
                    if (is_string($k) && is_string($v)) {
                        $catalog[$k] = $v;
                    }
                }
            }
        }

        $this->catalogCache[$locale][$namespace] = $catalog;
        return $catalog;
    }

    private function interpolate(string $value, array $params): string
    {
        if (empty($params)) {
            return $value;
        }

        foreach ($params as $key => $paramValue) {
            $value = str_replace('{' . $key . '}', (string)$paramValue, $value);
        }

        return $value;
    }

    private function normalizeLocale(string $locale): string
    {
        $clean = str_replace('_', '-', trim($locale));
        if ($clean === '') {
            return '';
        }

        $parts = explode('-', $clean);
        $parts[0] = strtolower($parts[0]);
        if (isset($parts[1])) {
            $parts[1] = strtoupper($parts[1]);
        }

        return implode('-', $parts);
    }

    private function getBaseLanguage(string $locale): string
    {
        if ($locale === '') {
            return '';
        }
        $parts = explode('-', $locale);
        return strtolower($parts[0] ?? '');
    }

    /**
     * Logs missing translation keys once per request.
     *
     * @param string[] $namespaces
     */
    private function logMissingKey(string $key, string $resolvedLocale, array $namespaces): void
    {
        if (!$this->logMissingKeys) {
            return;
        }

        $signature = $resolvedLocale . '|' . implode(',', $namespaces) . '|' . $key;
        if (isset($this->missingKeyLogCache[$signature])) {
            return;
        }
        $this->missingKeyLogCache[$signature] = true;

        $message = sprintf(
            '[i18n] missing_key key="%s" locale="%s" default_locale="%s" namespaces="%s"',
            $key,
            $resolvedLocale,
            $this->defaultLocale,
            implode(',', $namespaces)
        );

        if ($this->missingKeysLogFile !== null) {
            error_log($message . PHP_EOL, 3, $this->missingKeysLogFile);
            return;
        }

        error_log($message);
    }

    private function parseBooleanEnv(string $value): bool
    {
        $normalized = strtolower(trim($value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
