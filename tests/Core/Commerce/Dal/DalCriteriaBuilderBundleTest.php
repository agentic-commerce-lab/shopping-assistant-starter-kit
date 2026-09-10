<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dal\BundleSupport;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalCriteriaBuilder;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;

/**
 * Whether the bundle association is asked for at all.
 *
 * **Asking for it unconditionally is a fatal error, not a slow query.** `bundleItems` is registered
 * by Shopware Commercial's `ProductExtension`, and `shopware/commercial` is not a dependency of this
 * plugin — in a shop without it the field does not exist on `ProductDefinition` and the DAL rejects
 * the criteria outright. Since the assistant's every product read goes through this builder, that
 * would take out search, direct lookup and the cart's own pre-check together. Hence a capability
 * check rather than a try/catch, in the spirit of {@see \Swag\AssistantStarterKit\Core\Commerce\Dal\DalVectorSupport}.
 */
final class DalCriteriaBuilderBundleTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    private function support(bool $available): BundleSupport
    {
        return new class($available) implements BundleSupport {
            public function __construct(
                private readonly bool $available,
            ) {}

            public function isAvailable(): bool
            {
                return $this->available;
            }
        };
    }

    private function associations(bool $available): array
    {
        return array_keys(
            (new DalCriteriaBuilder(bundles: $this->support($available)))
                ->build(new ProductQuery(term: 'kit'), new CatalogScope(), self::CHANNEL)
                ->getAssociations(),
        );
    }

    public function testTheBundleItemsAssociationIsLoadedWhenCommercialProvidesIt(): void
    {
        // The member PRODUCT, not just the join row: BundleItem carries the item's name, and the
        // row on its own holds a product id and a quantity.
        $associations = $this->associations(true);

        self::assertContains('bundleItems', $associations);

        $nested = (new DalCriteriaBuilder(bundles: $this->support(true)))
            ->build(new ProductQuery(term: 'kit'), new CatalogScope(), self::CHANNEL)
            ->getAssociation('bundleItems');

        self::assertArrayHasKey('product', $nested->getAssociations());
    }

    public function testNothingBundleShapedIsAskedForInAShopWithoutCommercial(): void
    {
        // The field does not exist there, and a criteria naming it fails the whole read.
        self::assertNotContains('bundleItems', $this->associations(false));
    }

    public function testTheAssociationsEveryProductReadAlreadyNeededAreUntouched(): void
    {
        // Guards against a regression where adding the bundle association replaces rather than
        // extends the set the mapper reads options, properties, delivery time and cover from.
        $associations = $this->associations(false);

        self::assertContains('options', $associations);
        self::assertContains('properties', $associations);
        self::assertContains('deliveryTime', $associations);
        self::assertContains('cover', $associations);
    }
}
