<?php

namespace BinktermPHP\AI;

/**
 * Sanitizes AI-generated SVG markup before it is stored and served back to
 * browsers as image/svg+xml. Unlike a raster image, a maliciously-crafted
 * SVG served with that content type executes as a mini HTML document if a
 * user navigates to it directly, so this strips anything that could carry
 * script execution or reach an external URL (script tags, event handler
 * attributes, external hrefs/url() references, embedded rasters, etc.)
 * using an explicit tag/attribute allowlist rather than a blocklist.
 */
class SvgIconSanitizer
{
    private const MAX_INPUT_LENGTH = 40000;

    private const ALLOWED_TAGS = [
        'svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline',
        'polygon', 'text', 'tspan', 'defs', 'lineargradient', 'radialgradient',
        'stop', 'clippath', 'mask', 'use', 'title', 'desc', 'symbol',
    ];

    private const ALLOWED_ATTRS = [
        'id', 'class', 'transform', 'style', 'fill', 'fill-opacity', 'fill-rule',
        'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin',
        'stroke-dasharray', 'stroke-opacity', 'opacity', 'viewbox', 'width',
        'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry',
        'points', 'd', 'offset', 'stop-color', 'stop-opacity', 'gradientunits',
        'gradienttransform', 'clip-path', 'font-family', 'font-size',
        'font-weight', 'text-anchor', 'dominant-baseline', 'xmlns',
    ];

    /**
     * @return string|null Sanitized SVG markup, or null if the input has no usable <svg> root.
     */
    public static function sanitize(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || strlen($raw) > self::MAX_INPUT_LENGTH) {
            return null;
        }

        $start = stripos($raw, '<svg');
        $end = strripos($raw, '</svg>');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $raw = substr($raw, $start, $end - $start + strlen('</svg>'));

        // LLMs occasionally emit a bare "&" (e.g. in text content) that isn't
        // a valid XML entity reference, which would otherwise fail the parse.
        $raw = preg_replace('/&(?!amp;|lt;|gt;|quot;|apos;|#\d+;|#x[0-9a-fA-F]+;)/', '&amp;', $raw) ?? $raw;

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $loaded = $doc->loadXML($raw, LIBXML_NONET);
        if (!$loaded) {
            // Best-effort recovery for otherwise-minor malformations (e.g. an
            // element opened without a matching close/self-close tag).
            $doc = new \DOMDocument();
            $loaded = $doc->loadXML($raw, LIBXML_NONET | LIBXML_RECOVER);
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || !$doc->documentElement || strtolower($doc->documentElement->tagName) !== 'svg') {
            return null;
        }

        self::cleanAttributes($doc->documentElement);
        self::cleanNode($doc->documentElement);

        if (!$doc->documentElement->hasAttribute('xmlns')) {
            $doc->documentElement->setAttribute('xmlns', 'http://www.w3.org/2000/svg');
        }

        $result = $doc->saveXML($doc->documentElement);
        return $result !== false ? $result : null;
    }

    private static function cleanNode(\DOMElement $node): void
    {
        // Snapshot children before mutating, since removeChild() during iteration
        // would otherwise skip siblings.
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof \DOMElement) {
                if (!in_array(strtolower($child->tagName), self::ALLOWED_TAGS, true)) {
                    $node->removeChild($child);
                    continue;
                }
                self::cleanAttributes($child);
                self::cleanNode($child);
            } elseif ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $node->removeChild($child);
            }
            // DOMText nodes are left as-is (e.g. <text> content).
        }
    }

    private static function cleanAttributes(\DOMElement $node): void
    {
        $attrs = [];
        foreach ($node->attributes as $attr) {
            $attrs[] = $attr;
        }

        foreach ($attrs as $attr) {
            $name = strtolower($attr->name);

            if (str_starts_with($name, 'on')) {
                $node->removeAttribute($attr->name);
                continue;
            }

            if ($name === 'href' || $name === 'xlink:href') {
                $value = trim($attr->value);
                if (!str_starts_with($value, '#')) {
                    $node->removeAttribute($attr->name);
                }
                continue;
            }

            if ($name === 'style' && (stripos($attr->value, '@import') !== false || stripos($attr->value, 'expression(') !== false)) {
                $node->removeAttribute($attr->name);
                continue;
            }

            if (!in_array($name, self::ALLOWED_ATTRS, true)) {
                $node->removeAttribute($attr->name);
                continue;
            }

            // Any attribute value (fill="url(...)", clip-path="url(...)", etc.)
            // can reference an external paint server / resource, not just style.
            // Only a same-document fragment reference is allowed.
            if (preg_match('/url\s*\(\s*[\'"]?(?!#)/i', $attr->value)) {
                $node->removeAttribute($attr->name);
            }
        }
    }
}
