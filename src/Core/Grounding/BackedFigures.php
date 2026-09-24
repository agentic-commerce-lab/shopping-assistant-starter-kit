<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * Every figure a reply is ENTITLED to state, in integer cents.
 *
 * Four sources, and each one is a ruling rather than a convenience:
 *
 * - **A rendered card's price.** The original and the only one that needs no argument.
 * - **A number the shopper themselves introduced** (ruling R85). The `price_constraint` journey asks
 *   *"nothing over 40 please"* and the model replies *"I searched for products priced up to 40"* —
 *   measured verbatim, and the bare `40` was flagged. The model restated a constraint and reported
 *   honestly.
 * - **A number in a shop-information passage the model was handed.** Measured 2026-08-27 through the
 *   real endpoint: a correct shipping answer came back with `["4.95","29.00","9.95","14.95"]` flagged,
 *   because a shop-information turn renders no cards while spec R6 lets the model paraphrase the
 *   passage text it was given.
 * - **An amount an order tool returned this turn** (2026-09-24, D3 relaxed for the shopper's own
 *   orders). Taken as exact amounts from {@see OrderFigures}, never by scanning text, so an order
 *   figure the model rounded or added up stays unbacked — the edge of what the relaxation allows.
 *
 * All four exist for one reason, stated in {@see ProseAudit}: **a safety assertion that fires on
 * correct behaviour trains people to ignore it.**
 *
 * Extracted from {@see ProseAudit} when the third source pushed that class past this project's
 * maintainability gate. The boundary earns itself: *what may this reply say* is a different question
 * from *does this reply contradict the cards*, and only the second one is an audit.
 *
 * **Cents, not formatted strings or floats.** The extractor accepts a whole-euro figure like "24",
 * which would never string-match a card price rendered as "24.00" — a false positive on a reply that
 * got the price exactly right. Cents also avoid float rounding, and PHP would silently truncate a
 * float used as an array key.
 */
final class BackedFigures
{
    private function __construct() {}

    /**
     * @param list<ProductCard> $rendered
     * @param list<string>      $givenPassages shop-information passages this run handed the model
     * @param list<float>       $orderFigures  amounts order tools returned this turn
     *
     * @return array<int, true> keyed by cents, so a lookup is `array_key_exists`
     */
    public static function inCents(
        array $rendered,
        string $shopperMessage,
        array $givenPassages,
        array $orderFigures = [],
    ): array {
        $cents = [];

        // Exact amounts, both of them: a card's price and an order tool's figure are backed as the
        // number they are, which is what keeps a rounded or summed order total unbacked.
        foreach ([
            ...array_map(static fn(ProductCard $card): float => $card->price, $rendered),
            ...$orderFigures,
        ] as $amount) {
            $cents[self::toCents($amount)] = true;
        }

        // The shopper's side and the documents' side are both read with a plain number scan rather
        // than with CurrencyFigureExtractor, and the asymmetry is the point. The extractor answers
        // "is this a stated price?" — it needs a currency token or two decimals, so it finds nothing
        // at all in "nothing over 40 please". The question here is the looser one: *did this text
        // contain this number?* Using the strict extractor on both sides made the exemption unable to
        // fire, which is how the first attempt at R85 silently did nothing.
        foreach ([$shopperMessage, ...$givenPassages] as $text) {
            foreach (self::numbersIn($text) as $figure) {
                $cents[self::toCents($figure)] = true;
            }
        }

        return $cents;
    }

    public static function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /**
     * Every number in a piece of text the model was given, however written.
     *
     * @return list<float>
     */
    private static function numbersIn(string $text): array
    {
        $matches = [];

        if (preg_match_all('/\d+(?:[.,]\d+)?/', $text, $matches) === false) {
            return [];
        }

        $numbers = [];

        foreach ($matches[0] as $raw) {
            // A comma decimal separator is how a German document writes 4,95, and a reply saying
            // "4.95" must be exempt by it.
            $numbers[] = (float) str_replace(',', '.', $raw);
        }

        return $numbers;
    }
}
