<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Tool\EverythingShown;
use Swag\AssistantStarterKit\Core\Tool\SearchResultCounts;
use Swag\AssistantStarterKit\Tests\Support\BuildsProductCards;

/**
 * The one condition under which the assistant may tell a shopper there is nothing more.
 *
 * The prompt permits that sentence so a shopper asking "more please" gets an honest answer instead
 * of the same two products presented as new. A permission with no condition on it would replace one
 * untrue answer with another, and the condition needs two facts the model would have to combine
 * itself — which is the arithmetic the September 2026 review measured it failing.
 */
#[CoversClass(EverythingShown::class)]
#[CoversClass(SearchResultCounts::class)]
final class EverythingShownTest extends TestCase
{
    use BuildsProductCards;

    public function testAnExhaustedSearchMaySaySo(): void
    {
        $counts = SearchResultCounts::of(
            self::summaries(),
            returned: self::variantsOf(families: 4, each: 1),
            survivors: self::variantsOf(families: 4, each: 3),
            saturated: false,
        );

        self::assertTrue($counts['all_shown'] ?? null);
        self::assertStringContainsString('every product this search matched', $counts['all_shown_note'] ?? '');
    }

    /**
     * Products nobody has seen: there is obviously more, and the field must be absent.
     */
    public function testAWithheldProductWithholdsThePermissionToo(): void
    {
        $counts = SearchResultCounts::of(
            self::summaries(),
            returned: self::variantsOf(families: 3, each: 1),
            survivors: self::variantsOf(families: 5, each: 1),
            saturated: false,
        );

        self::assertArrayNotHasKey('all_shown', $counts);
    }

    /**
     * **The case the model could not have worked out, and the reason this class exists.** Every
     * matching product in the survivor list is on screen, so `withheld` is absent — but the
     * candidate window filled up, so whole families may exist beyond it that retrieval never saw.
     * `matched` is a floor here, not a census (ruling T4).
     */
    public function testASaturatedWindowRefusesThePermissionEvenWithNothingWithheld(): void
    {
        $counts = SearchResultCounts::of(
            self::summaries(),
            returned: self::variantsOf(families: 4, each: 1),
            survivors: self::variantsOf(families: 4, each: 3),
            saturated: true,
        );

        self::assertArrayNotHasKey('withheld', $counts);
        self::assertTrue($counts['more']);
        self::assertArrayNotHasKey('all_shown', $counts, 'a floor is not a census');
    }

    /**
     * An exact count from the gateway settles the total, so a saturated window no longer hides
     * anything — `more` is false and the permission is available again.
     */
    public function testAnExactCountRestoresThePermission(): void
    {
        $counts = SearchResultCounts::of(
            self::summaries(),
            returned: self::variantsOf(families: 4, each: 1),
            survivors: self::variantsOf(families: 4, each: 3),
            saturated: true,
            exact: 12,
        );

        self::assertFalse($counts['more']);
        self::assertTrue($counts['all_shown'] ?? null);
    }

    /**
     * It says "this search", never "the shop". An exhausted search establishes only that these words
     * matched nothing further — the distinction `NO_MATCH_NOTE` exists to protect.
     */
    public function testTheNoteNeverClaimsSomethingAboutTheAssortment(): void
    {
        self::assertStringContainsString('never about the shop', EverythingShown::NOTE);
        self::assertStringNotContainsString('does not sell', EverythingShown::NOTE);
    }
}
