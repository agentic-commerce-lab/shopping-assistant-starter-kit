<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Eval\Journey;
use Swag\AssistantStarterKit\Eval\JourneyAttempt;
use Swag\AssistantStarterKit\Eval\JourneyPage;
use Swag\AssistantStarterKit\Tests\Support\UsesCatalogFixture;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The harness must put the shopper on a page the way the storefront does, or a green journey is a
 * claim about a pipeline nobody ships.
 *
 * Deterministic: the model is a scripted {@see MockHttpClient} that answers in one plain sentence,
 * so this runs in the default suite with no credentials and no spend.
 */
final class JourneyAttemptPageContextTest extends TestCase
{
    use UsesCatalogFixture;

    public function testTheOpenProductIsResolvedPreGroundedAndTraced(): void
    {
        [, $trace] = $this->attempt()->run(
            $this->journey(JourneyPage::parse(['productId' => 'fx-026-blue-m'], 'page_context_fidelity')),
            'is this in stock?',
        );

        $payload = $trace->payload('page.context');

        self::assertIsArray($payload);
        self::assertTrue($payload['reported']);
        self::assertSame('fx-026-blue-m', $payload['resolved']);
    }

    /**
     * The trust model, checked by the harness rather than only by a unit test: a journey may block
     * the very product its page declares, and page context must grant nothing.
     */
    public function testAProductTheScopeRefusesIsNotResolved(): void
    {
        $journey = $this->journey(
            JourneyPage::parse(['productId' => 'fx-026-blue-m'], 'page_context_fidelity'),
            config: ['blockedProductIds' => ['fx-026-blue-m']],
        );

        [, $trace] = $this->attempt()->run($journey, 'is this in stock?');

        $payload = $trace->payload('page.context');

        self::assertIsArray($payload);
        self::assertTrue($payload['reported'], 'the journey did report a page product');
        self::assertNull($payload['resolved'], 'the catalogue scope must refuse it');
    }

    public function testTheBrowsedCategoryIsCarriedAndTraced(): void
    {
        [, $trace] = $this->attempt()->run(
            $this->journey(JourneyPage::parse(['categoryId' => 'Jerseys'], 'page_context_fidelity')),
            'what do you have?',
        );

        $payload = $trace->payload('page.context');

        self::assertIsArray($payload);
        self::assertFalse($payload['reported']);
        self::assertSame('Jerseys', $payload['category']);
    }

    public function testAJourneyWithNoPageRecordsAnEmptyPageContext(): void
    {
        [, $trace] = $this->attempt()->run($this->journey(JourneyPage::parse(null, 'j')), 'hello');

        $payload = $trace->payload('page.context');

        self::assertIsArray($payload, 'production records this stage on every turn, so the harness must too');
        self::assertFalse($payload['reported']);
        self::assertNull($payload['resolved']);
        self::assertNull($payload['category']);
    }

    /** @param array<string, mixed> $config */
    private function journey(JourneyPage $page, array $config = []): Journey
    {
        return new Journey(
            id: 'page_context_fidelity',
            category: 'grounding',
            runs: 1,
            archetypes: ['expert' => 'placeholder'],
            config: $config,
            turns: ['archetype'],
            assertions: [],
            page: $page,
        );
    }

    private function attempt(): JourneyAttempt
    {
        $http = new MockHttpClient(static fn(): MockResponse => new MockResponse(json_encode(
            ['choices' => [['message' => ['content' => 'Certainly.'], 'finish_reason' => 'stop']]],
            \JSON_THROW_ON_ERROR,
        )));

        return new JourneyAttempt(
            new LlmSettings('https://1.1.1.1', 'test-key', 'gpt-x'),
            self::catalogFixturePath(),
            $http,
        );
    }
}
