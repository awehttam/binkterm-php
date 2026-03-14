<?php

namespace BinktermPHP\I18n;

class LocaleResolver
{
    public const COOKIE_NAME = 'binktermphp_locale';

    private Translator $translator;

    public function __construct(Translator $translator)
    {
        $this->translator = $translator;
    }

    /**
     * Resolve locale using priority:
     * 1) Explicit locale argument
     * 2) Authenticated user settings locale (if provided in $user['locale'])
     * 3) Locale cookie
     * 4) Accept-Language header best match
     * 5) Default locale
     */
    public function resolveLocale(?string $requestedLocale = null, ?array $user = null): string
    {
        $candidates = [];

        if (is_string($requestedLocale) && trim($requestedLocale) !== '') {
            $candidates[] = $requestedLocale;
        }

        if (is_array($user) && isset($user['locale'])) {
            $candidates[] = (string)$user['locale'];
        }

        if (isset($_COOKIE[self::COOKIE_NAME])) {
            $candidates[] = (string)$_COOKIE[self::COOKIE_NAME];
        }

        $acceptLanguage = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        if ($acceptLanguage !== '') {
            $candidates = array_merge($candidates, $this->parseAcceptLanguage($acceptLanguage));
        }

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeLocale($candidate);
            if ($normalized !== '' && $this->translator->isSupportedLocale($normalized)) {
                return $normalized;
            }

            $base = $this->baseLanguage($normalized);
            if ($base !== '' && $this->translator->isSupportedLocale($base)) {
                return $base;
            }
        }

        return $this->translator->getDefaultLocale();
    }

    public function persistLocale(string $locale): void
    {
        $normalized = $this->normalizeLocale($locale);
        if ($normalized === '') {
            return;
        }

        $resolved = $this->resolveLocale($normalized);

        if (headers_sent()) {
            return;
        }

        setcookie(self::COOKIE_NAME, $resolved, [
            'expires' => time() + (86400 * 365),
            'path' => '/',
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * @return string[]
     */
    private function parseAcceptLanguage(string $header): array
    {
        $parts = explode(',', $header);
        $weighted = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $segments = explode(';', $part);
            $locale = trim($segments[0]);
            if ($locale === '' || $locale === '*') {
                continue;
            }

            $q = 1.0;
            if (isset($segments[1]) && preg_match('/q=([0-9.]+)/', $segments[1], $m)) {
                $q = (float)$m[1];
            }

            $weighted[] = ['locale' => $locale, 'q' => $q];
        }

        usort($weighted, static function (array $a, array $b): int {
            if ($a['q'] === $b['q']) {
                return 0;
            }
            return ($a['q'] > $b['q']) ? -1 : 1;
        });

        $locales = [];
        foreach ($weighted as $item) {
            $locales[] = (string)$item['locale'];
        }

        return $locales;
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

    private function baseLanguage(string $locale): string
    {
        if ($locale === '') {
            return '';
        }
        $parts = explode('-', $locale);
        return strtolower($parts[0] ?? '');
    }
}

