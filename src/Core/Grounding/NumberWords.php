<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

/**
 * Spelled-out numbers and period units, reduced to one canonical form.
 *
 * Split from {@see PeriodClaimExtractor} because it is a lookup table and that class is a parser, and
 * because Mago's complexity budget is per class.
 *
 * **German is here on purpose**, even though the eval suite is English-first. The detector this feeds
 * runs in the shipped shop, which answers in the shopper's language — a control that only read English
 * would be missing exactly where German legal documents are.
 *
 * Small and closed rather than a full number parser: shop documents state periods in the range a
 * consumer contract uses. `NUMBERS` covers what appears in one; anything larger arrives as digits
 * anyway, which needs no table.
 */
final readonly class NumberWords
{
    /**
     * Word forms, lowercase, German and English together.
     *
     * German inflections are listed rather than stemmed: `ein`, `eine`, `einem`, `einen` all mean one,
     * and a stemmer would also fold `einzeln` into it.
     *
     * @var array<string, int>
     */
    private const NUMBERS = [
        'one' => 1,
        'two' => 2,
        'three' => 3,
        'four' => 4,
        'five' => 5,
        'six' => 6,
        'seven' => 7,
        'eight' => 8,
        'nine' => 9,
        'ten' => 10,
        'eleven' => 11,
        'twelve' => 12,
        'thirteen' => 13,
        'fourteen' => 14,
        'fifteen' => 15,
        'sixteen' => 16,
        'seventeen' => 17,
        'eighteen' => 18,
        'nineteen' => 19,
        'twenty' => 20,
        'thirty' => 30,
        'forty' => 40,
        'fifty' => 50,
        'sixty' => 60,
        'ninety' => 90,
        'ein' => 1,
        'eine' => 1,
        'einem' => 1,
        'einen' => 1,
        'einer' => 1,
        'zwei' => 2,
        'drei' => 3,
        'vier' => 4,
        'fuenf' => 5,
        'fünf' => 5,
        'sechs' => 6,
        'sieben' => 7,
        'acht' => 8,
        'neun' => 9,
        'zehn' => 10,
        'elf' => 11,
        'zwoelf' => 12,
        'zwölf' => 12,
        'dreizehn' => 13,
        'vierzehn' => 14,
        'fuenfzehn' => 15,
        'fünfzehn' => 15,
        'sechzehn' => 16,
        'siebzehn' => 17,
        'achtzehn' => 18,
        'neunzehn' => 19,
        'zwanzig' => 20,
        'dreissig' => 30,
        'dreißig' => 30,
        'vierzig' => 40,
        'fuenfzig' => 50,
        'fünfzig' => 50,
        'sechzig' => 60,
        'neunzig' => 90,
    ];

    /**
     * Period units, lowercase, mapped to a canonical singular.
     *
     * **`working day` is deliberately not `day`.** "Three working days" and "three days" are different
     * promises — a Friday order arriving in three working days arrives on Wednesday — and folding them
     * together would let a reply turn a delivery estimate into a calendar deadline without the audit
     * noticing. Longest keys are matched first, so `working day` wins over `day`.
     *
     * @var array<string, string>
     */
    private const UNITS = [
        'working days' => 'working day',
        'working day' => 'working day',
        'business days' => 'working day',
        'business day' => 'working day',
        'werktagen' => 'working day',
        'werktage' => 'working day',
        'werktag' => 'working day',
        'days' => 'day',
        'day' => 'day',
        'tagen' => 'day',
        'tage' => 'day',
        'tag' => 'day',
        'weeks' => 'week',
        'week' => 'week',
        'wochen' => 'week',
        'woche' => 'week',
        'months' => 'month',
        'month' => 'month',
        'monaten' => 'month',
        'monate' => 'month',
        'monat' => 'month',
        'years' => 'year',
        'year' => 'year',
        'jahren' => 'year',
        'jahre' => 'year',
        'jahr' => 'year',
    ];

    /** Every number word, longest first, ready for a regex alternation. */
    public static function numberPattern(): string
    {
        $words = array_keys(self::NUMBERS);
        usort($words, static fn(string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return implode('|', array_map('preg_quote', $words));
    }

    /** Every unit, longest first, so `working days` is matched before `days`. */
    public static function unitPattern(): string
    {
        $units = array_keys(self::UNITS);
        usort($units, static fn(string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return implode('|', array_map('preg_quote', $units));
    }

    /** A digit string or a number word, as an integer, or null when it is neither. */
    public static function toInt(string $word): ?int
    {
        $word = mb_strtolower(trim($word));

        if ($word === '') {
            return null;
        }

        if (ctype_digit($word)) {
            return (int) $word;
        }

        return self::NUMBERS[$word] ?? null;
    }

    /** The canonical singular for a unit word, or null when it is not a period unit. */
    public static function toUnit(string $word): ?string
    {
        return self::UNITS[mb_strtolower(trim($word))] ?? null;
    }
}
