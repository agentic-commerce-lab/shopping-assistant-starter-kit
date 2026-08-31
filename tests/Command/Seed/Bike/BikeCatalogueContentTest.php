<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed\Bike;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\Bike\BikeCatalogue;

/**
 * The per-product checks, split from {@see BikeCatalogueTest} for mago's per-class complexity budget.
 *
 * That file asks whether the catalogue's taxonomy is coherent — the paths, the parents, the size of
 * the thing. This one walks all eighty-five products and asks whether each one is writable: a real
 * brand, a real option value, a price somebody could charge.
 */
final class BikeCatalogueContentTest extends TestCase
{
    public function testEveryVariantAxisNamesAGroupTheCatalogueDeclares(): void
    {
        $groups = BikeCatalogue::propertyGroups();

        foreach (BikeCatalogue::products() as $product) {
            foreach ($product['variants'] ?? [] as $group => $values) {
                self::assertArrayHasKey($group, $groups, $product['number'] . ' varies by an unknown group');

                $declared = $groups[$group] ?? [];
                self::assertIsArray($declared);

                foreach ($values as $value) {
                    self::assertContains(
                        $value,
                        $declared,
                        \sprintf('%s uses "%s", which group "%s" does not offer', $product['number'], $value, $group),
                    );
                }
            }
        }
    }

    public function testEveryProductNamesAManufacturerTheShopAlreadyHas(): void
    {
        foreach (BikeCatalogue::products() as $product) {
            self::assertContains($product['manufacturer'], BikeCatalogue::EXISTING_MANUFACTURERS);
        }
    }

    public function testEveryPriceIsAPositiveFigure(): void
    {
        foreach (BikeCatalogue::products() as $product) {
            self::assertGreaterThan(0.0, $product['price'], $product['number'] . ' is priced at or below zero');
        }
    }

    /**
     * Not a style rule. The shop renders stock from its own record, and the assistant's whole
     * availability posture — ruling R75, the `variant_stock` journey — is only exercised when some
     * of the catalogue is actually out of stock. A seed where everything is available tests the easy
     * half of the pipeline.
     */
    public function testSomeProductsAreOutOfStockSoAvailabilityCanBeTested(): void
    {
        $stocks = array_column(BikeCatalogue::products(), 'stock');

        self::assertContains(0, $stocks);
    }

    public function testTheCatalogueIsTheSizeItClaimsToBe(): void
    {
        // A guard on the seeder's blast radius rather than an exact count: this number is what a
        // reviewer sees before approving a write into a live shop, and it must not drift silently.
        self::assertGreaterThanOrEqual(60, \count(BikeCatalogue::products()));
        self::assertLessThanOrEqual(90, \count(BikeCatalogue::products()));
    }
}
