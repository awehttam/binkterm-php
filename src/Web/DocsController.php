<?php

namespace BinktermPHP\Web;

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

    public function __construct()
    {
        $this->docsDir = realpath(__DIR__ . '/../../docs');
        $this->repoRoot = realpath(__DIR__ . '/../..');
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
        $raw  = $this->rewriteLinks($raw);
        // HTML pass-through is enabled only for README.md, which is trusted
        // sysop-maintained content. All other docs are rendered with HTML escaped.
        $allowHtml = ($name === 'README');
        $html = MarkdownRenderer::toHtml($raw, allowHtml: $allowHtml);

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
     * Rewrite relative .md links in the raw Markdown to /admin/docs/view/{name}
     * so they work inside the docs browser.
     *
     * [Label](Foo.md)   → [Label](/admin/docs/view/Foo)
     * [Label](./Foo.md) → [Label](/admin/docs/view/Foo)
     *
     * External https:// links and anchor (#) links are left unchanged.
     */
    private function rewriteLinks(string $markdown): string
    {
        // Rewrite relative .md links to /admin/docs/view/{name}
        $markdown = preg_replace_callback(
            '/\[([^\]]+)\]\((?!https:\/\/|#)(?:\.\/)?([A-Za-z0-9_.\-\/]+)\.md(#[^\)]*)?\)/',
            function (array $m): string {
                $label  = $m[1];
                $target = str_replace('\\', '/', $m[2]);
                $anchor = $m[3] ?? '';

                if (str_contains($target, '../')) {
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

        return $markdown;
    }

    /**
     * Resolve a documentation name to an allowed markdown file path.
     */
    private function resolveDocPath(string $name): ?string
    {
        $specialDocs = [
            'FAQ'      => $this->repoRoot . DIRECTORY_SEPARATOR . 'FAQ.md',
            'README'   => $this->repoRoot . DIRECTORY_SEPARATOR . 'README.md',
            'REGISTER' => $this->repoRoot . DIRECTORY_SEPARATOR . 'REGISTER.md',
        ];

        if (isset($specialDocs[$name])) {
            $realPath = realpath($specialDocs[$name]);
            if ($realPath !== false && str_starts_with($realPath, $this->repoRoot . DIRECTORY_SEPARATOR)) {
                return $realPath;
            }
            return null;
        }

        $filePath = $this->docsDir . DIRECTORY_SEPARATOR . $name . '.md';
        $realPath = realpath($filePath);
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
