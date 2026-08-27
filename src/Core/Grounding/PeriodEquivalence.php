<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

/**
 * Two ways of writing the same period, reduced to one comparison key.
 *
 * **Why this exists, measured.** {@see PeriodClaimExtractor} already folds "fourteen days", "vierzehn
 * Tagen" and "14 Tage" together, but it left "two weeks" as its own thing — so a passage granting
 * *fourteen days* and a reply saying *two weeks* disagreed, and {@see PassageAudit} reported a
 * perfectly good answer as an invented deadline. Models produce that paraphrase constantly. A safety
 * check that fires on correct behaviour trains people to ignore it, and this one is meant to be strong
 * enough to act on.
 *
 * **What is folded, and what deliberately is not.**
 *
 * - Weeks into days, and years into months: both are exact by definition, so a reply may use either.
 * - **Months are NOT folded into days.** "One month" is not thirty days — it runs to the same date in
 *   the next month, which is what a contract means by it, and treating them as equal would let a reply
 *   turn a one-month window into a thirty-day one silently.
 * - **Working days stay their own unit.** Three working days is not three days: a Friday order arriving
 *   in three working days arrives on Wednesday. Folding them would let a delivery estimate become a
 *   calendar deadline unnoticed.
 */
final readonly class PeriodEquivalence
{
    /** Exact conversions into a family's base unit. Anything absent is already its own base. */
    private const IN_BASE_UNITS = [
        'week' => ['day', 7],
        'year' => ['month', 12],
    ];

    /**
     * A key two equivalent periods share, e.g. `day:14` for both "14 day" and "2 week".
     *
     * Unparseable input is returned unchanged rather than dropped: a period this cannot read must still
     * compare equal to itself, or the audit would report every occurrence of it as unsupported.
     */
    public static function keyFor(string $period): string
    {
        $parts = explode(' ', $period, 2);
        $amount = $parts[0] ?? '';
        $unit = $parts[1] ?? '';

        if (!ctype_digit($amount) || $unit === '') {
            return $period;
        }

        [$base, $factor] = self::IN_BASE_UNITS[$unit] ?? [$unit, 1];

        return \sprintf('%s:%d', $base, (int) $amount * $factor);
    }
}
