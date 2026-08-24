<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Grounding\VariantResolver;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Retrieval\FacetProbe;
use Swag\AssistantStarterKit\Core\Retrieval\QueryBuilder;
use Swag\AssistantStarterKit\Core\Tool\SearchProductsTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Tests\Support\UsesCatalogFixture;

/**
 * The shopper's location narrows their search — until it would leave them with nothing.
 *
 * The fixture facts these rest on are stated rather than searched for, the way
 * {@see SearchProductsToolLimitTest} states its own: `Jerseys` holds exactly the three `fx-026`
 * variants, and `fx-004-black` — the catalogue's only glove — is in `Gloves`.
 */
final class SearchProductsCategoryTest extends TestCase
{
    use UsesCatalogFixture;

    private const CATEGORY = 'Jerseys';

    /** @var list<string> */
    private const IN_CATEGORY = ['fx-026-black-m', 'fx-026-blue-l', 'fx-026-blue-m'];

    private TraceRecorder $trace;

    private FactRenderer $renderer;

    /** @param non-empty-string $name */
    public function __construct(string $name)
    {
        parent::__construct($name);

        $this->trace = new TraceRecorder();
        $this->renderer = new FactRenderer($this->trace);
    }

    /**
     * Differential, not absolute: the same search without a category has to reach outside it, or
     * this would pass just as well against a build that ignores the constraint entirely.
     */
    public function testASearchIsConstrainedToTheCategoryBeingBrowsed(): void
    {
        $this->tool(null)(limit: 8);
        $unconstrained = $this->renderer->lastRetrievedBatch();

        self::assertNotEmpty(
            array_diff($unconstrained, self::IN_CATEGORY),
            'without the constraint the same search must reach outside the category',
        );

        $this->tool(self::CATEGORY)(limit: 8);

        self::assertEqualsCanonicalizing(self::IN_CATEGORY, $this->renderer->lastRetrievedBatch());
    }

    public function testAConstraintThatFindsNothingIsRetriedWithoutIt(): void
    {
        // A shopper standing in one aisle asking for something from another gets an answer, not
        // silence. `UnmatchedOptionRetry` is the precedent: this pipeline retries rather than
        // returning an empty set it caused itself.
        $this->tool(self::CATEGORY)(term: 'Commuter Glove');

        self::assertSame(['fx-004-black'], $this->renderer->lastRetrievedBatch());
        self::assertContains('retrieve.without_category', $this->trace->stages());
    }

    /**
     * Reproduces the eval failure of `page_context_not_a_cage · expert`, 2026-08-24, for free.
     *
     * "gloves" does not match "Commuter Glove" by term — that is the whole reason
     * {@see \Swag\AssistantStarterKit\Core\Retrieval\RelaxedTermRetry} exists, and why the
     * `plural_finds_singular` journey exists. A shopper standing in Jerseys asking for gloves needs
     * BOTH relaxations at once: the category dropped, and the term relaxed. While the category was
     * given up *last*, the two could never combine — the term retry relaxed the words inside the
     * cage, and the category retry removed the cage while restoring the unrelaxed words.
     */
    public function testAShopperInTheWrongAisleStillFindsAProductThatNeedsTermRelaxation(): void
    {
        $this->tool(self::CATEGORY)(term: 'gloves');

        self::assertSame(['fx-004-black'], $this->renderer->lastRetrievedBatch());
    }

    public function testWithNoCategoryNothingIsConstrainedAndNothingIsRetried(): void
    {
        $this->tool(null)(term: 'gloves');

        self::assertNotContains('retrieve.without_category', $this->trace->stages());
    }

    private function tool(?string $browsingCategoryId): SearchProductsTool
    {
        $gateway = FixtureCommerceGateway::fromFile(self::catalogFixturePath());
        $this->trace = new TraceRecorder();
        $this->renderer = new FactRenderer($this->trace);

        return new SearchProductsTool(
            $gateway,
            new FacetProbe($gateway, $this->trace),
            new QueryBuilder(),
            new VariantResolver($gateway, $this->trace),
            new BlocklistFilter(),
            $this->renderer,
            $this->trace,
            new AssistantConfig(),
            $browsingCategoryId,
        );
    }
}
