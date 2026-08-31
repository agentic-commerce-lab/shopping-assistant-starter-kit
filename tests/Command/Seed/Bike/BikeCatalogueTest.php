<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed\Bike;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\Bike\BikeCatalogue;

/**
 * The catalogue is data, so these are the invariants the plan builder relies on and cannot check
 * once a write is in flight.
 *
 * **Why a data test rather than a data file review.** A seeder writes into somebody's shop and
 * cannot be run twice (see `SeedGuard`), so a typo that only surfaces mid-write leaves a half-seeded
 * catalogue and a database restore. Everything checkable before the first `create()` is checked
 * here — the same posture `ProductPlan::build()` takes when it collects every unresolved category
 * path and throws once, before any write starts.
 */
final class BikeCatalogueTest extends TestCase
{
    public function testEveryProductNumberIsUnique(): void
    {
        $numbers = array_column(BikeCatalogue::products(), 'number');

        self::assertSame(array_values(array_unique($numbers)), $numbers);
    }

    /**
     * The shop already carries `fx-*` and `sk-*` numbers. A collision would make the DAL reject the
     * whole batch, and the prefix is the only thing keeping the two apart.
     */
    public function testEveryProductNumberCarriesTheSeedPrefix(): void
    {
        foreach (BikeCatalogue::products() as $product) {
            self::assertStringStartsWith(BikeCatalogue::NUMBER_PREFIX, $product['number']);
        }
    }

    /**
     * A product may sit in a category the seed creates, or in one the shop already has — "Jerseys"
     * and "Saddles" exist and a thermal jersey belongs in the first of them, not in a second node
     * beside it. What it may not do is name a category nobody has declared, which is how a seed ends
     * up writing products into a path that silently resolves to null.
     */
    public function testEveryProductSitsInACategoryTheCatalogueDeclares(): void
    {
        $known = [...array_keys(BikeCatalogue::categories()), ...BikeCatalogue::EXISTING_CATEGORIES];

        foreach ($known as $path) {
            self::assertIsString($path);
        }

        foreach (BikeCatalogue::products() as $product) {
            self::assertContains($product['category'], $known, $product['number'] . ' has an unknown category');
        }
    }

    /**
     * `Restricted` is the shop's blocklist demonstration and `Merch` is the branded-goods shelf.
     * Seeding into either would quietly change what those two categories are for — and a product
     * landing in `Restricted` would be excluded from every search, which looks like a retrieval bug
     * rather than a seeding mistake.
     */
    public function testTheSeedStaysOutOfTheRestrictedAndMerchCategories(): void
    {
        self::assertNotContains('Restricted', BikeCatalogue::EXISTING_CATEGORIES);
        self::assertNotContains('Merch', BikeCatalogue::EXISTING_CATEGORIES);
    }

    /**
     * A category is declared as `Parent/Child`, and the parent must be one this shop already has —
     * the seeder attaches to the existing navigation rather than building a second tree beside it.
     */
    public function testEveryCategoryHangsUnderAnExistingTopLevelCategory(): void
    {
        foreach (BikeCatalogue::categories() as $path => $parent) {
            self::assertContains(
                $parent,
                BikeCatalogue::EXISTING_PARENTS,
                \sprintf('"%s" would hang under "%s", which this shop does not have', $path, $parent),
            );
        }
    }
}
