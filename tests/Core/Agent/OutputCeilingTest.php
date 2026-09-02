<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory;
use Swag\AssistantStarterKit\Core\Agent\AssistantRunner;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Tests\Support\UsesCatalogFixture;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Every request carries a ceiling on how much the model may generate.
 *
 * ## A spend guard, not a speed control
 *
 * This is deliberately **not** how the assistant is made fast — that was measured and it does not
 * work. Shortening replies from ~150 to ~47 words moved a first turn's median from 13.65 s to
 * 12.53 s on staging, inside a run-to-run spread of 9.7–19.1 s. The time is in the number of model
 * round trips, not the length of what comes back.
 *
 * What a ceiling does buy is that **no single reply can empty the merchant's API budget**. The
 * prompt asks for two or three sentences, and a prompt is a request: a model that ignores it, or is
 * talked out of it by a shopper asking for "everything you know about every jersey", generates until
 * something stops it. Nothing did.
 *
 * ## Why it is generous rather than tight
 *
 * A cap that truncates is worse than no cap: a reply cut off mid-word reads as a broken shop. So it
 * sits far above any legitimate answer — a normal reply measured ~47 words (~65 tokens), a merchant
 * whose `agentVoice` asks for detail might reach 300–400 — and only catches the runaway case. It is
 * a fuse, and a fuse that trips during normal use is the wrong fuse.
 */
final class OutputCeilingTest extends TestCase
{
    use UsesCatalogFixture;

    public function testTheGenerationCeilingIsSentWithEveryRequest(): void
    {
        $bodies = [];

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$bodies): MockResponse {
            $bodies[] = $options['body'] ?? '';

            // `finish_reason` is required by the platform's result converter, which rejects an
            // empty one outright — a response without it is not a response this code path can read.
            return new MockResponse(
                json_encode(
                    [
                        'choices' => [[
                            'finish_reason' => 'stop',
                            'message' => ['role' => 'assistant', 'content' => 'Here you go.'],
                        ]],
                    ],
                    \JSON_THROW_ON_ERROR,
                ),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        });

        $config = new AssistantConfig();
        $bundle = AssistantAgentFactory::withCoreToolsOnly($http)->create(
            FixtureCommerceGateway::fromFile(self::catalogFixturePath()),
            $config,
            cartAvailable: false,
            // A resolvable public host: this turn really reaches the (mocked) platform, so it has to
            // clear ValidatingHttpClient's SSRF guard.
            llm: new LlmSettings('https://1.1.1.1', 'test-key', 'gpt-x'),
        );

        (new AssistantRunner($config, $bundle))->run('hello', new MessageBag());

        self::assertNotSame([], $bodies, 'the platform was never called — this test would assert nothing');

        foreach ($bodies as $body) {
            self::assertIsString($body);
            $payload = json_decode($body, true);
            self::assertIsArray($payload);

            self::assertArrayHasKey('max_tokens', $payload, 'a request went out with no ceiling on it');
            self::assertSame(AssistantRunner::MAX_OUTPUT_TOKENS, $payload['max_tokens']);
        }
    }

    /**
     * The number itself, pinned. Not because a particular value is correct — it is a judgement — but
     * because the two ways it can be wrong are opposite and both silent: lowered far enough it starts
     * truncating real answers, and raised far enough it stops being a fuse at all.
     */
    public function testTheCeilingIsGenerousEnoughNeverToTruncateARealReply(): void
    {
        // ~65 tokens is a normal reply; 300–400 is a merchant who asked for detail in `agentVoice`.
        self::assertGreaterThanOrEqual(1000, AssistantRunner::MAX_OUTPUT_TOKENS);
        // And still a bound: an unbounded generation is what this exists to stop.
        self::assertLessThanOrEqual(4000, AssistantRunner::MAX_OUTPUT_TOKENS);
    }
}
