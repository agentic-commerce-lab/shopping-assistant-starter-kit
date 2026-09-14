<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Grounding\ProductNames;
use Swag\AssistantStarterKit\Core\Grounding\ProseProductNames;

/**
 * Two different products that share a name, which is not the same thing as two variants that do.
 *
 * **The live failure, on the demo shop 2026-09-14.** Two standalone products both called "Hex Bolt
 * M5", one filed under Brakes and one under Components. Asked for hex bolts, the assistant answered
 * *"available from two different departments"* and named both — and exactly one card rendered, the
 * Components one, at its own price and with none of the six documents that hang on the other. The
 * shopper read an answer about two products and saw a single card contradicting half of it.
 *
 * The cause is deliberate, documented behaviour that was right for the case it was written for:
 * Shopware names a variant after its parent, so "Trail Jersey" answers to Blue/M and Blue/L alike,
 * and rendering both would put a price and a stock figure for a variant the turn never touched in
 * front of the shopper. {@see ProseProductNamesAmbiguousNameTest} pins that, and it still holds.
 *
 * The family key is what tells the two cases apart — variants share a `parentId`, namesakes do not.
 */
final class ProseProductNamesNamesakesTest extends TestCase
{
    private function card(string $id, string $name, ?string $parentId = null): ProductCard
    {
        return new ProductCard(
            id: $id,
            parentId: $parentId,
            name: $name,
            description: null,
            price: 4.20,
            currency: 'EUR',
            stock: 9,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/detail/' . $id,
            imageUrl: null,
        );
    }

    /**
     * @param list<ProductCard> $cards
     * @param list<string>      $preferred
     *
     * @return list<string>
     */
    private function resolve(string $prose, array $cards, array $preferred = []): array
    {
        return ProseProductNames::idsNamedIn(
            $prose,
            ProductNames::of($cards),
            $preferred,
            [],
            ProductNames::familiesOf($cards),
        );
    }

    /**
     * The measured failure, as a test: both must render.
     */
    public function testTwoStandaloneProductsSharingANameBothRender(): void
    {
        $ids = $this->resolve('We have the Hex Bolt M5 in two different departments.', [
            $this->card('id-brakes', 'Hex Bolt M5'),
            $this->card('id-components', 'Hex Bolt M5'),
        ]);

        self::assertSame(['id-brakes', 'id-components'], $ids);
    }

    /**
     * And the case that must NOT change: two variants of one family are one answer, and the
     * preferred list still decides which of them the name points at.
     */
    public function testTwoVariantsOfOneFamilyStillRenderOnce(): void
    {
        $cards = [
            $this->card('id-jersey-m', 'Trail Jersey', parentId: 'id-jersey'),
            $this->card('id-jersey-l', 'Trail Jersey', parentId: 'id-jersey'),
        ];

        self::assertSame(['id-jersey-l'], $this->resolve('Added the Trail Jersey in size L.', $cards, ['id-jersey-l']));
        self::assertSame(['id-jersey-m'], $this->resolve('Added the Trail Jersey.', $cards));
    }

    /**
     * The shapes mixed: a family and a namesake under one name yield one card for the family and
     * one for the namesake, never three.
     */
    public function testAFamilyAndANamesakeYieldOneCardEach(): void
    {
        $ids = $this->resolve('The Trail Jersey is available.', [
            $this->card('id-jersey-m', 'Trail Jersey', parentId: 'id-jersey'),
            $this->card('id-jersey-l', 'Trail Jersey', parentId: 'id-jersey'),
            $this->card('id-other-jersey', 'Trail Jersey'),
        ]);

        self::assertSame(['id-jersey-m', 'id-other-jersey'], $ids);
    }

    /**
     * Namesakes stay adjacent and keep the position of the name that found them, so a second
     * product mentioned later still sorts after both of them.
     */
    public function testNamesakesKeepThePositionOfTheirName(): void
    {
        $ids = $this->resolve('First the Brake Pad, then the Hex Bolt M5.', [
            $this->card('id-brakes', 'Hex Bolt M5'),
            $this->card('id-components', 'Hex Bolt M5'),
            $this->card('id-pad', 'Brake Pad'),
        ]);

        self::assertSame(['id-pad', 'id-brakes', 'id-components'], $ids);
    }
}
