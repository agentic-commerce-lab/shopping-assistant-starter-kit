<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\ShopInfo;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoPassage;
use Swag\AssistantStarterKit\ShopInfo\PassageRanking;

/**
 * The threshold-and-sort both passage stores need, in one place.
 *
 * `PassageStore`'s own docblock names the hazard this class exists to contain: the interface speaks
 * SIMILARITY, where higher is more similar, while the MariaDB store underneath speaks DISTANCE, where
 * lower is. It calls that inversion "the one thing here most likely to be simplified into a sign
 * error". Two stores each doing their own threshold-and-sort is two chances to make it.
 */
final class PassageRankingTest extends TestCase
{
    /**
     * @param list<float> $vector
     *
     * @return array{passage: ShopInfoPassage, vector: list<float>}
     */
    private function candidate(string $name, array $vector): array
    {
        return [
            'passage' => new ShopInfoPassage('doc-1', $name, 'Section', 'text of ' . $name),
            'vector' => $vector,
        ];
    }

    public function testItReturnsTheMostSimilarPassageFirst(): void
    {
        $ranked = PassageRanking::of(
            [1.0, 0.0],
            [$this->candidate('far', [0.0, 1.0]), $this->candidate('near', [1.0, 0.0])],
            minScore: 0.0,
            limit: 10,
        );

        $best = $ranked[0] ?? null;
        self::assertNotNull($best);
        self::assertSame('near', $best->documentName);
    }

    public function testItSetsTheScoreOnEveryPassageItReturns(): void
    {
        $ranked = PassageRanking::of([1.0, 0.0], [$this->candidate('near', [1.0, 0.0])], 0.0, 10);

        $best = $ranked[0] ?? null;
        self::assertNotNull($best);
        self::assertEqualsWithDelta(1.0, $best->score, 0.000001);
    }

    /**
     * Higher is more similar, so the threshold is a floor. Getting this backwards would return
     * exactly the passages that do not answer the question.
     */
    public function testItDropsAnythingBelowTheThreshold(): void
    {
        $ranked = PassageRanking::of(
            [1.0, 0.0],
            [$this->candidate('orthogonal', [0.0, 1.0])],
            minScore: 0.4,
            limit: 10,
        );

        self::assertSame([], $ranked);
    }

    /**
     * The threshold is a floor and the comparison is inclusive (`< minScore` means drop below it).
     * This test pins the boundary: a candidate whose score equals `minScore` must be kept, not dropped.
     * Without this test, a future edit flipping `<` to `<=` would be invisible — exactly the sign-error
     * class of bug `PassageRanking`'s docblock says the class exists to guard against.
     */
    public function testItKeepsACandidateAtTheThresholdBoundary(): void
    {
        $ranked = PassageRanking::of(
            [1.0, 0.0],
            [$this->candidate('at-boundary', [1.0, 0.0])],
            minScore: 1.0,
            limit: 10,
        );

        self::assertCount(1, $ranked);
        $best = $ranked[0] ?? null;
        self::assertNotNull($best);
        self::assertSame('at-boundary', $best->documentName);
    }

    public function testItReturnsAtMostTheLimit(): void
    {
        $ranked = PassageRanking::of(
            [1.0, 0.0],
            [
                $this->candidate('a', [1.0, 0.0]),
                $this->candidate('b', [0.9, 0.1]),
                $this->candidate('c', [0.8, 0.2]),
            ],
            minScore: 0.0,
            limit: 2,
        );

        self::assertCount(2, $ranked);
    }

    public function testAnEmptyCandidateSetRanksToNothing(): void
    {
        self::assertSame([], PassageRanking::of([1.0, 0.0], [], 0.0, 10));
    }
}
