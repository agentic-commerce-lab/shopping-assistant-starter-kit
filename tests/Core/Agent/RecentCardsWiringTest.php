<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Tests\Support\UsesCatalogFixture;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * What the shopper was shown in the previous reply, carried into this turn.
 *
 * Separate from {@see PageContextTest} because the two answer different questions. That one is about
 * a hint the CLIENT sends and how far it is trusted; this is about what the SERVER already knows and
 * had been throwing away between turns.
 *
 * The defect, measured on staging 2026-09-02: a shopper shown Club Jersey **Blue/M** asked to add
 * "that" and got **Red/XL** — same parent, a variant they had never seen, reported as though it were
 * the one on screen. Only prose crosses a turn boundary, so the model had a name and no id, searched
 * again, and a differently-keyed search ranked the family's variants differently. See
 * {@see \Swag\AssistantStarterKit\Core\Prompt\RecentCardsContext}.
 */
final class RecentCardsWiringTest extends TestCase
{
    use UsesCatalogFixture;

    private const OPEN_PRODUCT = 'fx-026-blue-l';

    /**
     * The previous turn's cards reach both the prompt and the renderer.
     *
     * Two different jobs, and the fix needs both. The **prompt** is what lets the model resolve
     * "that one" to an id instead of searching by name and landing on a sibling variant. The
     * **renderer** is what stops `validate()` counting that id as invented when the model does name
     * it — the same pairing `viewing` has relied on since it was built.
     */
    public function testThePreviousTurnsCardsArePreGroundedToo(): void
    {
        $gateway = self::gateway();
        $shown = $gateway->product('fx-030-tan-650', new CatalogScope());
        self::assertNotNull($shown);

        $bundle = AssistantAgentFactory::withCoreToolsOnly(self::http())->create(
            $gateway,
            new AssistantConfig(),
            cartAvailable: false,
            llm: self::llm(),
            recentCards: [$shown],
        );

        self::assertStringContainsString($shown->id, $bundle->viewing, 'the id must reach the prompt');
        self::assertContains($shown->id, $bundle->renderer->retrievedIds());
    }

    /**
     * Both contexts can be live at once — a shopper reading a product page after a search — and
     * neither may erase the other. The page product is the more specific of the two and stays the
     * default card set; the shortlist only has to remain nameable.
     */
    public function testAViewedProductAndAPreviousShortlistCoexist(): void
    {
        $gateway = self::gateway();
        $scope = new CatalogScope();
        $card = $gateway->product(self::OPEN_PRODUCT, $scope);
        $shown = $gateway->product('fx-030-tan-650', $scope);
        self::assertNotNull($card);
        self::assertNotNull($shown);

        $bundle = AssistantAgentFactory::withCoreToolsOnly(self::http())->create(
            $gateway,
            new AssistantConfig(),
            cartAvailable: false,
            llm: self::llm(),
            viewing: $card,
            recentCards: [$shown],
        );

        self::assertContains($card->id, $bundle->renderer->retrievedIds());
        self::assertContains($shown->id, $bundle->renderer->retrievedIds());

        // `lastBatchIds` is what renders when the turn calls no tool, and it is REPLACED rather than
        // merged. The product on screen is the more specific answer to "what is this about", so it
        // has to be the one left standing.
        self::assertSame([$card->id], $bundle->renderer->lastRetrievedBatch());
    }

    /**
     * Nameable, but not the answer to the next question. Without this a turn that answered from the
     * shop's documents rendered the previous turn's product card underneath it — see
     * {@see StaleCardsDoNotFollowTheConversationTest} for the reported defect.
     */
    public function testAPreviousShortlistAloneIsNotTheDefaultCardSet(): void
    {
        $gateway = self::gateway();
        $shown = $gateway->product('fx-017', new CatalogScope());
        self::assertNotNull($shown);

        $bundle = AssistantAgentFactory::withCoreToolsOnly(self::http())->create(
            $gateway,
            new AssistantConfig(),
            cartAvailable: false,
            llm: self::llm(),
            recentCards: [$shown],
        );

        self::assertContains($shown->id, $bundle->renderer->retrievedIds(), 'must stay nameable');
        self::assertSame([], $bundle->renderer->lastRetrievedBatch(), 'must not be the default card set');
    }

    public function testNoPreviousCardsLeavesThePromptUnchanged(): void
    {
        $gateway = self::gateway();

        $without = AssistantAgentFactory::withCoreToolsOnly(self::http())->create(
            $gateway,
            new AssistantConfig(),
            cartAvailable: false,
            llm: self::llm(),
        );

        self::assertSame('', $without->viewing);
    }

    private static function gateway(): FixtureCommerceGateway
    {
        return FixtureCommerceGateway::fromFile(self::catalogFixturePath());
    }

    private static function llm(): LlmSettings
    {
        return new LlmSettings('https://example.invalid', 'test-key', 'gpt-x');
    }

    /** The platform must not be called: every assertion here is about what was built, not answered. */
    private static function http(): MockHttpClient
    {
        return new MockHttpClient(static function (): never {
            throw new \RuntimeException('The platform must not be called by this test.');
        });
    }
}
