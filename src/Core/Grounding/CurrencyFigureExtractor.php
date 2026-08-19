<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

/**
 * Extracts monetary figures out of free-text prose, normalising both the decimal
 * separator (to a dot) and thousands grouping (removed) so the result compares
 * cleanly against a rendered card's price.
 *
 * Three independent shapes are matched, tried in this order at every position:
 *
 * 1. A currency token immediately BEFORE the number — `€12.90`, `$1.29`, `EUR 24`,
 *    `eur 1,29` (any of `€`, `$`, `EUR`, `Euro`, `Euros`, case-insensitively). Cents
 *    are OPTIONAL here: a whole-euro figure like `€24` carries no decimal point at
 *    all, and must still be extracted as `24`.
 * 2. A currency token immediately AFTER the number — `12.90 EUR`, `1.29 euros`,
 *    `1,29 Euro`. Cents are required here, same as (3).
 * 3. A bare decimal figure with NO currency token anywhere near it — `only 1.29`,
 *    `12.90 (90% off 129.00)`. Nothing marks a figure like this as a price except
 *    its own two-decimal shape, so this alternative accepts ANY such number,
 *    deliberately noisy: for a check whose entire job is catching a price a model
 *    was tricked into quoting (Acceptance criterion A4 / the `injection_discount`
 *    journey), the cost of missing a real one is far higher than the cost of
 *    double-checking an unrelated number, and this class's only caller,
 *    {@see FactRenderer::unbackedPricesInProse()}, already tolerates a figure that
 *    happens to coincidentally match a rendered price by treating it as backed.
 *
 * All three shapes accept an integer part grouped into thousands by either `.` or
 * `,` (`1,299.00`, `1.299,00`, or ungrouped `1299.00`) — a group is exactly three
 * digits after a separator, which a two-decimal cents figure never is, so the two
 * are never confused. Without this, a genuine `€1,299.00` was truncated at the
 * group separator and misread as `1.29`.
 *
 * Split out of {@see FactRenderer} purely to keep that class's cyclomatic
 * complexity down — this is a self-contained parsing concern with no state
 * and no dependency on the retrieval map, so it earns its own file rather
 * than inflating the class the whole grounding guarantee rests on.
 */
final class CurrencyFigureExtractor
{
    private const CURRENCY_MARKER = '(?:€|\$|\bEUR\b|\bEuros?\b)';

    private const GROUPED_INTEGER = '[0-9]{1,3}(?:[.,][0-9]{3})*|[0-9]+';

    private const PATTERN =
        '/'
            . self::CURRENCY_MARKER
            . '\s*(?<int1>'
            . self::GROUPED_INTEGER
            . ')(?:[.,](?<frac1>[0-9]{2}))?'
            . '|(?<int2>'
            . self::GROUPED_INTEGER
            . ')[.,](?<frac2>[0-9]{2})\s*'
            . self::CURRENCY_MARKER
            . '|(?<int3>'
            . self::GROUPED_INTEGER
            . ')[.,](?<frac3>[0-9]{2})'
            . '/ui';

    /**
     * @return list<string> figures as found, normalised to a dot decimal separator
     *                      with thousands grouping removed, deduplicated
     */
    public function extract(string $prose): array
    {
        $matches = [];
        $found = preg_match_all(self::PATTERN, $prose, $matches, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL);
        if ($found === false || $found === 0) {
            return [];
        }

        $figures = [];
        foreach ($matches as $match) {
            $figure = self::normalise($match);
            if ($figure !== null) {
                $figures[] = $figure;
            }
        }

        return array_values(array_unique($figures));
    }

    /**
     * Exactly one of the pattern's three alternatives ever matches per occurrence, so
     * exactly one of `int1`/`int2`/`int3` is ever non-null — looping over the three
     * possible suffixes instead of chaining three `??` lookups keeps this method's own
     * complexity (and this class's aggregate) low.
     *
     * `preg_match_all()`'s PREG_SET_ORDER rows carry both numbered and named captures
     * in the same array, hence the broader `array-key` (not `string`) key type.
     *
     * @param array<array-key, string|null> $match
     */
    private static function normalise(array $match): ?string
    {
        foreach (['1', '2', '3'] as $alternative) {
            $integerPart = $match['int' . $alternative] ?? null;
            if ($integerPart === null) {
                continue;
            }

            $integerPart = str_replace([',', '.'], '', $integerPart);
            $fractionPart = $match['frac' . $alternative] ?? null;

            return $fractionPart === null ? $integerPart : sprintf('%s.%s', $integerPart, $fractionPart);
        }

        return null;
    }
}
