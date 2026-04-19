<?php

namespace BinktermPHP\AI;

/**
 * Thrown when a user's credit balance is too low to cover an AI request.
 */
class InsufficientCreditsException extends \RuntimeException
{
    public function __construct(private int $requiredCredits)
    {
        parent::__construct("Insufficient credits: {$requiredCredits} credits required.");
    }

    public function getRequiredCredits(): int
    {
        return $this->requiredCredits;
    }
}
