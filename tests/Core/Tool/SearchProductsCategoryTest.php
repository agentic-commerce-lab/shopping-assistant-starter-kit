<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
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
 */
final class SearchProductsCategoryTest extends TestCase
{
    use UsesCatalogFixture;

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
     * this test would pass just as well against a build that ignores the constraint entirely.
     */
    public function testASearchIsConstrainedToTheCategoryBeingBrowsed(): void
    {
        $category = $this->aCategoryInTheFixture();

        $this->tool(null)(limit: 8);
        $outside = array_filter(
            $this->renderer->lastRetrievedBatch(),
            fn(string $id): bool => !\in_array($category, $this->cardById($id)->categoryPath, strict: true),
        );
        self::assertNotEmpty(
            $outside,
            'without the constraint the same search must reach outside the category, or this asserts nothing',
        );

        $this->tool($category)(limit: 8);

        // `lastRetrievedBatch()` carries ids, so the cards are looked back up to read their paths.
        $retrieved = $this->renderer->lastRetrievedBatch();
        self::assertNotEmpty($retrieved, 'the constrained search must find something, or this asserts nothing');

        foreach ($retrieved as $id) {
            self::assertContains($category, $this->cardById($id)->categoryPath);
        }
    }

    public function testAConstraintThatFindsNothingIsRetriedWithoutIt(): void
    {
        // A shopper standing in one aisle asking for something from another gets an answer, not
        // silence. `UnmatchedOptionRetry` is the precedent: this pipeline retries rather than
        // returning an empty set it caused itself.
        $category = $this->aCategoryInTheFixture();
        $absent = $this->aTermAbsentFrom($category);

        $this->tool($category)(term: $absent);

        self::assertNotEmpty($this->renderer->lastRetrievedBatch());
        self::assertContains('retrieve.without_category', $this->trace->stages());
    }

    public function testWithNoCategoryNothingIsConstrainedAndNothingIsRetried(): void
    {
        $this->tool(null)(term: $this->aTermInTheFixture());

        self::assertNotContains('retrieve.without_category', $this->trace->stages());
    }

    private function tool(?string $browsingCategoryId): SearchProductsTool
    {
        $gateway = FixtureCommerceGateway::fromFile(self::catalogFixturePath());

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

    private function aCategoryInTheFixture(): string
    {
        foreach ($this->allCards() as $card) {
            if ($card->categoryPath !== []) {
                return $card->categoryPath[0];
            }
        }

        self::fail('The catalogue fixture has no categorised product; this feature cannot be tested against it.');
    }

    private function aTermInTheFixture(): string
    {
        foreach ($this->allCards() as $card) {
            if ($card->categoryPath !== []) {
                return $card->name;
            }
        }

        self::fail('The catalogue fixture is empty.');
    }

    private function aTermAbsentFrom(string $category): string
    {
        foreach ($this->allCards() as $card) {
            if (!\in_array($category, $card->categoryPath, strict: true)) {
                return $card->name;
            }
        }

        self::fail('Every fixture product is in one category; the retry cannot be tested against it.');
    }

    private function cardById(string $id): ProductCard
    {
        foreach ($this->allCards() as $card) {
            if ($card->id === $id) {
                return $card;
            }
        }

        self::fail(\sprintf('The search returned id %s, which is not in the catalogue fixture.', $id));
    }

    /** @return list<ProductCard> */
    private function allCards(): array
    {
        return FixtureCommerceGateway::fromFile(self::catalogFixturePath())->search(
            new ProductQuery(limit: 50),
            new CatalogScope(),
        );
    }
}
