<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\Tests\Support\BuildsCartTool;

/**
 * A variant nobody chose must not reach the cart.
 *
 * ## The defect
 *
 * Reported from a live shop on 2026-09-02: *"when I ask for adding items to the basket the agent
 * just adds them, although e.g. the jersey has 3 different variants. I asked for a jersey, it says
 * oh yes we have this variants. Then asking to add in the basket and it just adds one randomly
 * without asking which one specific I want — this could lead to wrong orders."*
 *
 * {@see AddToCartToolFamilyTest} already covers the neighbouring case: a family PARENT is refused
 * because it is not a sellable unit. This is the one it does not cover, and the more dangerous of
 * the two — a concrete variant id is a perfectly valid sellable unit, so the tool accepted it and
 * the cart line was real. It was simply the wrong colour, and the shopper found out after ordering.
 *
 * Nothing in the pipeline enforced the question. `search_products` hands the model every variant's
 * id and option values, and whether the shopper was asked which one they wanted came down to the
 * model reading one sentence of tool description — against D6, which says capability control is
 * toolbox construction and never a prompt instruction. Two live runs on 2026-09-02 happened to ask
 * politely; that is a sampled outcome, not a guarantee, and a wrong order is not a defect a merchant
 * gets to discover twice.
 *
 * ## What is asserted
 *
 * The rule is that a variant belonging to a family may only be added together with option values
 * that identify **that** variant, resolved through
 * {@see \Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface::resolveVariant()} — the
 * primitive {@see \Swag\AssistantStarterKit\Core\Grounding\VariantResolver} already trusts never to
 * guess. So the model cannot satisfy this by fabricating: options for the wrong variant resolve to
 * the wrong variant and are refused, and options that narrow to more than one resolve to null.
 *
 * `fx-026` is the fixture's one real family — Blue/M, Blue/L, Black/M — which is why every case
 * below uses it.
 */
final class AddToCartToolVariantChoiceTest extends TestCase
{
    use BuildsCartTool;

    /**
     * `$trace` is the trait's own property, assigned here for the reason
     * {@see AddToCartToolFamilyTest} gives: the analyzer cannot see that
     * {@see BuildsCartTool::cartTool()} always replaces it before an assertion reads it.
     *
     * @param non-empty-string $name
     */
    public function __construct(string $name)
    {
        parent::__construct($name);

        $this->trace = new TraceRecorder();
    }

    public function testAVariantOfAFamilyIsRefusedWhenNoOptionsWereChosen(): void
    {
        // The reported bug, as one call: a valid variant id, no statement of what the shopper
        // picked. Blue/L is in stock and would have gone straight into the cart.
        $result = $this->cartTool()(variantId: 'fx-026-blue-l', quantity: 1);

        self::assertArrayNotHasKey('cart', $result);
        self::assertStringContainsString('which', strtolower($result['note']));
    }

    public function testNothingReachesTheCartWhenTheChoiceIsMissing(): void
    {
        // Asserting the note alone would pass against a tool that refuses politely and adds anyway
        // — the same trap AddToCartToolFamilyTest guards against for its own refusal.
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');

        $this->cartTool($gateway)(variantId: 'fx-026-blue-l', quantity: 1);

        self::assertSame(0, $gateway->cart()->itemCount);
    }

    public function testTheOptionsTheShopperChoseLetTheVariantThrough(): void
    {
        // The demo sentence — "the trail jersey in blue, size L" then "add that to my cart" — must
        // still work in one call. The model copies these values verbatim out of the search result.
        $result = $this->cartTool()(variantId: 'fx-026-blue-l', quantity: 2, options: [
            ['Colour', 'Blue'],
            ['Size', 'L'],
        ]);

        self::assertArrayHasKey('cart', $result);
        self::assertSame('Added 2 to the cart.', $result['note']);
    }

    public function testABareOptionValueWithNoGroupIsEnoughWhenItIdentifiesTheVariant(): void
    {
        // `search_products` documents the same shorthand, and a model that does not know an
        // option's group must not be pushed into guessing one — a wrong group name would be
        // dropped, and a dropped constraint here is a refusal rather than a wrong cart line.
        $result = $this->cartTool()(variantId: 'fx-026-blue-l', quantity: 1, options: ['Blue', 'L']);

        self::assertArrayHasKey('cart', $result);
    }

    public function testOptionsForADifferentVariantOfTheSameFamilyAreRefused(): void
    {
        // This is what makes fabrication self-defeating rather than merely discouraged. Black/M is
        // a real variant of this family, so the resolution succeeds — it just does not identify the
        // unit being added, and adding it anyway is the wrong-colour order the shopper reported.
        $result = $this->cartTool()(variantId: 'fx-026-blue-l', quantity: 1, options: [
            ['Colour', 'Black'],
            ['Size', 'M'],
        ]);

        self::assertArrayNotHasKey('cart', $result);
    }

    public function testOptionsThatNarrowToMoreThanOneVariantAreRefused(): void
    {
        // "Blue" alone matches Blue/M and Blue/L, so `resolveVariant()` returns null rather than
        // picking one. A shopper who said only "the blue one" has not chosen a size, and this is
        // the case where guessing costs the most: Blue/M is sold out.
        $result = $this->cartTool()(variantId: 'fx-026-blue-l', quantity: 1, options: [['Colour', 'Blue']]);

        self::assertArrayNotHasKey('cart', $result);
    }

    public function testAStandaloneProductNeedsNoChoiceAtAll(): void
    {
        // The guard must refuse only what it was meant to. `fx-007` has no parent, so there is no
        // sibling it could be confused with and nothing for the shopper to have picked.
        $result = $this->cartTool()(variantId: 'fx-007', quantity: 1);

        self::assertArrayHasKey('cart', $result);
    }

    public function testEachRefusalIsTraceableUnderItsOwnReasonCode(): void
    {
        // A merchant reading a trace has to tell "the model never asked" from "the model named a
        // different variant than the one it added" — the first is a missing conversation, the
        // second is the model contradicting itself, and they are fixed in different places.
        $this->cartTool()(variantId: 'fx-026-blue-l', quantity: 1);
        self::assertContains('variant_not_chosen', $this->cartReasonCodes());

        $this->cartTool()(variantId: 'fx-026-blue-l', quantity: 1, options: [['Colour', 'Black'], ['Size', 'M']]);
        self::assertContains('variant_mismatch', $this->cartReasonCodes());
    }
}
