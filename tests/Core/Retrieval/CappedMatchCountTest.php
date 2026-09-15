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
 * Counting that gives up once the answer is "very many", and what the caller is told instead.
 *
 * **The measurement this exists for**, on a 118,232-product shop, 2026-09-15: counting every match of
 * `kette` took 1,640 ms and ran once per search candidate, uncached, on every search. The cost
 * follows the size of the match set, so it is cheap exactly when the number is small — which is
 * exactly when the number is worth having. *"There are 3 more"* changes what a shopper does;
 * *"there are 4,812 more"* says what *"very many"* says.
 */
final class CappedMatchCountTest extends TestCase
{
    private function candidate(): IntentCandidates
    {
        return new IntentCandidates(
            cards: [],
            note: null,
            buildResult: new QueryBuildResult(new ProductQuery(term: 'kette'), [], [], []),
            windowSaturated: false,
            budgetNarrowed: false,
        );
    }

    /** A gateway that can only count the old way. */
    private function plain(int $count): MatchCountReader
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

    /**
     * Below the cap nothing changes: the figure is exact and the assistant may state it.
     */
    public function testASmallCountStaysExact(): void
    {
        $count = ExactMatchCount::of(new CountingMatchGateway(12), [$this->candidate()], new CatalogScope());

        self::assertNotNull($count);
        self::assertSame(12, $count->count);
        self::assertFalse($count->capped);
    }

    /**
     * At the cap the caller learns only that it is large — never a figure the shop cannot stand
     * behind.
     */
    public function testAHugeCountComesBackCapped(): void
    {
        $count = ExactMatchCount::of(new CountingMatchGateway(4812), [$this->candidate()], new CatalogScope());

        self::assertNotNull($count);
        self::assertSame(ExactMatchCount::CAP, $count->count);
        self::assertTrue($count->capped);
    }

    /**
     * Exactly at the cap is indistinguishable from beyond it, and must be treated as beyond — the
     * safe direction, since the reply says "very many" either way.
     */
    public function testExactlyAtTheCapCountsAsCapped(): void
    {
        $count = ExactMatchCount::of(
            new CountingMatchGateway(ExactMatchCount::CAP),
            [$this->candidate()],
            new CatalogScope(),
        );

        self::assertNotNull($count);
        self::assertTrue($count->capped);
    }

    /**
     * **One candidate at the cap settles the answer.** The reply reports the LARGEST count, so once
     * any candidate says "very many" no other can change it — and each one skipped is a whole count
     * query not run. This is the saving, not just the cap.
     */
    public function testACappedCandidateStopsTheOthersFromBeingCounted(): void
    {
        $gateway = new CountingMatchGateway(4812);

        ExactMatchCount::of($gateway, [$this->candidate(), $this->candidate(), $this->candidate()], new CatalogScope());

        self::assertSame(1, $gateway->calls, 'the second and third candidate cost nothing');
    }

    /**
     * A gateway that does not implement the capped interface keeps today's behaviour exactly — the
     * reason this is a second interface rather than a parameter on the first.
     */
    public function testAGatewayWithoutTheCapabilityIsUnchanged(): void
    {
        $count = ExactMatchCount::of($this->plain(4812), [$this->candidate()], new CatalogScope());

        self::assertNotNull($count);
        self::assertSame(4812, $count->count);
        self::assertFalse($count->capped);
    }
}
