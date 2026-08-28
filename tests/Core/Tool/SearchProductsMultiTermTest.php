<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Grounding\VariantResolver;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Retrieval\FacetProbe;
use Swag\AssistantStarterKit\Core\Retrieval\QueryBuilder;
use Swag\AssistantStarterKit\Core\Tool\SearchProductsTool;
use Swag\AssistantStarterKit\Core\Tool\ToolArgumentException;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * One search, several terms, interleaved.
 *
 * ## The defect this closes
 *
 * Measured on 2026-08-26 against a 15,218-unit fashion catalogue — see
 * `docs/superpowers/reports/2026-08-26-occasion-queries-baseline.md`, Finding 2. Asked what to wear to
 * a wedding, the assistant answered:
 *
 * > Dresses: Silk Slip Occasion Dress, Pleated Midi Occasion Dress […]
 * > Suits: Three-Piece Occasion Suit, Linen Occasion Suit […]
 *
 * and rendered **five cards, all of them men's suits**. It had searched `occasion dress`, then
 * `occasion suit`, and the shop renders only the most recent search — so a shopper read about dresses
 * and was shown suits. Both archetypes, every run.
 *
 * Every grounding assertion passes straight through that: the dresses are real products, so
 * `no_invented_product` is satisfied, and nothing in the suite notices that the shopper cannot see the
 * thing being described.
 *
 * ## Why more terms rather than more searches
 *
 * The last-search-wins rule is not the bug — it is what stops a later unrelated lookup replacing the
 * answer, which the `last_search_wins` journey exists to protect. The bug is that an answer spanning
 * two kinds of product needed two searches to retrieve. So one call takes both terms and interleaves
 * what they find, and the rule is left alone.
 *
 * Interleaved, not concatenated: a limit of six over `["occasion dress", "occasion suit"]` must return
 * three of each, not six dresses. Concatenation would render exactly the same one-sided row the defect
 * produced, from a single search instead of two.
 */
final class SearchProductsMultiTermTest extends TestCase
{
    private TraceRecorder $trace;

    /**
     * Initialised in the constructor, not in `setUp()`, matching every other tool test here — mago's
     * `uninitialized-property` rule only accepts a constructor. `tool()` replaces it per call, because
     * an assertion about the trace has to read the same instance the tool recorded into.
     *
     * @param non-empty-string $name
     */
    public function __construct(string $name)
    {
        parent::__construct($name);

        $this->trace = new TraceRecorder();
    }

    public function testTwoTermsBothReachTheResult(): void
    {
        $result = $this->tool()(terms: ['glove', 'bottle'], limit: 6);

        $names = $this->names($result['products']);

        self::assertNotSame([], array_filter($names, static fn(string $n): bool => str_contains($n, 'Glove')));
        self::assertNotSame([], array_filter($names, static fn(string $n): bool => str_contains($n, 'Bottle')));
    }

    /**
     * The heart of it. `jersey` matches a family of several units in this fixture and `bottle` matches
     * fewer, so a concatenating implementation would spend the whole limit on jerseys — which is the
     * shape of the defect.
     */
    public function testTheLimitIsSharedBetweenTermsRatherThanSpentOnTheFirst(): void
    {
        $result = $this->tool()(terms: ['jersey', 'bottle'], limit: 4);

        $names = $this->names($result['products']);
        $bottles = array_filter($names, static fn(string $n): bool => str_contains($n, 'Bottle'));

        self::assertNotSame([], $bottles, 'the second term was crowded out: ' . implode(', ', $names));
    }

    public function testASingleTermStillBehavesExactlyAsBefore(): void
    {
        $viaTerm = $this->tool()(term: 'bottle', limit: 5);
        $viaTerms = $this->tool()(terms: ['bottle'], limit: 5);

        self::assertSame($this->names($viaTerm['products']), $this->names($viaTerms['products']));
    }

    /** `term` and `terms` together are merged rather than one silently winning. */
    public function testTermAndTermsAreMerged(): void
    {
        $result = $this->tool()(term: 'glove', terms: ['bottle'], limit: 6);

        $names = $this->names($result['products']);

        self::assertNotSame([], array_filter($names, static fn(string $n): bool => str_contains($n, 'Glove')));
        self::assertNotSame([], array_filter($names, static fn(string $n): bool => str_contains($n, 'Bottle')));
    }

    /** A duplicate term must not consume a slot in the bound, and must not duplicate a product. */
    public function testDuplicateTermsAreCollapsed(): void
    {
        $result = $this->tool()(terms: ['bottle', 'bottle', 'BOTTLE'], limit: 5);

        $names = $this->names($result['products']);

        self::assertSame(array_values(array_unique($names)), $names);
    }

    public function testTooManyTermsIsRejectedRatherThanTruncated(): void
    {
        $this->expectException(ToolArgumentException::class);

        $this->tool()(terms: ['a', 'b', 'c', 'd']);
    }

    /** The trace has to name every term, or a report cannot say which aisle a run actually searched. */
    public function testTheTraceRecordsEveryTerm(): void
    {
        $this->tool()(terms: ['glove', 'bottle'], limit: 6);

        $terms = [];

        foreach ($this->trace->events() as $event) {
            if ('understand' === $event->stage && \is_string($event->payload['term'] ?? null)) {
                $terms[] = $event->payload['term'];
            }
        }

        self::assertSame(['glove', 'bottle'], $terms);
    }

    /**
     * Takes the products list rather than the whole reply: the reply's shape carries optional
     * `families` and `note` keys, and a narrower parameter type would make every call site an
     * analyzer error for no benefit.
     *
     * @param list<array{id: string, name: string, options: array<string, string>, properties: array<string, list<string>>, reasons?: list<string>}> $products
     *
     * @return list<string>
     */
    private function names(array $products): array
    {
        return array_map(static fn(array $product): string => $product['name'], $products);
    }

    private function tool(CatalogScope $scope = new CatalogScope()): SearchProductsTool
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
            new AssistantConfig(scope: $scope),
        );
    }
}
