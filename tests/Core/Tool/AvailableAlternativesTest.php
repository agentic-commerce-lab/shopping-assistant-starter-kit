<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Tool\AvailableAlternatives;
use Swag\AssistantStarterKit\Tests\Support\UsesCatalogFixture;

/**
 * *"Do you have the trail jersey in blue, size M?"* — it is sold out, and the shop has two other
 * variants a shopper could buy today.
 *
 * Fixture family `fx-026` Trail Jersey: Blue/M sold out, Blue/L stocked, Black/M stocked. The gap it
 * leaves is the point — there is no Black/L.
 */
final class AvailableAlternativesTest extends TestCase
{
    use UsesCatalogFixture;

    private function gateway(): FixtureCommerceGateway
    {
        return FixtureCommerceGateway::fromFile(self::catalogFixturePath());
    }

    private function card(string $id, ?string $parentId, int $stock): ProductCard
    {
        $found = $this->gateway()->product($id, new CatalogScope());
        self::assertNotNull($found, 'fixture no longer holds ' . $id);

        return $found;
    }

    public function testASoldOutVariantIsAnsweredWithTheSiblingsAShopperCanActuallyBuy(): void
    {
        $key = AvailableAlternatives::keyFor(
            $this->card('fx-026-blue-m', 'fx-026', 0),
            $this->gateway(),
            new CatalogScope(),
        );

        self::assertSame(
            [
                ['Colour' => 'Blue', 'Size' => 'L'],
                ['Colour' => 'Black', 'Size' => 'M'],
            ],
            $key['alternatives'] ?? null,
        );
    }

    public function testTheOfferIsRealVariantsAndNeverACrossProductOfOptionValues(): void
    {
        // The whole reason this reports combinations rather than "Colour: Blue, Black / Size: L, M".
        // Those two lines read as four buyable jerseys; the shop has two, and Black/L is a product
        // the assistant would have invented while every individual value it named was true.
        $key = AvailableAlternatives::keyFor(
            $this->card('fx-026-blue-m', 'fx-026', 0),
            $this->gateway(),
            new CatalogScope(),
        );

        self::assertNotContains(['Colour' => 'Black', 'Size' => 'L'], $key['alternatives'] ?? []);
    }

    public function testTheSoldOutVariantIsNotOfferedAsItsOwnAlternative(): void
    {
        $key = AvailableAlternatives::keyFor(
            $this->card('fx-026-blue-m', 'fx-026', 0),
            $this->gateway(),
            new CatalogScope(),
        );

        self::assertNotContains(['Colour' => 'Blue', 'Size' => 'M'], $key['alternatives'] ?? []);
    }

    public function testAProductAShopperCanBuyIsOfferedNoAlternativeAtAll(): void
    {
        // Nothing to solve. An "also available in" list beside a product in stock is noise, and
        // noise the model will repeat.
        $key = AvailableAlternatives::keyFor(
            $this->card('fx-026-blue-l', 'fx-026', 12),
            $this->gateway(),
            new CatalogScope(),
        );

        self::assertSame([], $key);
    }

    public function testASoldOutProductWithNoFamilyGetsNoSuggestion(): void
    {
        // `fx-011` is a standalone discontinued brake pad. Reaching for "something similar" here
        // means leaving the product the shopper named, which is a merchant's decision about their
        // range rather than a fact about their catalogue.
        $key = AvailableAlternatives::keyFor($this->card('fx-011', null, 0), $this->gateway(), new CatalogScope());

        self::assertSame([], $key);
    }

    public function testAGatewayThatCannotReadFamiliesSaysNothingRatherThanGuessing(): void
    {
        $key = AvailableAlternatives::keyFor($this->card('fx-026-blue-m', 'fx-026', 0), null, new CatalogScope());

        self::assertSame([], $key);
    }

    public function testABlockedSiblingIsNeverOffered(): void
    {
        // Through the scope, like every other family read: otherwise the blocklist leaks the exact
        // catalogue it exists to hide, into the one place a shopper is guaranteed to read.
        $key = AvailableAlternatives::keyFor(
            $this->card('fx-026-blue-m', 'fx-026', 0),
            $this->gateway(),
            new CatalogScope(blockedProductIds: ['fx-026-blue-l']),
        );

        self::assertSame([['Colour' => 'Black', 'Size' => 'M']], $key['alternatives'] ?? null);
    }
}
