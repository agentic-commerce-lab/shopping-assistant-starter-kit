<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Tool\NoMatchOrientation;
use Swag\AssistantStarterKit\Core\Tool\SearchProductsTool;
use Swag\AssistantStarterKit\Tests\Core\Commerce\LoopOnlyGateway;

/**
 * What an empty search tells the model, when the gateway can describe its own tree.
 *
 * ## The measured failure
 *
 * On the lab shop — cycling gear, zero products matching dress, suit, gown, formal, wedding, bridal or
 * tuxedo — "what to wear to a wedding" produced:
 *
 * > "The search for wedding attire came up empty. Could you share more details about what you are
 * > looking for, such as a specific style, item type, colour, or size, so I can try different search
 * > terms for you?"
 *
 * Every rule held and the answer was still bad: it sent the shopper hunting for words that cannot
 * succeed. `NO_MATCH_NOTE` rightly forbids "we don't sell that", so the only move left was to imply a
 * better phrasing existed — because nothing told the assistant what the shop DOES have.
 *
 * After: *"A search for wedding attire found no matching products. You can explore our available
 * categories instead: Jewelry, Beauty & Shoes / Movies / …"*
 */
final class NoMatchOrientationTest extends TestCase
{
    public function testAnEmptySearchCarriesTheShopsDepartments(): void
    {
        $result = $this->tool()(term: 'zzzznotathing');

        self::assertSame([], $result['products']);
        self::assertArrayHasKey('shop_sells', $result);
        self::assertNotSame([], $result['shop_sells']);
        self::assertSame(NoMatchOrientation::NOTE, $result['note'] ?? null);
    }

    /** The prohibition NO_MATCH_NOTE carried must survive into the note that replaces it. */
    public function testTheNoteStillForbidsClaimingTheShopHasNone(): void
    {
        self::assertStringContainsString('NOT that the shop has none', NoMatchOrientation::NOTE);
        self::assertStringContainsString('never tell the shopper the shop does not sell it', NoMatchOrientation::NOTE);
    }

    /** And it must forbid the specific bad move that was measured. */
    public function testTheNoteForbidsAskingForBetterWords(): void
    {
        self::assertStringContainsString('Do NOT ask them to describe it differently', NoMatchOrientation::NOTE);
    }

    /**
     * The second measured failure: every name true, and the answer still noise. Asked what to wear to a
     * wedding, the assistant recited five unrelated departments — Movies among them — which implies a
     * hunt that cannot succeed.
     */
    public function testTheNoteForbidsRecitingUnrelatedDepartments(): void
    {
        self::assertStringContainsString('ONLY if it is plausibly where', NoMatchOrientation::NOTE);
        self::assertStringContainsString('do not list departments that have nothing to do', NoMatchOrientation::NOTE);
    }

    /** And it must not let the list be passed off as the whole shop. */
    public function testTheNoteForbidsPresentingTheListAsTheWholeShop(): void
    {
        self::assertStringContainsString("Never present this list as the shop's full range", NoMatchOrientation::NOTE);
        self::assertStringContainsString('focused on', NoMatchOrientation::NOTE);
    }

    public function testASuccessfulSearchCarriesNoOrientation(): void
    {
        $result = $this->tool()(term: 'bottle');

        self::assertNotSame([], $result['products']);
        self::assertArrayNotHasKey('shop_sells', $result);
    }

    /**
     * A gateway that cannot describe its tree keeps exactly the old behaviour — the whole point of the
     * capability being optional.
     */
    public function testAGatewayWithoutATreeReaderKeepsTheOldNote(): void
    {
        $orientation = NoMatchOrientation::of(
            new LoopOnlyGateway(FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json')),
            new CatalogScope(),
        );

        self::assertNull($orientation);
        self::assertSame(
            ['note' => SearchProductsTool::NO_MATCH_NOTE],
            NoMatchOrientation::replyFor(
                new LoopOnlyGateway(FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json')),
                new CatalogScope(),
                SearchProductsTool::NO_MATCH_NOTE,
            ),
        );
    }

    public function testItNamesOnlyDepartmentsAndNeverAFigure(): void
    {
        $encoded = json_encode($this->tool()(term: 'zzzznotathing'), \JSON_THROW_ON_ERROR);

        foreach (['price', 'stock', 'delivery', 'currency'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $encoded);
        }
    }

    private function tool(): SearchProductsTool
    {
        return ToolBuilder::over(FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json'));
    }
}
