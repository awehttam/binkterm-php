<?php

namespace BinktermPHP\AI;

/**
 * Exception raised when an AI provider call fails or returns invalid data.
 */
class AiException extends \RuntimeException
{
    private string $provider;
    private ?int $httpStatus;
    private string $errorCode;
    private ?string $responseBody;

    public function __construct(
        string $provider,
        string $message,
        ?int $httpStatus = null,
        string $errorCode = '',
        ?string $responseBody = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->provider = $provider;
        $this->httpStatus = $httpStatus;
        $this->errorCode = $errorCode;
        $this->responseBody = $responseBody;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }
}
