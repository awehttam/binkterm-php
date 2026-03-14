<?php

namespace BinktermPHP\Robots;

/**
 * Interface for echomail (and future netmail) robot message processors.
 *
 * A processor receives individual messages and decides whether to handle them.
 * All implementations must be stateless beyond the injected $db dependency.
 */
interface MessageProcessorInterface
{
    /**
     * Return the machine identifier for this processor type.
     * Must match the value stored in echomail_robots.processor_type.
     *
     * @return string
     */
    public static function getProcessorType(): string;

    /**
     * Return a human-readable display name for admin UI dropdowns.
     *
     * @return string
     */
    public static function getDisplayName(): string;

    /**
     * Return a brief description of what this processor does.
     *
     * @return string
     */
    public static function getDescription(): string;

    /**
     * Process a single message.
     *
     * The $message array contains echomail columns (id, subject, from_name,
     * to_name, message_text, echoarea_id, etc.). This same interface is
     * designed to work for netmail messages (same column shape) in the future.
     *
     * @param array $message      Row from the echomail (or netmail) table
     * @param array $robotConfig  Decoded processor_config JSONB from the robot rule
     * @return bool               True if the message was matched/handled, false to skip
     */
    public function processMessage(array $message, array $robotConfig): bool;
}
