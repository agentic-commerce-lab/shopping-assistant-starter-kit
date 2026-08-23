<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Llm;

use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The shipped platform: Symfony AI's generic OpenAI-compatible bridge, behind the interface.
 *
 * Holds the optional HTTP client that used to be threaded through
 * {@see \Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory::create()} as a fifth parameter.
 * Moving it here is what took that method back under the linter's parameter bound, and it belongs
 * here anyway: which client the platform talks over is the platform's business, not the turn's.
 *
 * The eval suite and the unit tests pass a client that throws, so a test that accidentally reaches
 * the network fails loudly instead of spending money.
 */
final readonly class SymfonyAiPlatform implements LlmPlatformInterface
{
    public function __construct(
        private ?HttpClientInterface $httpClient = null,
    ) {}

    public function of(LlmSettings $settings): PlatformInterface
    {
        return PlatformFactory::create($settings, $this->httpClient);
    }
}
