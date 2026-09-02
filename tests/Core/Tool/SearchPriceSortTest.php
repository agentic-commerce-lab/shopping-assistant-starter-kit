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
use Swag\AssistantStarterKit\Core\Retrieval\PriceSort;
use Swag\AssistantStarterKit\Core\Retrieval\QueryBuilder;
use Swag\AssistantStarterKit\Core\Tool\SearchProductsTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * "What is the cheapest jersey" must be answerable.
 *
 * Reported from the staging shop, 2026-09-02. The reply was *"The shop shows the current figures for
 * the products you named"* — no product named, nothing answered, and the shopper had named nothing.
 * It was the only reply available: the tool result carries no figure by design, so the model could
 * not tell which was cheapest, and it had no way to ask the shop to sort either.
 *
 * The ordering is the answer. The shop sorts, the model reports the position, the card carries the
 * number — see {@see PriceSort} for why that keeps the grounding rule intact.
 *
 * ## Its own catalogue, with the prices written down
 *
 * Three jersey families at 49.90 / 59.00 / 69.00, and the cheapest one sold out. The shared fixture
 * carries a single jersey family, which cannot tell a price ordering from a relevance one — and an
 * assertion that passes because there is only one candidate proves nothing.
 */
final class SearchPriceSortTest extends TestCase
{
    private string $catalogue = '';

    protected function setUp(): void
    {
        $products = [
            self::family('Budget Jersey', 49.90, stock: 0),
            self::family('Club Jersey', 59.00, stock: 7),
            self::family('Thermal Jersey Long Sleeve', 69.00, stock: 4),
        ];

        $this->catalogue = tempnam(sys_get_temp_dir(), 'pricesort') . '.json';
        file_put_contents($this->catalogue, json_encode(['products' => $products], \JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        if ($this->catalogue !== '' && is_file($this->catalogue)) {
            unlink($this->catalogue);
        }
    }

    public function testTheCheapestMatchComesFirst(): void
    {
        self::assertSame('Budget Jersey', $this->names('price_asc')[0]);
    }

    public function testTheDearestMatchComesFirstWhenAskedTheOtherWay(): void
    {
        self::assertSame('Thermal Jersey Long Sleeve', $this->names('price_desc')[0]);
    }

    /**
     * Sorting by price replaces the in-stock bias rather than sitting behind it: a sold-out unit is
     * still the cheapest one, and it arrives carrying `soldOut` so the reply can say so. Promoting a
     * dearer unit instead would answer a question nobody asked — and this is the case that proves the
     * ordering is really price and not relevance, since relevance sorts a sold-out unit last.
     */
    public function testASoldOutUnitStillLeadsWhenItIsTheCheapest(): void
    {
        $products = $this->products('price_asc');

        self::assertSame('Budget Jersey', $products[0]['name']);
        self::assertTrue($products[0]['soldOut'] ?? false);
    }

    /** Without a sort, nothing changes: the in-stock bias still puts the sold-out family last. */
    public function testNoSortLeavesTheDefaultRankingAlone(): void
    {
        $ranked = $this->names(null);

        self::assertNotSame('Budget Jersey', $ranked[0], 'relevance ranking sorts a sold-out unit last');
        self::assertSame('Budget Jersey', end($ranked));
    }

    /** An ordering this shop cannot serve is relevance, not a refusal. */
    public function testAnUnknownSortFallsBackToRelevance(): void
    {
        self::assertSame($this->names(null), $this->names('by_vibes'));
        self::assertNull(PriceSort::fromRequest('by_vibes'));
        self::assertNull(PriceSort::fromRequest(null));
        self::assertSame(PriceSort::Ascending, PriceSort::fromRequest(' PRICE_ASC '));
        self::assertSame(PriceSort::Descending, PriceSort::fromRequest('price_desc'));
    }

    /** @return list<string> */
    private function names(?string $sort): array
    {
        return array_map(static fn(array $p): string => (string) $p['name'], $this->products($sort));
    }

    /** @return list<array<string, mixed>> the products the search returned, in its own order */
    private function products(?string $sort): array
    {
        $gateway = FixtureCommerceGateway::fromFile($this->catalogue);
        $trace = new TraceRecorder();

        $tool = new SearchProductsTool(
            $gateway,
            new FacetProbe($gateway, $trace),
            new QueryBuilder(),
            new VariantResolver($gateway, $trace),
            new BlocklistFilter(),
            new FactRenderer($trace),
            $trace,
            new AssistantConfig(),
        );

        /** @var list<array<string, mixed>> $products */
        $products = $tool('jersey', limit: 5, sort: $sort)['products'];

        return $products;
    }

    /** @return array<string, mixed> */
    private static function family(string $name, float $price, int $stock): array
    {
        $slug = strtolower(str_replace(' ', '-', $name));

        return [
            'id' => $slug,
            'name' => $name,
            'description' => 'A cycling jersey.',
            'price' => $price,
            'stock' => $stock * 3,
            'url' => '/p/' . $slug,
            'categoryPath' => ['Apparel'],
            'properties' => ['Material' => ['Polyester']],
            'variants' => [
                ['id' => $slug . '-m', 'options' => ['Size' => 'M'], 'price' => $price, 'stock' => $stock],
                ['id' => $slug . '-l', 'options' => ['Size' => 'L'], 'price' => $price, 'stock' => $stock],
            ],
        ];
    }
}
