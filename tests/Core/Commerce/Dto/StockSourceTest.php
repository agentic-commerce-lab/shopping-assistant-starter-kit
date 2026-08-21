<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dto;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;

/**
 * The classification that decides whether a shopper may buy in one click.
 *
 * Measured on the live shop, 2026-08-21: every simple product — `fx-007` Alloy Water Bottle,
 * `fx-017` Alloy Bottle Cage — arrived at the widget as `stockSource: parent`, because the only
 * question asked of a row was whether it had a parent. The widget reads `parent` as "the variant
 * was not resolved", so all three lost their add-to-cart button and gained the note *"Stock shown
 * for the product, not this variant"* — about products that have no variants at all.
 *
 * The three cases are three different facts, so the tests below are stated as the three shapes a
 * catalogue row can have rather than as branches of a conditional.
 */
final class StockSourceTest extends TestCase
{
    private const PARENT_ID = 'fafafafafafafafafafafafafafafafa';

    public function testARowWithAParentIsAVariantAndReportsItsOwnStock(): void
    {
        self::assertSame(StockSource::Variant, StockSource::forProductRow(parentId: self::PARENT_ID, childCount: 0));
    }

    public function testARowWithChildrenIsAFamilyParentWhoseStockIsAnAggregate(): void
    {
        self::assertSame(StockSource::Parent, StockSource::forProductRow(parentId: null, childCount: 3));
    }

    /** The case that was missing, and the whole reason this method exists. */
    public function testARowWithNeitherParentNorChildrenIsAStandaloneProduct(): void
    {
        self::assertSame(StockSource::Product, StockSource::forProductRow(parentId: null, childCount: 0));
    }

    /**
     * `SalesChannelProductEntity::getChildCount()` is nullable, and an unread `childCount` must
     * not be taken as evidence of children — that would put every simple product back behind the
     * "unresolved variant" disclosure this method exists to end.
     */
    public function testAnUnknownChildCountIsTreatedAsNoChildren(): void
    {
        self::assertSame(StockSource::Product, StockSource::forProductRow(parentId: null, childCount: null));
    }
}
