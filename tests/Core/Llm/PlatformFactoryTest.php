<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Llm;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Llm\LlmException;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Core\Llm\PlatformFactory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PlatformFactoryTest extends TestCase
{
    public function testInvokesTheCompatibleEndpointAndReturnsText(): void
    {
        // finish_reason is required by the installed 0.12 CompletionsConversionTrait,
        // which throws "Unsupported finish reason" without it; not needed in the brief's
        // originally targeted version.
        $http = new MockHttpClient(new MockResponse(json_encode(
            [
                'choices' => [['message' => ['content' => 'Two options fit your budget.'], 'finish_reason' => 'stop']],
            ],
            \JSON_THROW_ON_ERROR,
        )));

        $platform = PlatformFactory::create(new LlmSettings('https://1.1.1.1', 'test-key', 'gpt-x'), $http);

        $result = $platform->invoke('gpt-x', new MessageBag(Message::ofUser('anything under 40?')));

        self::assertSame('Two options fit your budget.', $result->asText());
    }

    public function testTheGuardSurvivesTheFactoryWiring(): void
    {
        $platform = PlatformFactory::create(
            new LlmSettings('https://169.254.169.254', 'k', 'gpt-x'),
            new MockHttpClient(new MockResponse('{}')),
        );

        $this->expectException(LlmException::class);

        $platform->invoke('gpt-x', new MessageBag(Message::ofUser('x')));
    }
}
