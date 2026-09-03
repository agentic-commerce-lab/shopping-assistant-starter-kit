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

/**
 * A jersey catalogue with the prices written down, for the two price-ordering test classes.
 *
 * Three families at 49.90 / 59.00 / 69.00 with the cheapest one sold out. The shared fixture carries
 * a single jersey family, which cannot tell a price ordering from a relevance one — an assertion that
 * passes because there is only one candidate proves nothing.
 *
 * A base class rather than a trait: `mago analyze` cannot resolve `createMock()` through a trait, the
 * lesson `RetentionTestCase` records, and the same shape suits this for the same reason.
 */
abstract class PriceSortTestCase extends TestCase
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

    /** @return list<string> */
    protected function names(?string $sort, string $shopperMessage = ''): array
    {
        return array_map(static fn(array $p): string => (string) $p['name'], $this->products($sort, $shopperMessage));
    }

    /** @return list<array<string, mixed>> the products the search returned, in its own order */
    protected function products(?string $sort, string $shopperMessage = ''): array
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
            // Ninth is where the shopper is standing, tenth is what they said — both client-claimed
            // per-turn state, both last so positional constructions keep their meaning.
            null,
            $shopperMessage,
        );

        /** @var list<array<string, mixed>> $products */
        $products = $tool('jersey', limit: 5, sort: $sort)['products'];

        return $products;
    }

    /** @return array<string, mixed> */
    protected static function family(string $name, float $price, int $stock): array
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
