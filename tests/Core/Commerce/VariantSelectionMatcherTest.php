<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;
use Swag\AssistantStarterKit\Core\Commerce\VariantSelectionMatcher;

/**
 * The matching rules both gateways share. Stated against the seeded TRAIL-JERSEY so a failure
 * names a shopper-visible outcome rather than a comparison detail.
 */
final class VariantSelectionMatcherTest extends TestCase
{
    /** @param array<string, string> $options */
    private function card(string $id, array $options): ProductCard
    {
        return new ProductCard(
            id: $id,
            parentId: 'fafafafafafafafafafafafafafafafa',
            name: 'Trail Jersey',
            description: null,
            price: 74.90,
            currency: 'EUR',
            stock: 0,
            stockSource: StockSource::Variant,
            deliveryTime: null,
            url: '/detail/' . $id,
            imageUrl: null,
            options: $options,
        );
    }

    public function testEverySelectionMustMatchNotJustOne(): void
    {
        $blueM = $this->card('a2', ['Colour' => 'Blue', 'Size' => 'M']);

        self::assertTrue(VariantSelectionMatcher::matchesAll($blueM, [
            new VariantSelection('Blue', 'Colour'),
            new VariantSelection('M', 'Size'),
        ]));

        self::assertFalse(VariantSelectionMatcher::matchesAll($blueM, [
            new VariantSelection('Blue', 'Colour'),
            new VariantSelection('L', 'Size'),
        ]));
    }

    public function testAGrouplessSelectionMatchesAnyGroupsValue(): void
    {
        // The model usually knows "blue" without knowing it belongs to "Colour".
        self::assertTrue(VariantSelectionMatcher::matchesAll(
            $this->card('a2', ['Colour' => 'Blue', 'Size' => 'M']),
            [new VariantSelection('M')],
        ));
    }

    public function testAGroupThatTheVariantDoesNotHaveFailsTheMatch(): void
    {
        // Skipping an unknown group would let "the Blue jersey, Width wide" resolve to a Blue
        // variant and report its stock as the answer to a question about a dimension this
        // catalogue does not carry.
        self::assertFalse(VariantSelectionMatcher::matchesAll(
            $this->card('a2', ['Colour' => 'Blue', 'Size' => 'M']),
            [new VariantSelection('wide', 'Width')],
        ));
    }

    public function testMatchingIsCaseSensitiveAndThatIsDeliberate(): void
    {
        // Pinned, not tolerated. Values arrive already canonicalised to the catalogue's own
        // spelling (ruling R20), and group names stay exact so a wrong guess is DROPPED VISIBLY
        // into filtersDropped rather than silently mis-targeting another group (ruling R21).
        // GetProductTool still passes raw model casing (ruling R46) — the fix belongs there, and
        // loosening this comparison would trade R21's visibility away to paper over it.
        $blueM = $this->card('a2', ['Colour' => 'Blue', 'Size' => 'M']);

        self::assertFalse(VariantSelectionMatcher::matchesAll($blueM, [new VariantSelection('blue', 'Colour')]));
        self::assertFalse(VariantSelectionMatcher::matchesAll($blueM, [new VariantSelection('Blue', 'colour')]));
    }

    public function testNoSelectionsMatchEverything(): void
    {
        // The vacuous-truth boundary. VariantResolver never calls with an empty list (it returns
        // early), and FixtureCommerceGateway's `count === 1` check is what turns "matches
        // everything" into "resolves nothing" on a multi-variant family.
        self::assertTrue(VariantSelectionMatcher::matchesAll($this->card('a2', ['Colour' => 'Blue']), []));
    }
}
