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
 * The path the question actually takes.
 *
 * Traced against staging 2026-09-21, *"Habt ihr den City Tyre in 700x32?"* produced **one** tool
 * call — `search_products` — and never reached `get_product`, where the alternatives used to be
 * attached. The result carried the family's sizes and nothing about which of them could be bought,
 * so the model recommended a different product: the only thing in the result whose availability it
 * could see.
 *
 * Fixture family `fx-026` Trail Jersey: Blue/M sold out, Blue/L and Black/M stocked.
 */
final class SearchProductsAlternativesTest extends TestCase
{
    use UsesCatalogFixture;

    private function tool(bool $suggest): SearchProductsTool
    {
        $gateway = FixtureCommerceGateway::fromFile(self::catalogFixturePath());
        $trace = new TraceRecorder();

        return new SearchProductsTool(
            $gateway,
            new FacetProbe($gateway, $trace),
            new QueryBuilder(),
            new VariantResolver($gateway, $trace),
            new BlocklistFilter(),
            new FactRenderer($trace),
            $trace,
            new AssistantConfig(suggestAlternatives: $suggest),
        );
    }

    /**
     * @return array<string, mixed> the summary of the sold-out Blue/M, or an empty array
     */
    private function blueM(bool $suggest): array
    {
        $result = $this->tool($suggest)(term: 'Trail Jersey', options: [['option' => 'Blue'], ['option' => 'M']]);

        foreach ($result['products'] ?? [] as $product) {
            if (($product['soldOut'] ?? false) === true) {
                return $product;
            }
        }

        return [];
    }

    public function testTheSoldOutVariantNowCarriesTheSizesAShopperCouldBuyInstead(): void
    {
        $product = $this->blueM(suggest: true);

        self::assertNotSame([], $product, 'the sold-out variant did not come back at all');
        self::assertSame(
            [
                ['Colour' => 'Blue', 'Size' => 'L'],
                ['Colour' => 'Black', 'Size' => 'M'],
            ],
            $product['alternatives'] ?? null,
        );
    }

    public function testWithTheSettingOffTheSearchResultIsExactlyWhatItWasBefore(): void
    {
        $product = $this->blueM(suggest: false);

        self::assertNotSame([], $product);
        self::assertArrayNotHasKey('alternatives', $product);
    }
}
