<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Grounding\VariantResolver;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Retrieval\FacetProbe;
use Swag\AssistantStarterKit\Core\Retrieval\QueryBuilder;
use Swag\AssistantStarterKit\Core\Retrieval\RelaxedTermRetry;
use Swag\AssistantStarterKit\Core\Tool\SearchProductsTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * The reported failure, end to end: a colleague asked the deployed shop for **gloves** and was told
 * the shop carries none. It carries `fx-004`, the Commuter Glove.
 *
 * Two things had to be true for that answer to happen, and both are covered here — the search found
 * nothing for the plural, and the tool's note then invited the model to read "no match" as "does not
 * exist".
 */
final class SearchProductsToolRelaxedTermTest extends TestCase
{
    private TraceRecorder $trace;

    /** @param non-empty-string $name */
    public function __construct(string $name)
    {
        parent::__construct($name);

        $this->trace = new TraceRecorder();
    }

    private function tool(): SearchProductsTool
    {
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
        $this->trace = new TraceRecorder();

        return new SearchProductsTool(
            $gateway,
            new FacetProbe($gateway, $this->trace),
            new QueryBuilder(),
            new VariantResolver($gateway, $this->trace),
            new BlocklistFilter(),
            new FactRenderer($this->trace),
            $this->trace,
            new AssistantConfig(),
        );
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<string>
     */
    private static function ids(array $result): array
    {
        /** @var list<array{id: string}> $products */
        $products = $result['products'] ?? [];

        return array_map(static fn(array $product): string => $product['id'], $products);
    }

    /** `fx-017` is the "Alloy Bottle Cage". The plural finds it only after the term is relaxed. */
    public function testAPluralThatMatchesNothingIsRetriedShortened(): void
    {
        $result = $this->tool()(term: 'cages');

        self::assertSame(['fx-017'], self::ids($result));
        self::assertSame(RelaxedTermRetry::NOTE, $result['note'] ?? null);
    }

    /**
     * The note is the point, not decoration: it tells the model the words were changed, so a near
     * match is not presented as the exact thing the shopper asked for.
     */
    public function testTheRelaxationIsRecordedInTheTrace(): void
    {
        $this->tool()(term: 'cages');

        $events = array_values(array_filter(
            $this->trace->events(),
            static fn($event): bool => 'retrieve.relaxTerm' === $event->stage,
        ));

        self::assertCount(1, $events);
        self::assertSame('cages', $events[0]->payload['term'] ?? null);
        self::assertSame('cage', $events[0]->payload['relaxedTerm'] ?? null);
        self::assertSame(1, $events[0]->payload['hits'] ?? null);
    }

    /** A term that already matches must not pay for a second read, nor carry a near-match note. */
    public function testATermThatMatchesIsNotRelaxed(): void
    {
        $result = $this->tool()(term: 'bottle cage');

        self::assertSame(['fx-017'], self::ids($result));
        self::assertArrayNotHasKey('note', $result);
        self::assertSame(
            [],
            array_values(array_filter(
                $this->trace->events(),
                static fn($event): bool => 'retrieve.relaxTerm' === $event->stage,
            )),
        );
    }

    /**
     * **The claim the assistant is not allowed to make.** When even the relaxed search finds
     * nothing, the note must report an empty search and must not hand the model a sentence about
     * what the shop does or does not sell.
     */
    public function testAnEmptySearchDoesNotLicenceAClaimAboutTheCatalogue(): void
    {
        $result = $this->tool()(term: 'snowboard');

        self::assertSame([], self::ids($result));
        self::assertSame(SearchProductsTool::NO_MATCH_NOTE, $result['note'] ?? null);
        self::assertStringNotContainsStringIgnoringCase('does not sell', self::ids($result)[0] ?? '');
        // Says what was learned, and says out loud what must not be concluded from it.
        self::assertStringContainsString('NOT that the shop has none', SearchProductsTool::NO_MATCH_NOTE);
    }

    /**
     * The relaxation only ever widens, and every stage after it still runs. A blocked product must
     * not reach the shopper through the second read — `fx-014` is the blocklist target.
     */
    public function testTheRetryCannotSmuggleABlockedProductThrough(): void
    {
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
        $this->trace = new TraceRecorder();

        $tool = new SearchProductsTool(
            $gateway,
            new FacetProbe($gateway, $this->trace),
            new QueryBuilder(),
            new VariantResolver($gateway, $this->trace),
            new BlocklistFilter(),
            new FactRenderer($this->trace),
            $this->trace,
            new AssistantConfig(
                scope: new \Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope(blockedProductIds: ['fx-014']),
            ),
        );

        // "cartridges" relaxes to "cartridge", which matches fx-014 by name.
        $result = $tool(term: 'cartridges');

        self::assertNotContains('fx-014', self::ids($result));
    }

    /** Sanity: the fixture really does hold the product the relaxed term is supposed to find. */
    public function testTheFixtureHoldsTheProductThisTestDependsOn(): void
    {
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
        $card = $gateway->product('fx-017', new \Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope());

        self::assertInstanceOf(ProductCard::class, $card);
        self::assertStringContainsString('Cage', $card->name);
    }
}
