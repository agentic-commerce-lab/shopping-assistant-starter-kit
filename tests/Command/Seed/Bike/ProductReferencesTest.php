<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed\Bike;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\Bike\BikeProducts;
use Swag\AssistantStarterKit\Command\Seed\Bike\BikeSeedContext;
use Swag\AssistantStarterKit\Command\Seed\Bike\ProductReferences;
use Swag\AssistantStarterKit\Command\Seed\Bike\UnresolvedReferences;

/**
 * Resolving all of one product's references at once — or none of them.
 *
 * **Why "all at once" is its own operation.** {@see BikeSeedPlan} used to combine the four lookups
 * itself, in a four-clause guard, and mago reported the resulting class as too complex. It was right
 * about more than the arithmetic: the plan does not care *which* reference failed, only whether it may
 * build a payload, and short-circuiting the four also meant a run reported fewer misses than it had
 * found.
 *
 * @phpstan-import-type ProductSpec from BikeProducts
 */
final class ProductReferencesTest extends TestCase
{
    public function testAProductWhoseReferencesAllResolveComesBackWithThemAll(): void
    {
        $unresolved = new UnresolvedReferences();

        $resolved = ProductReferences::resolveAll($this->gloves(), $this->context(), $unresolved);

        self::assertNotNull($resolved);
        self::assertNotSame('', $resolved->categoryId);
        self::assertNotSame('', $resolved->manufacturerId);
        self::assertCount(2, $resolved->properties);
        self::assertTrue($unresolved->isEmpty());
    }

    public function testAProductWithAnUnknownCategoryResolvesToNothing(): void
    {
        $unresolved = new UnresolvedReferences();
        $product = $this->gloves(category: 'Nowhere');

        self::assertNull(ProductReferences::resolveAll($product, $this->context(), $unresolved));
        self::assertStringContainsString('category "Nowhere"', $unresolved->describe());
    }

    /**
     * Every miss, not the first. A run over the catalogue must be able to report a renamed category
     * *and* a deleted brand in one pass, or fixing a seed becomes a loop of one error at a time.
     */
    public function testEveryKindOfMissIsRecordedInOnePass(): void
    {
        $unresolved = new UnresolvedReferences();
        $product = $this->gloves(category: 'Nowhere', manufacturer: 'Nobody');

        self::assertNull(ProductReferences::resolveAll($product, $this->context(), $unresolved));
        self::assertStringContainsString('category "Nowhere"', $unresolved->describe());
        self::assertStringContainsString('manufacturer "Nobody"', $unresolved->describe());
    }

    public function testAProductWithAnUndeclaredPropertyValueResolvesToNothing(): void
    {
        $unresolved = new UnresolvedReferences();
        $product = $this->gloves(properties: ['Season' => ['Monsoon']]);

        self::assertNull(ProductReferences::resolveAll($product, $this->context(), $unresolved));
        self::assertStringContainsString('property "Season = Monsoon"', $unresolved->describe());
    }

    private function context(): BikeSeedContext
    {
        return BikeSeedContext::resolve(FakeShopTaxonomy::complete(), FakeShopTaxonomy::SALES_CHANNEL_ID);
    }

    /**
     * A real catalogue product, with the one field a test wants to break passed in.
     *
     * Built as a whole literal rather than spread over a base: the analyzer reads a spread as only the
     * keys that were overridden, so `[...$this->gloves(), 'category' => 'Nowhere']` arrives as
     * `array{category: string}` and no longer satisfies `ProductSpec`.
     *
     * @param array<string, list<string>> $properties
     *
     * @return ProductSpec
     */
    private function gloves(
        string $category = 'Gloves',
        string $manufacturer = 'Kestrel Works',
        array $properties = ['Season' => ['Winter'], 'Insulation' => ['Insulated']],
    ): array {
        return [
            'number' => 'bk-gloves-winter',
            'name' => 'Winter Gloves',
            'description' => 'Insulated.',
            'price' => 39.0,
            'stock' => 8,
            'manufacturer' => $manufacturer,
            'category' => $category,
            'properties' => $properties,
            'variants' => ['Size' => ['M']],
        ];
    }
}
