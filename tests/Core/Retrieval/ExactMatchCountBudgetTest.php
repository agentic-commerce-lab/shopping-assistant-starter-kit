<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Retrieval;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\MatchCountReader;
use Swag\AssistantStarterKit\Core\Retrieval\ExactMatchCount;
use Swag\AssistantStarterKit\Core\Retrieval\IntentCandidates;
use Swag\AssistantStarterKit\Core\Retrieval\QueryBuildResult;

/**
 * The exact count is withheld once a stated budget had to be enforced after the query.
 *
 * The gateway counts with a SQL aggregation over the search's own criteria, so a `price` range there is
 * counted on the list price. Measured 2026-09-01 as a B2B customer with a +50% surcharge: 29 of 32
 * candidates were over budget once their real prices were known, and the aggregation still returned 33
 * — which the model reported as "quite a few more matching that price (33 in total)".
 */
final class ExactMatchCountBudgetTest extends TestCase
{
    private static function gateway(int $count): object
    {
        return new class($count) implements MatchCountReader {
            public function __construct(
                private readonly int $count,
            ) {}

            public function countMatches(ProductQuery $query, CatalogScope $scope): int
            {
                return $this->count;
            }
        };
    }

    private static function candidates(bool $budgetNarrowed): IntentCandidates
    {
        return new IntentCandidates(
            cards: [],
            note: null,
            buildResult: new QueryBuildResult(new ProductQuery(term: 'jersey'), [], [], []),
            windowSaturated: false,
            budgetNarrowed: $budgetNarrowed,
        );
    }

    public function testAnUnnarrowedSearchStillGetsItsExactCount(): void
    {
        self::assertSame(33, ExactMatchCount::of(self::gateway(33), [self::candidates(false)], new CatalogScope()));
    }

    public function testTheCountIsWithheldWhenTheBudgetHadToBeEnforced(): void
    {
        self::assertNull(ExactMatchCount::of(self::gateway(33), [self::candidates(true)], new CatalogScope()));
    }

    /** One narrowed term is enough: the count returned is the largest across terms, so it is tainted. */
    public function testOneNarrowedTermWithholdsTheCountForAllOfThem(): void
    {
        self::assertNull(ExactMatchCount::of(
            self::gateway(33),
            [self::candidates(false), self::candidates(true)],
            new CatalogScope(),
        ));
    }
}
