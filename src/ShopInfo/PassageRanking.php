<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\ShopInfo;

use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoPassage;

/**
 * Scores candidate passages against a query vector, drops what falls below the threshold, and returns
 * the best few.
 *
 * Extracted from {@see InMemoryPassageStore} when {@see DalPortablePassageStore} needed the same
 * logic. `PassageStore`'s own docblock explains why one copy matters: the interface speaks
 * SIMILARITY, higher being more similar, while a vector store underneath speaks DISTANCE, lower being
 * more similar — "the one thing here most likely to be simplified into a sign error". Two stores each
 * writing their own threshold-and-sort is two chances to make it, and the copy that drifts is always
 * the one nobody reads.
 */
final class PassageRanking
{
    private function __construct() {}

    /**
     * @param list<float>                                                  $queryVector
     * @param list<array{passage: ShopInfoPassage, vector: list<float>}>   $candidates
     *
     * @return list<ShopInfoPassage> best first, each carrying its similarity
     */
    public static function of(array $queryVector, array $candidates, float $minScore, int $limit): array
    {
        $scored = [];

        foreach ($candidates as $candidate) {
            $score = CosineSimilarity::between($queryVector, $candidate['vector']);

            if ($score < $minScore) {
                continue;
            }

            $passage = $candidate['passage'];
            $scored[] = new ShopInfoPassage(
                $passage->documentId,
                $passage->documentName,
                $passage->section,
                $passage->text,
                $score,
            );
        }

        usort($scored, static fn(ShopInfoPassage $a, ShopInfoPassage $b): int => $b->score <=> $a->score);

        return \array_slice($scored, offset: 0, length: $limit);
    }
}
