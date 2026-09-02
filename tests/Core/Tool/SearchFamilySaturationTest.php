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
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * A few large families must not make every other family unreachable.
 *
 * ## The defect
 *
 * Reported from the staging shop, 2026-09-02: *"What is the cheapest jersey"* answered with two of
 * the shop's three jerseys and left out the cheapest one. Read off the trace, the candidate window
 * held **11 Club Jersey variants and 9 Thermal Jersey variants — two families, 20 of 20 slots.** The
 * third family never entered it.
 *
 * Relevance ranking clusters a family's variants adjacently, because they share a name and a
 * description. {@see \Swag\AssistantStarterKit\Core\Tool\FamilyDiversifier} exists to spread the
 * ANSWER across families, but it can only diversify what retrieval brought back, and
 * `SearchProductsTool` says plainly that nothing downstream can recover a unit retrieval dropped.
 * So the bound that decided the answer was the candidate window, and it was 20.
 *
 * Plain *"zeig mir Jerseys"* was wrong the same way for the same reason — the superlative in the
 * reported question only made the omission obvious, because the missing family was the cheapest.
 *
 * ## What this pins
 *
 * Three families of twelve variants each: 36 units, more than the old window of 20 and inside the
 * new floor of 50. The assertion is on families rather than on ids, since which variant represents a
 * family is a different question (`ContradictedVariants`, `FamilyDiversifier`).
 *
 * It does NOT pin the window to 50. A single family larger than the window still saturates it, and
 * the structural fix is family-aware retrieval; this asserts the reported shape stays fixed.
 */
final class SearchFamilySaturationTest extends TestCase
{
    private const FAMILIES = ['Club Jersey', 'Thermal Jersey Long Sleeve', 'Trail Jersey'];

    private string $catalogue = '';

    protected function setUp(): void
    {
        $products = [];

        foreach (self::FAMILIES as $index => $name) {
            $variants = [];

            // Twelve each: three families of twelve is 36 units, which the old 20-wide window could
            // not hold and a plain relevance prefix would fill with the first two families.
            for ($v = 0; $v < 12; $v++) {
                $variants[] = [
                    'id' => sprintf('fam-%d-v%02d', $index, $v),
                    'options' => [
                        'Colour' => ['Blue', 'Black', 'White'][$v % 3],
                        'Size' => ['S', 'M', 'L', 'XL'][$v % 4],
                    ],
                    // The third family is the cheapest, as in the shop this was measured against.
                    'price' => 70.0 - ($index * 10) + $v,
                    'stock' => 5,
                ];
            }

            $products[] = [
                'id' => sprintf('fam-%d', $index),
                'name' => $name,
                'description' => 'A cycling jersey.',
                'price' => 70.0 - ($index * 10),
                'stock' => 60,
                'url' => '/p/fam-' . $index,
                'categoryPath' => ['Apparel'],
                'properties' => ['Material' => ['Polyester']],
                'variants' => $variants,
            ];
        }

        $this->catalogue = tempnam(sys_get_temp_dir(), 'saturation') . '.json';
        file_put_contents($this->catalogue, json_encode(['products' => $products], \JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        if ($this->catalogue !== '' && is_file($this->catalogue)) {
            unlink($this->catalogue);
        }
    }

    public function testEveryMatchingFamilyCanStillBeReached(): void
    {
        $result = $this->tool()('jersey', limit: 5);

        $families = array_values(array_unique(array_map(
            static fn(array $product): string => $product['name'],
            $result['products'],
        )));

        sort($families);
        $expected = self::FAMILIES;
        sort($expected);

        self::assertSame($expected, $families, 'all three families must be reachable, not the first two');
    }

    /** And the cheapest family is among them, which is what the reported question turned on. */
    public function testTheCheapestFamilyIsReachable(): void
    {
        $result = $this->tool()('jersey', limit: 5);
        $names = array_map(static fn(array $p): string => $p['name'], $result['products']);

        self::assertContains('Trail Jersey', $names);
    }

    private function tool(): SearchProductsTool
    {
        $gateway = FixtureCommerceGateway::fromFile($this->catalogue);
        $trace = new TraceRecorder();

        return new SearchProductsTool(
            $gateway,
            new FacetProbe($gateway, $trace),
            new QueryBuilder(),
            new VariantResolver($gateway, $trace),
            new BlocklistFilter(),
            new FactRenderer($trace),
            $trace,
            new AssistantConfig(),
        );
    }
}
