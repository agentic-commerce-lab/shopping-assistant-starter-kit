<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartNoticeReason;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Tool\AddToCartTool;
use Swag\AssistantStarterKit\Core\Tool\CartCorrectionNote;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Adding a bundle to the cart, and why the quantity arithmetic cannot describe it.
 *
 * ## What Shopware actually does
 *
 * Dumped from the live cart on 2026-09-10 after the assistant added the Roadside Repair Kit — a
 * bundle of four members, one of them at quantity two:
 *
 * ```
 * token ykShIpFshh1…  total 73.08  lines 4
 * product qty=1 ref=0268a39c…  label=Mini Pump 120psi
 *     discount qty=1 ref=01a08b4969f1…  label=Roadside Repair Kit
 * product qty=1 ref=6153b705…  label=Multi-Tool 12
 *     discount qty=1 …
 * product qty=1 ref=a2b2da3b…  label=Tyre Lever Set
 *     discount qty=1 …
 * product qty=2 ref=cfe94451…  label=Inner Tube Presta 700c
 *     discount qty=2 …
 * ```
 *
 * The cart total is the bundle price, and **no line item carries the bundle's own product id** —
 * a bundle enters the cart as its members, each with a discount child named after the bundle.
 *
 * ## The bug this pins
 *
 * `CartCorrectionNote::lineQuantity()` looks for a line whose `variantId` matches what was added,
 * so for a bundle it can only ever return 0. `AddToCartTool` read that as "Shopware stored none of
 * it" and reported **"Nothing was added; the shop adjusted the quantity."** — on a **successful**
 * add. Measured live: the shopper was told *"The Roadside Repair Kit is already in your cart, and
 * the quantity has not changed"*, in a session whose cart had just been created, over a cart holding
 * five items worth €73.08. Two false claims out of one absent line.
 *
 * A bundle therefore gets a note of its own rather than a corrected count. There is nothing to
 * correct: Shopware did not adjust a quantity, it expanded one line into several, and the honest
 * report is what the shopper will see on the cart page.
 */
final class AddToCartBundleNoteTest extends TestCase
{
    private const BUNDLE_ID = 'roadsiderepairkit0000000000000';

    private string $catalogue = '';

    protected function setUp(): void
    {
        $this->catalogue = tempnam(sys_get_temp_dir(), 'cartbundle') . '.json';
        file_put_contents(
            $this->catalogue,
            json_encode(
                [
                    'products' => [[
                        'id' => self::BUNDLE_ID,
                        'name' => 'Roadside Repair Kit',
                        'description' => 'Everything needed to fix a puncture at the roadside.',
                        'price' => 73.08,
                        'stock' => 19,
                        'url' => '/p/roadside-repair-kit',
                        'categoryPath' => ['Maintenance'],
                        'properties' => [],
                        'variants' => [],
                        'bundleItems' => [
                            ['name' => 'Mini Pump 120psi', 'quantity' => 1, 'required' => true],
                            ['name' => 'Inner Tube Presta 700c', 'quantity' => 2, 'required' => true],
                        ],
                    ]],
                ],
                \JSON_THROW_ON_ERROR,
            ),
        );
    }

    protected function tearDown(): void
    {
        if ($this->catalogue !== '' && is_file($this->catalogue)) {
            unlink($this->catalogue);
        }
    }

    public function testTheNoteSaysTheBundleWentInAndHowItWillLook(): void
    {
        $note = CartCorrectionNote::bundleText('Roadside Repair Kit');

        self::assertStringContainsString('Roadside Repair Kit', $note);
        self::assertStringContainsString('individual items', $note);
    }

    public function testTheBundleNoteNeverClaimsNothingWasAdded(): void
    {
        // The measured failure, stated as an assertion: this exact wording is what the model
        // turned into "already in your cart, and the quantity has not changed".
        self::assertStringNotContainsString('Nothing was added', CartCorrectionNote::bundleText('Roadside Repair Kit'));
    }

    public function testAddingABundleReportsTheBundleNoteRatherThanACorrectedCount(): void
    {
        $trace = new TraceRecorder();
        $tool = new AddToCartTool(
            FixtureCommerceGateway::fromFile($this->catalogue),
            new BlocklistFilter(),
            new FactRenderer($trace),
            $trace,
            new AssistantConfig(),
        );

        $result = $tool(self::BUNDLE_ID, 1);

        self::assertSame(CartCorrectionNote::bundleText('Roadside Repair Kit'), $result['note'] ?? null);
    }

    public function testAnOrdinaryProductStillReportsWhatShopwareStored(): void
    {
        // The correction arithmetic is right for everything that occupies one line, and this is the
        // guard against a bundle carve-out swallowing it.
        self::assertSame('Added 1 to the cart.', CartCorrectionNote::text(1, 1, null));
        self::assertSame('Nothing was added: the product is out of stock.', CartCorrectionNote::text(
            0,
            1,
            CartNoticeReason::OutOfStock,
        ));
    }
}
