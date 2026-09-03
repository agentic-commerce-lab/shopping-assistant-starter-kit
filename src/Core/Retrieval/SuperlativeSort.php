<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval;

/**
 * The ordering a shopper's own words ask for, when the model did not ask for it.
 *
 * ## The defect this exists for
 *
 * Measured on a 6.6 test shop, 2026-09-03, `google/gemini-3.7-flash`. Asked *"what is the cheapest
 * coat?"*, three samples in a row named a coat that was not the cheapest one on screen:
 *
 * | The reply called it cheapest | The prices actually rendered |
 * |---|---|
 * | Halter Coat 1124 — 155.71 | 155.71 / **71.07** / 135.42 |
 * | Tea Coat 0812 — 219.25 | 219.25 / **35.63** / 292.53 |
 *
 * Each time the trace read `filtersApplied: []` and no sort: {@see PriceSort} was built for exactly
 * this question and the model never passed it. It received relevance-ranked hits, had no figures of
 * its own — {@see \Swag\AssistantStarterKit\Core\Tool\ToolProductSummary} carries none by design —
 * and named the first thing it saw. Not a 6.6 problem and not a rebase artefact: `SearchPriceSortTest`
 * is green, the sorting mechanism is intact. What was missing was anything that *makes* it run.
 *
 * ## Why the shop decides it and not the prompt
 *
 * The parameter's use was documented only in the tool's own docblock, which is prose addressed to a
 * model — and this is the second defect of that shape found in one day (see the "show me all" rule in
 * {@see \Swag\AssistantStarterKit\Core\Prompt\SystemPrompt}). A capability that only works when the
 * model remembers to ask for it is not a capability the shop can promise.
 *
 * So the shop reads the shopper's own sentence, exactly as {@see StatedBudget} reads a stated price
 * range rather than trusting the query to have carried it. A superlative is an unusually safe thing
 * to read this way: *"the cheapest"* has one meaning, it is a request about ordering and nothing
 * else, and getting it wrong can only ever reorder a result set the shopper already asked for.
 *
 * ## Why this misreads nothing
 *
 * **Superlatives only.** *"Do you have anything cheaper?"* is a comparative — it asks relative to
 * what is already on screen, which is a different question with a different answer, and it is left
 * to the model. Only the absolute form is claimed here.
 *
 * **The model still wins.** An explicit `sort` argument is honoured as passed; this fills in a
 * missing one. A model that asked for `price_desc` on the words "the cheapest" is answering some
 * other question, and second-guessing it here would hide that rather than fix it.
 */
final class SuperlativeSort
{
    /**
     * Read on word boundaries, and inflected forms spelled out rather than stemmed.
     *
     * German declines these five ways (`günstigste`, `günstigsten`, `günstigster`, `günstigstes`,
     * `günstigstem`) so the suffix is an alternation, for the reason
     * {@see \Swag\AssistantStarterKit\Core\Grounding\PropertyNegation} gives for writing `kein` out:
     * a stem would fold in whatever else begins with those letters.
     */
    private const ASCENDING = [
        'cheapest',
        'least\s+expensive',
        'lowest[\s-]priced?',
        'lowest\s+price',
        'best\s+price',
        'g(?:ü|ue)nstigste[mnrs]?',
        'billigste[mnrs]?',
        'preiswerteste[mnrs]?',
        'niedrigste[mnrs]?\s+preis',
    ];

    private const DESCENDING = [
        'most\s+expensive',
        'priciest',
        'dearest',
        'highest[\s-]priced?',
        'highest\s+price',
        'teuerste[mnrs]?',
        'h(?:ö|oe)chste[mnrs]?\s+preis',
    ];

    private function __construct() {}

    /**
     * The ordering that applies to a search: the one the model asked for, else the one the shopper's
     * own sentence asks for, else this shop's relevance ranking.
     *
     * One call rather than a `??` at the call site, so {@see \Swag\AssistantStarterKit\Core\Tool\SearchProductsTool}
     * carries no decision about it — that class is at the complexity the gate allows, and "which
     * ordering applies" belongs beside the words that decide it anyway.
     */
    public static function applying(?string $requested, string $shopperMessage): ?PriceSort
    {
        return PriceSort::fromRequest($requested) ?? self::inferredFrom($shopperMessage);
    }

    /**
     * The ordering these words ask for, or null when they ask for none.
     *
     * Ascending is tested first and wins a sentence containing both. That is not a real sentence a
     * shopper writes; it is a rule so the outcome does not depend on array order, and cheapest is
     * the safer of the two to guess at — it is what a shopper hunting a price is nearly always after.
     */
    public static function inferredFrom(string $shopperMessage): ?PriceSort
    {
        $message = mb_strtolower($shopperMessage);

        if (self::matchesAny($message, self::ASCENDING)) {
            return PriceSort::Ascending;
        }

        return self::matchesAny($message, self::DESCENDING) ? PriceSort::Descending : null;
    }

    /**
     * @param list<string> $patterns
     */
    private static function matchesAny(string $message, array $patterns): bool
    {
        return preg_match(\sprintf('/\b(?:%s)\b/u', implode('|', $patterns)), $message) === 1;
    }
}
