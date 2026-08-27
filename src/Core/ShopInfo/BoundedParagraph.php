<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\ShopInfo;

/**
 * One paragraph cut down to the bound — at sentence ends where possible, by force where not.
 *
 * Its own class because it is the only part of chunking that deals in something smaller than a
 * paragraph, and because {@see ParagraphSplitter} has to stay inside Mago's complexity budget.
 *
 * Both cuts are lossy in different ways and both are better than the alternative. A sentence cut
 * keeps every sentence whole and only separates neighbours. A forced cut ({@see HardSplit}) can land
 * mid-word, but it applies only to a single sentence longer than the entire bound — which in a legal
 * document means a list or a table that lost its formatting during extraction, and refusing to index
 * it at all would be worse.
 */
final readonly class BoundedParagraph
{
    /**
     * @return list<string>
     */
    public static function pieces(string $paragraph, int $maxChars): array
    {
        if (\strlen($paragraph) <= $maxChars) {
            return [$paragraph];
        }

        $sentences = preg_split('/(?<=[.!?])\s+/', $paragraph);

        if ($sentences === false) {
            return HardSplit::atBound($paragraph, $maxChars);
        }

        $pieces = [];
        $current = '';

        foreach ($sentences as $sentence) {
            $candidate = $current === '' ? $sentence : $current . ' ' . $sentence;

            if (\strlen($candidate) <= $maxChars) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $pieces[] = $current;
            }

            $forced = HardSplit::atBound($sentence, $maxChars);
            $current = array_pop($forced) ?? '';
            $pieces = [...$pieces, ...$forced];
        }

        return $current === '' ? $pieces : [...$pieces, $current];
    }
}
