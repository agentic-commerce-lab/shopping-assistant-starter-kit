<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\ShopInfo;

/**
 * Cutting text at a byte bound without ever cutting inside a character.
 *
 * The last resort of {@see BoundedParagraph}, and its own class only because Mago's complexity budget
 * is per class. Reached when a single sentence is longer than the whole chunk bound — a list or a
 * table that lost its formatting during extraction.
 *
 * **Bytes, but never half a character.** The bound exists to keep a passage small enough to embed and
 * the embedding API counts bytes; half of a multi-byte character is invalid UTF-8, which would make
 * the passage unindexable rather than merely awkward. German legal text is full of umlauts, so this
 * is the normal case and not a corner one.
 */
final readonly class HardSplit
{
    /**
     * @return list<string>
     */
    public static function atBound(string $text, int $maxBytes): array
    {
        $pieces = [];
        $current = '';

        foreach (mb_str_split($text) as $character) {
            if ((\strlen($current) + \strlen($character)) > $maxBytes) {
                $pieces[] = $current;
                $current = '';
            }

            $current .= $character;
        }

        return $current === '' ? $pieces : [...$pieces, $current];
    }
}
