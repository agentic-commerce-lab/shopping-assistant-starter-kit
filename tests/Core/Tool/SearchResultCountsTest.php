<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Tool\SearchResultCounts;
use Swag\AssistantStarterKit\Core\Tool\WithheldCount;
use Swag\AssistantStarterKit\Tests\Support\BuildsProductCards;

/**
 * The counted fields a search hands the model, and the one that was missing.
 *
 * Three replies in the September 2026 review presented a fraction of a result set as the whole of
 * it — three of sixteen helmets, one of thirty, three of forty-nine — while `matched` sat in the
 * same reply saying otherwise. Both numbers were there; the subtraction was not.
 *
 * That `withheld` counts PRODUCTS rather than rows is decided in {@see WithheldCountsProductsTest}.
 */
#[CoversClass(SearchResultCounts::class)]
#[CoversClass(WithheldCount::class)]
final class SearchResultCountsTest extends TestCase
{
    use BuildsProductCards;

    public function testItStatesWhatIsNotBeingShown(): void
    {
        $counts = SearchResultCounts::of(
            self::summaries(),
            returned: self::standalone(3),
            survivors: self::standalone(16),
            saturated: false,
        );

        self::assertSame(3, $counts['total']);
        self::assertSame(16, $counts['matched']);
        self::assertSame(13, $counts['withheld'] ?? null);
    }

    /**
     * A field reading `withheld: 0` on every complete answer is context the model pays to read on
     * the turns where it means nothing — and it invites a sentence about withholding on a reply that
     * withheld nothing.
     */
    public function testACompleteAnswerCarriesNoWithheldFieldAtAll(): void
    {
        $counts = SearchResultCounts::of(
            self::summaries(),
            returned: self::standalone(3),
            survivors: self::standalone(3),
            saturated: false,
        );

        self::assertArrayNotHasKey('withheld', $counts);
    }

    /**
     * Ruling T3: `total` means "how many are in products", and it is not renamed however badly it
     * reads. The model has learned it.
     */
    public function testTotalStillMeansHowManyAreBeingShown(): void
    {
        $counts = SearchResultCounts::of(
            self::summaries(),
            returned: self::standalone(8),
            survivors: self::standalone(279),
            saturated: true,
        );

        self::assertSame(8, $counts['total']);
        self::assertSame(271, $counts['withheld'] ?? null);
    }

    /**
     * An exact count from the gateway is authoritative for `matched`, and it makes "matched is a
     * floor" false (T4). `withheld` still comes from the survivor list, which is the only place the
     * product identities are — so beside a saturated window it is a floor, and `more` says so.
     */
    public function testAnExactCountWinsForMatched(): void
    {
        $counts = SearchResultCounts::of(
            self::summaries(),
            returned: self::standalone(3),
            survivors: self::standalone(50),
            saturated: true,
            exact: 61,
        );

        self::assertSame(61, $counts['matched']);
        self::assertFalse($counts['more'], 'An exact count makes "matched is a floor" false (T4).');
        self::assertSame(47, $counts['withheld'] ?? null);
    }

    public function testASaturatedWindowWithoutAnExactCountStillSaysMatchedIsAFloor(): void
    {
        $counts = SearchResultCounts::of(
            self::summaries(),
            returned: self::standalone(3),
            survivors: self::standalone(50),
            saturated: true,
        );

        self::assertTrue($counts['more']);
    }

    public function testWithheldIsNeverNegative(): void
    {
        self::assertSame([], WithheldCount::replyFor(self::standalone(2), self::standalone(3)));
    }
}
