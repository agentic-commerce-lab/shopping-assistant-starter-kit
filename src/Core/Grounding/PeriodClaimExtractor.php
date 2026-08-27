<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

/**
 * The periods a piece of text states — "14 days", "fourteen days", "vierzehn Tagen" — as one token each.
 *
 * The counterpart of {@see CurrencyFigureExtractor} for durations, and it exists for the risk specific
 * to shop information: a reply that states a deadline, a retention period or a warranty term the
 * merchant's documents never granted. A wrong price disappoints a shopper; an invented revocation
 * period is a legal statement about the merchant's business.
 *
 * **Normalising is the whole job.** A model paraphrases — measured on the lab shop, a document saying
 * "binnen dreissig Tagen" produced a reply saying "30 Tage" — so comparing words would report a
 * correct answer as an invention. {@see NumberWords} reduces every phrasing of the same period to one
 * token, and only then is anything compared.
 *
 * Ranges are expanded to both endpoints: "one to three working days" states both a floor and a
 * ceiling, and either could be the invented half.
 */
final readonly class PeriodClaimExtractor
{
    /**
     * @return list<string> canonical periods, e.g. `14 day`, `3 working day`, first appearance order,
     *                      each reported once
     */
    public function extract(string $text): array
    {
        $numbers = NumberWords::numberPattern();
        $units = NumberWords::unitPattern();

        // A range first — "3 to 5 working days", "drei bis fuenf Werktage", "1-3 working days" — so its
        // two numbers are not read as one period each with the wrong unit.
        $pattern = \sprintf(
            '/\b(?:(%1$s|\d{1,4})\s*(?:to|bis|–|-|\x{2013})\s*)?(%1$s|\d{1,4})\s+(%2$s)\b/iu',
            $numbers,
            $units,
        );

        if (preg_match_all($pattern, $text, $matches, \PREG_SET_ORDER) === false) {
            return [];
        }

        $claims = [];

        foreach ($matches as $match) {
            foreach (self::claimsIn($match) as $claim) {
                $claims[$claim] = true;
            }
        }

        return array_keys($claims);
    }

    /**
     * @param array<array-key, string> $match
     *
     * @return list<string>
     */
    private static function claimsIn(array $match): array
    {
        $unit = NumberWords::toUnit($match[3] ?? '');

        if ($unit === null) {
            return [];
        }

        $claims = [];

        // The range's lower bound, when there was one.
        $from = NumberWords::toInt($match[1] ?? '');

        if ($from !== null) {
            $claims[] = $from . ' ' . $unit;
        }

        $to = NumberWords::toInt($match[2] ?? '');

        if ($to !== null) {
            $claims[] = $to . ' ' . $unit;
        }

        return $claims;
    }
}
