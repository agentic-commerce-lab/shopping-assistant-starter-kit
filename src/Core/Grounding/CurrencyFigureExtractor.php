<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

/**
 * Extracts monetary figures written as "€12.90", "EUR 12,90" or "12,90 €" out
 * of free-text prose, normalising the decimal separator to a dot.
 *
 * Split out of {@see FactRenderer} purely to keep that class's cyclomatic
 * complexity down — this is a self-contained parsing concern with no state
 * and no dependency on the retrieval map, so it earns its own file rather
 * than inflating the class the whole grounding guarantee rests on.
 */
final class CurrencyFigureExtractor
{
    private const PATTERN = '/(?:€|EUR)\s*([0-9]+(?:[.,][0-9]{2})?)|([0-9]+[.,][0-9]{2})\s*(?:€|EUR)/u';

    /**
     * @return list<string> figures as found, normalised to a dot decimal separator
     */
    public function extract(string $prose): array
    {
        $matches = [];
        $found = preg_match_all(self::PATTERN, $prose, $matches, \PREG_SET_ORDER);
        if ($found === false || $found === 0) {
            return [];
        }

        $figures = [];
        foreach ($matches as $match) {
            $prefixForm = $match[1] ?? '';
            $suffixForm = $match[2] ?? '';
            $raw = $prefixForm !== '' ? $prefixForm : $suffixForm;

            if ($raw === '') {
                continue;
            }

            $figures[] = strtr($raw, ',', '.');
        }

        return $figures;
    }
}
