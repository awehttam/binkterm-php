<?php

namespace BinktermPHP\AI;

interface AiProviderInterface
{
    public function getName(): string;

    public function getDefaultModel(): string;

    public function isConfigured(): bool;

    public function generateText(AiRequest $request): AiResponse;

    public function generateJson(AiRequest $request): AiResponse;
}
