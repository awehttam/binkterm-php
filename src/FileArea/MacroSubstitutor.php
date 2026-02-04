<?php

namespace BinktermPHP\FileArea;

/**
 * MacroSubstitutor - Replace macros in script templates
 */
class MacroSubstitutor
{
    /**
     * Substitute macros in the given template string.
     *
     * @param string $template
     * @param array $context
     * @return string
     */
    public function substitute(string $template, array $context): string
    {
        if ($template == '') {
            return '';
        }

        return preg_replace_callback('/%[a-z0-9_]+%/i', function ($matches) use ($context) {
            return $this->resolveMacro($matches[0], $context);
        }, $template);
    }

    /**
     * Resolve a single macro.
     *
     * @param string $macro
     * @param array $context
     * @return string
     */
    private function resolveMacro(string $macro, array $context): string
    {
        $key = strtolower(trim($macro, '%'));
        if (!array_key_exists($key, $context)) {
            return '';
        }

        $value = $context[$key];
        if (is_array($value) || is_object($value)) {
            return '';
        }

        return (string)$value;
    }
}
