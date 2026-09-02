<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Tool\SearchTermList;
use Swag\AssistantStarterKit\Core\Tool\ToolArgumentException;

/**
 * The bound counts `term` and `terms` together, and until 2026-09-02 the model-facing schema did not
 * say so.
 *
 * `SearchProductsTool`'s `@param` read "Up to 3 search terms" on `terms` alone, so a model following
 * it literally — `term: "trikot"` plus `terms: [a, b, c]` — lands on four and is rejected, with a
 * message naming `terms` for an overflow that came from `term`. Measured live on 2026-09-02 with
 * `openai/gpt-5-mini`: `tool.arguments.rejected`, then the model gave up and the turn rendered
 * nothing.
 *
 * The bound itself is unchanged — three retrieval passes is a cost ceiling, and "reject, never
 * coerce" stays (see the class docblock). What changes is that the contract now describes it.
 */
#[CoversClass(SearchTermList::class)]
final class SearchTermListBoundTest extends TestCase
{
    public function testTheSingleTermCountsTowardsTheBound(): void
    {
        // Three in `terms` plus one in `term` is four, which is what the old schema invited.
        $this->expectException(ToolArgumentException::class);

        SearchTermList::of('trikot', ['jacket', 'gloves', 'helmet'], 'terms');
    }

    public function testTheMessageNamesTheTotalAndTheOtherArgument(): void
    {
        try {
            SearchTermList::of('trikot', ['jacket', 'gloves', 'helmet'], 'terms');
            self::fail('Expected the bound to be enforced.');
        } catch (ToolArgumentException $e) {
            // A model that reads only the message must be able to fix the call from it alone.
            self::assertStringContainsString('3', $e->getMessage());
            self::assertStringContainsString('in total', $e->getMessage());
            self::assertStringContainsString('term', $e->getMessage());
        }
    }

    public function testThreeInTotalIsAccepted(): void
    {
        self::assertSame(['trikot', 'jacket', 'gloves'], SearchTermList::of('trikot', ['jacket', 'gloves'], 'terms'));
    }

    /**
     * The de-duplication runs before the count, so a repeated term does not consume budget — a
     * model that names the same thing twice is not asking for more work.
     */
    public function testADuplicateDoesNotConsumeTheBudget(): void
    {
        self::assertSame(
            ['trikot', 'jacket', 'gloves'],
            SearchTermList::of('trikot', ['Trikot', 'jacket', 'gloves'], 'terms'),
        );
    }
}
