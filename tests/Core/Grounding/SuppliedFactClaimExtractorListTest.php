<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Grounding\SuppliedFactClaimExtractor;

/**
 * A delivery marker followed by a **list**, which is how any question about a bundle gets answered.
 *
 * Every marker in the trace export was followed by the thing claimed — *"comes with the included
 * bracket"* — so the phrase pattern collected the words after the marker with a separator class of
 * `[\s,]+`. Ask what is in a bundle and the model writes the other shape:
 *
 * > The Drivetrain Care Bundle includes:
 * > - Chain lube 120 ml
 * > - Gear brush
 *
 * A colon is neither whitespace nor a comma, so the window shut immediately and the whole extracted
 * claim was the bare word `includes` — no noun in it, so {@see \Swag\AssistantStarterKit\Core\Grounding\DescriptionAudit}
 * discarded it as a claim attached to nothing. Measured live 2026-09-10 against a bundle whose real
 * contents are a 100 ml lube, a degreaser and a cassette tool: the catalogue contains no product
 * whose name holds "brush", and nothing was reported.
 *
 * **One claim per item, not one for the list.** Reporting only the first item would flag that reply
 * but pass one whose first item is real and second invented — and the merchant needs the item that
 * was wrong, not the one that came first.
 */
final class SuppliedFactClaimExtractorListTest extends TestCase
{
    private function extract(string $text): array
    {
        return (new SuppliedFactClaimExtractor())->extract($text);
    }

    public function testEachItemOfADashedListBecomesItsOwnClaim(): void
    {
        $claims = $this->extract("The Drivetrain Care Bundle includes:\n- Chain lube 120 ml\n- Gear brush");

        self::assertContains('Chain lube 120 ml', $claims);
        self::assertContains('Gear brush', $claims);
    }

    public function testAStarredListIsReadTheSameWay(): void
    {
        // Markdown's other bullet, and the model uses both.
        $claims = $this->extract("It comes with:\n* a frame bracket\n* two keys");

        self::assertContains('a frame bracket', $claims);
        self::assertContains('two keys', $claims);
    }

    public function testTheItemWindowStopsAtFourWordsLikeTheProseOne(): void
    {
        // Same bound and the same reason as WORDS_AFTER: past four words the claim's own noun gets
        // diluted by the rest of the line, and a wider window reports a claim as supported on the
        // strength of words the shop's prose merely happens to contain.
        $claims = $this->extract("Includes:\n- a hardened steel chain with a fabric sleeve and two keys");

        self::assertContains('a hardened steel chain', $claims);
    }

    public function testProseAfterAMarkerIsStillReadAsProse(): void
    {
        // The shape the class was built for must be untouched by the list handling.
        self::assertSame(['comes with the included bracket'], $this->extract('It comes with the included bracket.'));
    }

    public function testANumberedListIsReadTheSameWay(): void
    {
        // The form a model reaches for most readily when it enumerates.
        $claims = $this->extract("The kit includes:\n1. a mini pump\n2. two tyre levers");

        self::assertContains('a mini pump', $claims);
        self::assertContains('two tyre levers', $claims);
    }

    public function testMarkdownEmphasisAroundAnItemIsNotPartOfTheClaim(): void
    {
        // Every measured reply bolds its item names, and `**Gear` is not a word the shop's prose
        // could ever assert.
        $claims = $this->extract("It includes:\n- **Gear brush**\n- **Chain lube 120 ml** (11-speed)");

        self::assertContains('Gear brush', $claims);
    }

    public function testAMarkerIntroducingNoListAddsNoItemClaims(): void
    {
        // The bare marker is still returned, as it always was, and DescriptionAudit still discards
        // it: a claim with no content words attaches its grammar to nothing checkable, which that
        // class documents and {@see \Swag\AssistantStarterKit\Core\Grounding\ClaimTokens} enforces.
        // What matters here is that the list pass invents no item where there is no list.
        self::assertSame(['includes'], $this->extract('Everything it includes:'));
    }
}
