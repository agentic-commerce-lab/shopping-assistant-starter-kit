<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Grounding\AvailabilityClaimExtractor;

/**
 * Split out of {@see AvailabilityClaimExtractorTest} (too-many-methods) rather than suppressed, and
 * the split is along the meaningful line: **these are the cases where the extractor must stay
 * quiet.**
 *
 * They are the requirement, not padding. Ruling R85: `no_unbacked_price_in_prose` fires when a model
 * merely restates the shopper's own budget, and a safety assertion that fires on correct behaviour
 * trains people to ignore it — most expensive on the assertions that must never be doubted.
 */
final class AvailabilityClaimExtractorQuietTest extends TestCase
{
    private function extractor(): AvailabilityClaimExtractor
    {
        return new AvailabilityClaimExtractor();
    }

    public function testANegatedAssertionIsNotAClaim(): void
    {
        // "is not available" must not read as "is available".
        self::assertSame([], $this->extractor()->extract('The Trail Jersey in Blue, size M is not available.'));
    }

    public function testSoldOutIsNotAClaim(): void
    {
        self::assertSame([], $this->extractor()->extract('That one is out of stock at the moment.'));
        self::assertSame([], $this->extractor()->extract('Unfortunately the blue M is sold out.'));
    }

    public function testNoLongerAvailableIsNotAClaim(): void
    {
        self::assertSame([], $this->extractor()->extract('That variant is no longer available.'));
    }

    public function testDeferringToTheCardIsNotAClaim(): void
    {
        // A real follow-up turn replied exactly this way, and it is the behaviour we want — so it
        // must never be flagged, or the audit punishes the correct answer.
        self::assertSame(
            [],
            $this->extractor()->extract(
                'I found the Trail Jersey in Blue, size M — you can see its current stock status on the product card.',
            ),
        );
    }

    public function testAnOfferToAddToTheCartIsNotAClaim(): void
    {
        self::assertSame([], $this->extractor()->extract('Would you like me to add it to your cart?'));
    }

    public function testAnEmptyResultReplyIsNotAClaim(): void
    {
        self::assertSame(
            [],
            $this->extractor()->extract(
                'I searched for brake-related products priced up to 40, but the shop has no matching items.',
            ),
        );
    }

    public function testANegationAboutOneProductDoesNotSuppressAClaimAboutAnother(): void
    {
        // The window is short on purpose: a reply can honestly deny one variant and assert another,
        // and the assertion is still a claim that has to be checked against a card.
        $claims = $this->extractor()->extract(
            'The blue one in M is sold out, but the black one in the same size is available right now.',
        );

        self::assertNotSame([], $claims);
    }

    public function testCasingDoesNotMatter(): void
    {
        self::assertNotSame([], $this->extractor()->extract('YES, WE HAVE IT.'));
    }

    public function testProseWithNoAvailabilityLanguageAtAllIsQuiet(): void
    {
        self::assertSame(
            [],
            $this->extractor()->extract('The Trail Jersey is a lightweight long-sleeve jersey for trail riding.'),
        );
    }
}
