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

    public function __construct()
    {
        $this->docsDir = realpath(__DIR__ . '/../../docs');
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

        $filePath = $this->docsDir . DIRECTORY_SEPARATOR . $name . '.md';

        // Resolve and confirm the file is inside docsDir (no traversal).
        $realPath = realpath($filePath);
        if ($realPath === false || strpos($realPath, $this->docsDir) !== 0) {
            $this->render404();
            return;
        }

        // Must not be inside the proposals subdirectory.
        $proposalsDir = $this->docsDir . DIRECTORY_SEPARATOR . 'proposals';
        if (strpos($realPath, $proposalsDir) === 0) {
            $this->render404();
            return;
        }

        $raw  = file_get_contents($realPath);
        $raw  = $this->rewriteLinks($raw);
        $html = MarkdownRenderer::toHtml($raw);

        $template = new Template();
        $template->renderResponse('admin/docs.twig', [
            'content'   => $html,
            'doc_name'  => $name,
            'is_index'  => $name === 'index',
        ]);
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
        return preg_replace_callback(
            '/\[([^\]]+)\]\(\.?\/?([A-Za-z0-9_.\-]+)\.md(#[^\)]*)?\)/',
            function (array $m): string {
                $label  = $m[1];
                $target = $m[2];
                $anchor = $m[3] ?? '';
                // Extra safety: reject any embedded path separators
                if (str_contains($target, '/') || str_contains($target, '\\')) {
                    return $m[0];
                }
                return '[' . $label . '](/admin/docs/view/' . $target . $anchor . ')';
            },
            $markdown
        );
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
