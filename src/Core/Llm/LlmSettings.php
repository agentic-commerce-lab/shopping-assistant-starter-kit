<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Llm;

final readonly class LlmSettings
{
    /**
     * @param non-empty-string $model
     */
    public function __construct(
        public string $baseUrl,
        #[\SensitiveParameter]
        public string $apiKey,
        public string $model,
        public bool $allowInsecureEgress = false,
    ) {}
}
