<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\Facet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;
use Swag\AssistantStarterKit\Core\Grounding\DescriptionAudit;

/**
 * A scope-of-delivery claim written as a **list**, which is how a bundle is always answered.
 *
 * The audit's grammar was built from prose in the trace export — *"comes with the included
 * bracket"*, *"rated for high-security use"* — and every marker there was followed by the thing
 * claimed. Ask about a bundle and the model writes the other shape instead:
 *
 * > The **Drivetrain Care Bundle** includes:
 * > - **Chain lube 120 ml** (11-speed)
 * > - **Gear brush**
 *
 * Measured live on 2026-09-10: the catalogue holds no product whose name contains "brush" and the
 * lube is 100 ml, so both lines are inventions — and the audit reported nothing, because the phrase
 * pattern's separator class was `[\s,]+`. A colon is not whitespace or a comma, so the four-word
 * window closed immediately after the marker and the claim it extracted was the bare word
 * `includes`, with no noun in it to check against anything.
 *
 * **Each list item is its own claim.** Reporting only the first would flag this reply but pass a
 * reply whose first item is real and whose second is invented, and a merchant reading the trace
 * needs the item that was wrong rather than the one that happened to be first.
 */
final class DescriptionAuditListClaimTest extends TestCase
{
    private const REPLY = "The Drivetrain Care Bundle includes:\n- Chain lube 120 ml\n- Gear brush";

    /**
     * The bundle's own description, which describes what the set is FOR and never enumerates it —
     * the realistic case, because Shopware renders the item list from `bundle_item` and a merchant
     * has no reason to repeat it in prose.
     */
    private const DESCRIPTION =
        'Keeps a drivetrain clean and says when the chain is finished, sized for a single 11-speed '
            . 'service. The wear indicator and the bike wash are optional additions.';

    private static function facets(): FacetSet
    {
        return new FacetSet([
            new Facet('properties.Terrain', FacetType::Terms, ['Road', 'Gravel', 'Trail', 'Commuting']),
            new Facet('properties.Material', FacetType::Terms, ['Steel', 'Alloy', 'Carbon', 'Nylon']),
        ]);
    }

    /**
     * @param list<string> $shopProse
     *
     * @return list<string>
     */
    private function audit(string $prose, array $shopProse): array
    {
        return (new DescriptionAudit())->unsupportedFactClaims($prose, $shopProse, self::facets());
    }

    public function testAnItemTheShopsProseNeverMentionsIsReported(): void
    {
        $found = $this->audit(self::REPLY, [self::DESCRIPTION]);

        self::assertContains('Gear brush', $found);
    }

    /**
     * **And the other invented item is not reported, which is a limit worth stating.**
     *
     * The lube in this bundle is 100 ml, so *"Chain lube 120 ml"* is as invented as the brush. It
     * survives because {@see DescriptionAudit::supported()} calls a claim supported when **any** one
     * of its content tokens is asserted, and `chain` is asserted — the description says the set
     * *"says when the chain is finished"*. That leniency is deliberate and measured: it is what took
     * the audit from 15 findings with 4 true ones down to 4 findings all true, and tightening it to
     * require every token would re-open false positives across the whole corpus this detector was
     * tuned on. Extracting the item at all is this change's business; how strictly a claim is then
     * judged is not, and the wrong place to decide it is a bundle test.
     */
    public function testAPartiallySupportedItemIsLeftToTheExistingSupportRule(): void
    {
        $found = $this->audit(self::REPLY, [self::DESCRIPTION]);

        self::assertNotContains('Chain lube 120 ml', $found);
    }

    public function testAContentsListTheShopItselfAssertsIsClean(): void
    {
        // What the bundle's own `bundle_item` rows say, rendered as the sentence the server hands
        // over. Once the contents are supplied, restating them is not an invention.
        $found = $this->audit("The Roadside Repair Kit includes:\n- Mini Pump 120psi\n- Tyre Lever Set", [
            'The Roadside Repair Kit is supplied with 1 × Mini Pump 120psi and 1 × Tyre Lever Set.',
        ]);

        self::assertSame([], $found, 'Items the server supplied must not be reported as invented.');
    }

    /**
     * **A false positive measured live on 2026-09-10, on the deployed fix's own first turn.**
     *
     * Told that a bundle's price covers its optional items, the model correctly wrote *"It includes
     * all items, even the optional ones, so this is the most you would pay"* — and the audit
     * reported `includes all items, even` as an unsupported delivery claim. The words it checked
     * were `items` and `even`: the generic noun for a bundle member and a filler. Neither names a
     * product, so neither can be found in a shop's prose, so the claim could never be supported no
     * matter what the shop said.
     *
     * That is the failure ruling R85 describes exactly — a control firing on right behaviour — and
     * it would have fired on the very sentence this change asks the model to write. `item` joins
     * the markers in {@see \Swag\AssistantStarterKit\Core\Grounding\ClaimTokens}' noise list for
     * the same reason they are there: `supplied` and `included` are the grammar of a claim rather
     * than its content, and `items` is the grammar of a bundle.
     */
    public function testStatingThatThePriceCoversEveryItemIsNotAProductClaim(): void
    {
        $found = $this->audit('It includes all items, even the optional ones, so this is the most you would pay.', [
            'Drivetrain Care Bundle is supplied with Dry Chain Lube 100ml, Bike Wash 1L.',
        ]);

        self::assertSame([], $found);
    }

    public function testAColonThatIntroducesNoListIsUnaffected(): void
    {
        // Regression guard on the separator widening: admitting `:` must not turn ordinary prose
        // into a list, nor drag the words after an unrelated colon into a claim.
        $found = $this->audit('It comes with two keys, and no bracket is supplied.', [
            'A 90 cm chain supplied with two keys and no bracket.',
        ]);

        self::assertSame([], $found);
    }
}
