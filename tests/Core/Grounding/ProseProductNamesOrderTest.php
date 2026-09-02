<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Grounding\ProseProductNames;

/**
 * Cards follow the order the reply names them, not the order names are searched in.
 *
 * ## The defect
 *
 * Measured on the staging shop, 2026-09-02. Asked *"show me the trail jersey in black, size M"*, the
 * reply was right — *"The Trail Jersey in Black, size M is available and in stock. If you need
 * cooler-weather riding options…, the Thermal Jersey Long Sleeve is also available."* — and the cards
 * came back **Thermal Jersey Long Sleeve first, Trail Jersey second**.
 *
 * Nothing was wrong with the selection; only the order. Names must be SEARCHED longest first, or
 * `Chain` matches inside `Wet Chain Lube 100ml`, and this returned ids in that same search order. So
 * the product with the longest name led the shortlist whatever the reply was about — and the widget's
 * first card is the one an end-to-end test, and a shopper in a hurry, reaches for first.
 *
 * The end-to-end suite caught it by adding the wrong jersey to a cart.
 */
final class ProseProductNamesOrderTest extends TestCase
{
    private const NAMES = [
        'id-trail' => 'Trail Jersey',
        'id-thermal' => 'Thermal Jersey Long Sleeve',
    ];

    /** The measured reply, shortened to the two sentences that name the products. */
    public function testTheFirstProductNamedIsTheFirstRendered(): void
    {
        $ids = ProseProductNames::idsNamedIn(
            'The Trail Jersey in Black, size M is available. The Thermal Jersey Long Sleeve is also available.',
            self::NAMES,
        );

        self::assertSame(['id-trail', 'id-thermal'], $ids);
    }

    /** And the other way round, so the assertion above is about the prose and not about the map. */
    public function testTheOrderFollowsTheProseWhenItIsReversed(): void
    {
        $ids = ProseProductNames::idsNamedIn(
            'The Thermal Jersey Long Sleeve is warmest. The Trail Jersey is the lighter one.',
            self::NAMES,
        );

        self::assertSame(['id-thermal', 'id-trail'], $ids);
    }

    /**
     * Ordering must not cost the longest-first search. `Chain` sits inside `Wet Chain Lube 100ml`, so
     * a reply about the lubricant must still render the lubricant and never the chain.
     */
    public function testAShortNameInsideALongerOneIsStillNotMatched(): void
    {
        $ids = ProseProductNames::idsNamedIn('I recommend the Wet Chain Lube 100ml for winter.', [
            'id-chain' => 'Chain',
            'id-lube' => 'Wet Chain Lube 100ml',
        ]);

        self::assertSame(['id-lube'], $ids);
    }

    /** Both, in prose order, when a reply really does name the short one on its own. */
    public function testAShortNameStandingAloneKeepsItsPlaceInTheOrder(): void
    {
        $ids = ProseProductNames::idsNamedIn('A Chain is cheap; Wet Chain Lube 100ml keeps it running.', [
            'id-chain' => 'Chain',
            'id-lube' => 'Wet Chain Lube 100ml',
        ]);

        self::assertSame(['id-chain', 'id-lube'], $ids);
    }
}
