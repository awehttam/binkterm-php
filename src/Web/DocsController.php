<?php

namespace BinktermPHP\Web;

use BinktermPHP\Auth;
use BinktermPHP\I18n\LocaleResolver;
use BinktermPHP\I18n\Translator;
use BinktermPHP\MarkdownRenderer;
use BinktermPHP\Template;

/**
 * Serves the in-app documentation browser.
 *
 * Renders Markdown files from the docs/ directory (excluding proposals/).
 * Relative .md links are rewritten to /admin/docs/view/{name}.
 * Only https:// external links and intra-docs links are permitted.
 */
class DocsController
{
    private string $docsDir;
    private string $repoRoot;
    private string $locale;

    public function __construct()
    {
        $this->docsDir  = realpath(__DIR__ . '/../../docs');
        $this->repoRoot = realpath(__DIR__ . '/../..');
        $this->locale   = $this->resolveCurrentLocale();
    }

    /**
     * Resolve the active locale for the current request.
     */
    private function resolveCurrentLocale(): string
    {
        try {
            $user       = (new Auth())->getCurrentUser();
            $translator = new Translator();
            $resolver   = new LocaleResolver($translator);
            return $resolver->resolveLocale(null, is_array($user) ? $user : null);
        } catch (\Throwable) {
            return 'en';
        }
    }

    /**
     * Resolve a localized Markdown file path from a base path (no extension).
     *
     * Resolution order:
     *   1. {basePath}.{locale}.md  — locale-specific file
     *   2. {basePath}.md           — generic (no locale suffix)
     *   3. {basePath}.en.md        — explicit English fallback
     *
     * Returns the path of the first existing file, or null if none found.
     */
    public static function resolveLocalizedPath(string $basePath, string $locale): ?string
    {
        $candidates = [];
        if ($locale !== '' && $locale !== 'en') {
            $candidates[] = $basePath . '.' . $locale . '.md';
        }
        $candidates[] = $basePath . '.md';
        $candidates[] = $basePath . '.en.md';

        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Render the documentation index (docs/index.md).
     */
    public function index(): void
    {
        $this->renderDoc('index');
    }

    /**
     * Render a single documentation page by name (without .md extension).
     *
     * @param string $name Filename without extension, e.g. "CONFIGURATION"
     */
    public function view(string $name): void
    {
        $this->renderDoc($name);
    }

    /**
     * Resolve, validate, render, and output a docs markdown file.
     */
    private function renderDoc(string $name): void
    {
        // Sanitize: only alphanumerics, underscores, hyphens, dots allowed.
        // No slashes or path traversal.
        if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $name)) {
            $this->render404();
            return;
        }

        // Prevent accessing proposals/ (they live in docs/proposals/ but since
        // we only accept flat names this is mainly a double-check).
        if (stripos($name, 'proposals') !== false) {
            $this->render404();
            return;
        }

        $realPath = $this->resolveDocPath($name);
        if ($realPath === null) {
            $this->render404();
            return;
        }

        $raw  = file_get_contents($realPath);
        $raw  = self::rewriteLinks($raw);
        // HTML pass-through is enabled only for README.md, which is trusted
        // sysop-maintained content. All other docs are rendered with HTML escaped.
        $allowHtml = ($name === 'README');
        $html = MarkdownRenderer::toHtml($raw, allowHtml: $allowHtml, allowImages: true);

        $template = new Template();
        $template->renderResponse('admin/docs.twig', [
            'content'   => $html,
            'doc_name'  => $name,
            'is_index'  => $name === 'index',
        ]);
    }

    /**
     * Serve a static asset (images only) from the docs/ directory.
     *
     * Only image file types are permitted. Path traversal is blocked via realpath().
     *
     * @param string $path Relative path within docs/, e.g. "screenshots/echomail.png"
     */
    public function asset(string $path): void
    {
        // Restrict to safe characters; reject anything with ..
        if (!preg_match('/^[A-Za-z0-9_.\-\/]+$/', $path) || str_contains($path, '..')) {
            http_response_code(404);
            return;
        }

        $allowed = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!isset($allowed[$ext])) {
            http_response_code(404);
            return;
        }

        $filePath = $this->docsDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $realPath = realpath($filePath);
        if ($realPath === false || !str_starts_with($realPath, $this->docsDir . DIRECTORY_SEPARATOR)) {
            http_response_code(404);
            return;
        }

        header('Content-Type: ' . $allowed[$ext]);
        header('Cache-Control: max-age=3600');
        readfile($realPath);
    }

    /**
     * Render a plain-text documentation file (e.g. an FTN specification) from the docs/ directory.
     *
     * Accepts subdirectory paths with URL-encoded characters, e.g.
     * "LSC/LSC1%20-%20Markup%20Kludge" → docs/LSC/LSC1 - Markup Kludge.txt
     * The .txt extension is appended by this method.
     *
     * @param string $path URL-encoded relative path within docs/, without extension
     */
    public function viewTxt(string $path): void
    {
        $decoded = urldecode($path);

        // Allow letters, digits, spaces, hyphens, underscores, dots, and slashes only.
        if (!preg_match('/^[A-Za-z0-9 _.\-\/]+$/', $decoded) || str_contains($decoded, '..')) {
            $this->render404();
            return;
        }

        if (stripos($decoded, 'proposals') !== false) {
            $this->render404();
            return;
        }

        $filePath = $this->docsDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $decoded) . '.txt';
        $realPath = realpath($filePath);
        if ($realPath === false || !str_starts_with($realPath, $this->docsDir . DIRECTORY_SEPARATOR)) {
            $this->render404();
            return;
        }

        $proposalsDir = $this->docsDir . DIRECTORY_SEPARATOR . 'proposals' . DIRECTORY_SEPARATOR;
        if (str_starts_with($realPath, $proposalsDir)) {
            $this->render404();
            return;
        }

        $raw  = file_get_contents($realPath);
        $html = '<pre class="docs-plaintext">' . htmlspecialchars($raw, ENT_QUOTES, 'UTF-8') . '</pre>';

        $template = new Template();
        $template->renderResponse('admin/docs.twig', [
            'content'  => $html,
            'doc_name' => basename($decoded),
            'is_index' => false,
        ]);
    }

    /**
     * Rewrite relative .md and .txt links in the raw Markdown so they work inside the docs browser.
     *
     * [Label](Foo.md)                      → [Label](/admin/docs/view/Foo)
     * [Label](./Foo.md)                    → [Label](/admin/docs/view/Foo)
     * [Label](LSC/LSC1%20-%20Spec.txt)     → [Label](/admin/docs/txt/LSC/LSC1%20-%20Spec)
     *
     * External https:// links and anchor (#) links are left unchanged.
     */
    public static function rewriteLinks(string $markdown): string
    {
        // Rewrite relative .md links to /admin/docs/view/{name}
        $markdown = preg_replace_callback(
            '/\[([^\]]+)\]\((?!https:\/\/|#)(?:\.\/)?([A-Za-z0-9_.\-\/]+)\.md(#[^\)]*)?\)/',
            function (array $m): string {
                $label  = $m[1];
                $target = str_replace('\\', '/', $m[2]);
                $anchor = $m[3] ?? '';

                if (str_starts_with($target, '../')) {
                    // Allow known root-level docs (must match keys in $specialBases).
                    $basename = basename($target);
                    if (in_array($basename, ['FAQ', 'README', 'REGISTER', 'CONTRIBUTING', 'CREDITS'], true)) {
                        return '[' . $label . '](/admin/docs/view/' . $basename . $anchor . ')';
                    }
                    return $m[0];
                }

                if (str_starts_with($target, 'docs/')) {
                    $target = substr($target, 5);
                }

                if (str_contains($target, '/')) {
                    return $m[0];
                }

                return '[' . $label . '](/admin/docs/view/' . $target . $anchor . ')';
            },
            $markdown
        );

        // Rewrite relative .txt links (e.g. LSC specs in subdirectories) to /admin/docs/txt/{path}
        $markdown = preg_replace_callback(
            '/\[([^\]]+)\]\((?!https?:\/\/|#)((?:\.\/)?[^)]+?)\.txt(#[^\)]*)?\)/',
            function (array $m): string {
                $label  = $m[1];
                $target = ltrim(str_replace('\\', '/', $m[2]), './');
                $anchor = $m[3] ?? '';
                return '[' . $label . '](/admin/docs/txt/' . $target . $anchor . ')';
            },
            $markdown
        );

        // Rewrite relative image src attributes (e.g. src="docs/screenshots/foo.png")
        // to the docs asset route so browsers can fetch them.
        $markdown = preg_replace_callback(
            '/\bsrc="(docs\/[A-Za-z0-9_.\-\/]+\.(png|jpg|jpeg|gif|webp))"/',
            function (array $m): string {
                $assetPath = substr($m[1], strlen('docs/'));
                return 'src="/admin/docs/asset/' . $assetPath . '"';
            },
            $markdown
        );

        // Rewrite Markdown image syntax with relative paths (e.g. ![alt](images/foo.png))
        // to the docs asset route so the browser can fetch them.
        $markdown = preg_replace_callback(
            '/!\[([^\]]*)\]\((?!https?:\/\/)([A-Za-z0-9_.\-\/]+\.(png|jpg|jpeg|gif|webp))\)/',
            function (array $m): string {
                return '![' . $m[1] . '](/admin/docs/asset/' . $m[2] . ')';
            },
            $markdown
        );

        return $markdown;
    }

    /**
     * Resolve a documentation name to an allowed markdown file path,
     * preferring a locale-specific variant when one exists.
     */
    private function resolveDocPath(string $name): ?string
    {
        $specialBases = [
            'FAQ'      => $this->repoRoot . DIRECTORY_SEPARATOR . 'FAQ',
            'README'   => $this->repoRoot . DIRECTORY_SEPARATOR . 'README',
            'REGISTER' => $this->repoRoot . DIRECTORY_SEPARATOR . 'REGISTER',
            
            'CONTRIBUTING' => $this->repoRoot . DIRECTORY_SEPARATOR . 'CONTRIBUTING',
            'CREDITS' => $this->repoRoot . DIRECTORY_SEPARATOR . 'CREDITS',
        ];

        if (isset($specialBases[$name])) {
            $resolved = self::resolveLocalizedPath($specialBases[$name], $this->locale);
            if ($resolved === null) {
                return null;
            }
            $realPath = realpath($resolved);
            if ($realPath === false || !str_starts_with($realPath, $this->repoRoot . DIRECTORY_SEPARATOR)) {
                return null;
            }
            return $realPath;
        }

        $basePath = $this->docsDir . DIRECTORY_SEPARATOR . $name;
        $resolved = self::resolveLocalizedPath($basePath, $this->locale);
        if ($resolved === null) {
            return null;
        }
        $realPath = realpath($resolved);
        if ($realPath === false || !str_starts_with($realPath, $this->docsDir . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $proposalsDir = $this->docsDir . DIRECTORY_SEPARATOR . 'proposals' . DIRECTORY_SEPARATOR;
        if (str_starts_with($realPath, $proposalsDir)) {
            return null;
        }

        return $realPath;
    }

    /**
     * Render a 404 response.
     */
    private function render404(): void
    {
        http_response_code(404);
        $template = new Template();
        $template->renderResponse('admin/docs.twig', [
            'content'  => null,
            'doc_name' => null,
            'is_index' => false,
        ]);
    }
}
