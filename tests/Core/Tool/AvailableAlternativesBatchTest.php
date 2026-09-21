<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Tool\FamilyAlternatives;
use Swag\AssistantStarterKit\Tests\Core\Commerce\RecordingVariantLookup;

/**
 * The search path, where a shopper's *"habt ihr die Hose in 32x32?"* actually lands.
 *
 * The trace of that question against staging (2026-09-21) showed one tool call, `search_products`,
 * and a result carrying the family's sizes but nothing about which of them can be bought. The model
 * recommended a DIFFERENT product — the only thing in the result whose availability it could see.
 * That is the gap these keys close, per card, in one read per family.
 */
final class AvailableAlternativesBatchTest extends TestCase
{
    /** @param array<string, string> $options */
    private function variant(string $id, string $parentId, array $options, int $stock): ProductCard
    {
        return new ProductCard(
            id: $id,
            parentId: $parentId,
            name: 'Slim Chino',
            description: null,
            price: 69.0,
            currency: 'EUR',
            stock: $stock,
            stockSource: StockSource::Variant,
            deliveryTime: null,
            url: '/detail/' . $id,
            imageUrl: null,
            options: $options,
        );
    }

    private function lookup(): RecordingVariantLookup
    {
        return new RecordingVariantLookup(['trousers' => [
            $this->variant('w32', 'trousers', ['Size' => '32x32'], 0),
            $this->variant('w31', 'trousers', ['Size' => '31x32'], 4),
            $this->variant('w34', 'trousers', ['Size' => '34x32'], 2),
        ]]);
    }

    public function testTheSoldOutSizeIsToldWhichOtherSizesFit(): void
    {
        $keys = FamilyAlternatives::forAll(
            [$this->variant('w32', 'trousers', ['Size' => '32x32'], 0)],
            $this->lookup(),
            new CatalogScope(),
        );

        self::assertSame([['Size' => '31x32'], ['Size' => '34x32']], $keys[0]['alternatives'] ?? null);
    }

    public function testASizeThatIsInStockIsToldNothing(): void
    {
        // It answers the shopper's question by itself. An "also available in" list beside it is
        // noise, and noise the model repeats.
        $keys = FamilyAlternatives::forAll(
            [$this->variant('w31', 'trousers', ['Size' => '31x32'], 4)],
            $this->lookup(),
            new CatalogScope(),
        );

        self::assertSame([], $keys);
    }

    public function testTwoSoldOutSizesOfOneFamilyCostOneReadBetweenThem(): void
    {
        // A read per card would put a round trip on every sold-out variant of every search. A
        // fashion catalogue returns those by the handful.
        $lookup = $this->lookup();

        FamilyAlternatives::forAll(
            [
                $this->variant('w32', 'trousers', ['Size' => '32x32'], 0),
                $this->variant('w36', 'trousers', ['Size' => '36x32'], 0),
            ],
            $lookup,
            new CatalogScope(),
        );

        self::assertSame(['trousers' => 1], $lookup->calls);
    }

    public function testAResultWithNothingSoldOutAsksTheShopNothing(): void
    {
        $lookup = $this->lookup();

        FamilyAlternatives::forAll(
            [$this->variant('w31', 'trousers', ['Size' => '31x32'], 4)],
            $lookup,
            new CatalogScope(),
        );

        self::assertSame([], $lookup->calls);
    }

    public function testTheKeysComeBackAgainstTheCardTheyBelongTo(): void
    {
        // Keyed by position, because the caller merges them into summaries built from the same
        // list. A flat list would silently attach the first family's sizes to the wrong product.
        $keys = FamilyAlternatives::forAll(
            [
                $this->variant('w31', 'trousers', ['Size' => '31x32'], 4),
                $this->variant('w32', 'trousers', ['Size' => '32x32'], 0),
            ],
            $this->lookup(),
            new CatalogScope(),
        );

        self::assertSame([1], array_keys($keys));
    }
}
