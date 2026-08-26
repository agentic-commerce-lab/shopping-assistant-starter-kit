<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\ShopInfo;

use Swag\AssistantStarterKit\Core\ShopInfo\PassageStore;

/**
 * @internal cosine similarity for the in-memory store, in the same direction the real store's
 *           callers see after it converts its own distance — see {@see PassageStore} on the sign
 */
final class CosineSimilarity
{
    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    public static function between(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $value) {
            $other = $b[$i] ?? 0.0;
            $dot += $value * $other;
            $normA += $value ** 2;
            $normB += $other ** 2;
        }

        $magnitude = sqrt($normA) * sqrt($normB);

        return $magnitude === 0.0 ? 0.0 : $dot / $magnitude;
    }
}
