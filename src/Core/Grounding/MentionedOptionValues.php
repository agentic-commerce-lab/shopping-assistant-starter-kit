<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * Which of the retrieved products' option values a reply actually mentions, grouped by option.
 *
 * The matching half of {@see ContradictedVariants}, split out for the same reason
 * {@see ProductNameIndex} lives beside {@see FactRenderer}: both jobs in one class put it over this
 * project's per-class cyclomatic-complexity budget, and "what does this sentence mention" is testable
 * without any notion of contradiction.
 *
 * **Word boundaries, because sizes are single letters.** `M` occurs inside dozens of ordinary words,
 * so a plain `str_contains` would find every size in every sentence. The boundary is written as "no
 * letter or digit on either side" rather than `\b`, which does the wrong thing around non-ASCII text —
 * this prose is German as often as English.
 *
 * **Only values the retrieved cards carry.** A value no retrieved card has cannot rule a retrieved
 * card out, so the vocabulary comes from the cards rather than from the shop's whole facet set.
 */
final class MentionedOptionValues
{
    private function __construct() {}

    /**
     * @param list<ProductCard> $cards
     *
     * @return array<string, list<string>> option group => the values this reply mentions, lowercased
     */
    public static function of(string $prose, array $cards): array
    {
        $mentioned = [];

        foreach (self::vocabulary($cards) as $group => $values) {
            foreach ($values as $value) {
                if (self::mentions($prose, $value)) {
                    $mentioned[$group][] = mb_strtolower($value);
                }
            }
        }

        return $mentioned;
    }

    /**
     * @param list<ProductCard> $cards
     *
     * @return array<string, list<string>> option group => every value the retrieved cards carry
     */
    private static function vocabulary(array $cards): array
    {
        $values = [];

        foreach ($cards as $card) {
            foreach ($card->options as $group => $value) {
                $values[(string) $group][mb_strtolower($value)] = $value;
            }
        }

        $vocabulary = [];

        foreach ($values as $group => $byValue) {
            $vocabulary[$group] = array_values($byValue);
        }

        return $vocabulary;
    }

    private static function mentions(string $prose, string $value): bool
    {
        if (trim($value) === '') {
            return false;
        }

        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($value, '/') . '(?![\p{L}\p{N}])/iu';

        return preg_match($pattern, $prose) === 1;
    }
}
