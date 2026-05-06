<?php

namespace BinktermPHP\Media;

/** Base class for providers that resolve via an oEmbed endpoint. */
abstract class OEmbedProvider implements EmbedProviderInterface
{
    public function getType(): string { return 'platform'; }

    /** Returns the oEmbed endpoint URL for the given content URL. */
    abstract protected function getOEmbedEndpoint(string $url): string;

    public function getEmbedHtml(string $url): string
    {
        $endpoint = $this->getOEmbedEndpoint($url);
        $json     = $this->fetchOEmbed($endpoint);

        if ($json === null || empty($json['html'])) {
            return '';
        }

        return $this->sandboxIframes((string) $json['html']);
    }

    private function fetchOEmbed(string $endpoint): ?array
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout'        => 5,
                'ignore_errors'  => true,
                'header'         => "User-Agent: BinktermPHP/1.0\r\n",
            ],
        ]);

        $body = @file_get_contents($endpoint, false, $ctx);
        if ($body === false) {
            return null;
        }

        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }

    /** Adds sandbox attribute to any <iframe> tags in oEmbed HTML that lack it. */
    private function sandboxIframes(string $html): string
    {
        return preg_replace_callback(
            '/<iframe\b([^>]*)>/i',
            function (array $m) {
                $attrs = $m[1];
                if (!preg_match('/\bsandbox\b/i', $attrs)) {
                    $attrs .= ' sandbox="allow-scripts allow-same-origin allow-presentation"';
                }
                return '<iframe' . $attrs . '>';
            },
            $html
        ) ?? $html;
    }
}
