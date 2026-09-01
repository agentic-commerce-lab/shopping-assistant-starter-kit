<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Category\CategoryEntity;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalActiveCategories;

/**
 * The measured leak this class exists for.
 *
 * Shopware Commercial's Advanced Product Catalogues hide categories by subscribing to
 * `sales_channel.category.loaded` and calling `setActive(false)` on every category the active
 * organisation is not released for — in PHP, **after** the query. `DalCategoryTreeReader` filtered
 * `active = true` in the criteria, i.e. in SQL, and then trusted every row that came back. Verified
 * 2026-09-01 against a Commercial shop: the reader would name a restricted category to a B2B shopper,
 * contradicting the promise in its own docblock that "a category hidden from the storefront
 * navigation must not be advertised by the assistant either".
 */
final class DalActiveCategoriesTest extends TestCase
{
    private static function category(string $id, bool $active): CategoryEntity
    {
        $category = new CategoryEntity();
        $category->setId($id);
        $category->setActive($active);

        return $category;
    }

    public function testACategoryDeactivatedAfterLoadingIsDropped(): void
    {
        $kept = DalActiveCategories::of([
            self::category('allowed', true),
            self::category('restricted', false),
        ]);

        self::assertSame(['allowed'], array_map(static fn(CategoryEntity $c): string => $c->getId(), $kept));
    }

    public function testEverythingActiveSurvives(): void
    {
        $kept = DalActiveCategories::of([self::category('a', true), self::category('b', true)]);

        self::assertCount(2, $kept);
    }

    /**
     * A repository hands back `getElements()`, whose values are typed only as entities. Anything that
     * is not a category is skipped rather than assumed — the reader did this before and it stays.
     */
    public function testNonCategoriesAreSkipped(): void
    {
        self::assertSame([], DalActiveCategories::of(['not-an-entity', 42, null]));
    }

    /**
     * `CategoryEntity::$active` is a typed property with no default, so a partially hydrated category
     * throws an `\Error` on read rather than returning null. That must degrade to "not visible", not
     * end the shopper's turn — the same hazard rulings R48/R49 guard for prices, and it caught this
     * class on its first run.
     */
    public function testAnUninitialisedActiveFlagDegradesToNotVisible(): void
    {
        self::assertSame([], DalActiveCategories::of([new CategoryEntity()]));
    }
}
