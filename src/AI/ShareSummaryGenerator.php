<?php

namespace BinktermPHP\AI;

use BinktermPHP\I18n\Translator;

/**
 * Generates a short plain-text summary of an echomail message for use as
 * the Open Graph description on shared message pages.
 */
class ShareSummaryGenerator
{
    const DEFAULT_SYSTEM_PROMPT =
        'You write concise Open Graph meta descriptions for BBS message board posts. '
        . 'Write one to two plain-text sentences summarising the key point of the message. '
        . 'Do not start with "This message". Do not use Markdown or HTML. '
        . 'Output only the summary — no labels, no quotes.';

    /**
     * Generate a one-to-two sentence plain-text summary of the given message.
     *
     * @param array       $message Message row (expects 'subject' and 'message_text' keys)
     * @param string|null $locale  BCP 47 locale code for the output language (e.g. 'fr', 'de')
     * @return string The generated summary text
     * @throws \RuntimeException on AI provider failure
     */
    public static function generate(array $message, ?string $locale = null): string
    {
        $subject = trim($message['subject'] ?? '');
        $body    = trim(strip_tags($message['message_text'] ?? ''));

        // Truncate body fed to the model — enough for context, not wasteful
        if (mb_strlen($body) > 1500) {
            $body = mb_substr($body, 0, 1500) . '…';
        }

        $userPrompt = $subject !== ''
            ? "Subject: {$subject}\n\n{$body}"
            : $body;

        $bbsConfig    = \BinktermPHP\BbsConfig::getConfig();
        $configPrompt = trim((string)($bbsConfig['ai_assistant']['share_summary_prompt'] ?? ''));
        $systemPrompt = $configPrompt !== '' ? $configPrompt : self::DEFAULT_SYSTEM_PROMPT;

        if ($locale !== null && $locale !== '' && $locale !== 'en') {
            $languageName = (new Translator())->getLocaleName($locale);
            $systemPrompt .= " Write your response in {$languageName}.";
        }

        $request = new AiRequest(
            'share_summary',
            $systemPrompt,
            $userPrompt,
            null,
            null,
            0.3,
            120
        );

        $response = AiService::create()->generateText($request);
        return trim($response->getContent());
    }
}
