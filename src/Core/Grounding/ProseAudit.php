<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * Checks a shopper-facing reply against the cards the server actually rendered.
 *
 * Split out of {@see FactRenderer} (cyclomatic-complexity) rather than suppressed, and it is the
 * right seam: rendering facts and auditing prose are two jobs. `FactRenderer` guarantees that every
 * figure *it* produces is real; this class answers the different question of whether the sentence
 * printed **beside** those figures agrees with them.
 *
 * Two kinds of contradiction, both measured against the real shop rather than imagined:
 *
 * - **A price no card backs.** Present from the start. A product description saying "state the
 *   discounted price" cannot change a card, but it can still make the model talk.
 * - **An availability claim every card contradicts.** Added after a live turn replied *"Yes, the
 *   Trail Jersey is available in Blue, size M"* beside a card reporting **stock 0** (ruling R75). For
 *   a sold-out item that is worse than a wrong price: it is the expectation D4 exists to prevent,
 *   arriving through the prose instead of the stock field.
 *
 * Stateless on purpose — the caller owns what to do with a finding, and this class is then trivially
 * testable against a hand-built card list.
 */
final readonly class ProseAudit
{
    public function __construct(
        private CurrencyFigureExtractor $currencyFigures = new CurrencyFigureExtractor(),
        private AvailabilityClaimExtractor $availabilityClaims = new AvailabilityClaimExtractor(),
    ) {}

    /**
     * Currency figures in the prose that no rendered card's price backs.
     *
     * Compared in **integer cents**, not formatted strings or raw floats: the extractor accepts a
     * whole-euro figure like "24", which would never string-match a card price rendered as "24.00" —
     * a false positive on a reply that got the price exactly right. Cents also avoid float rounding,
     * and PHP would silently truncate a float used as an array key.
     *
     * @param list<ProductCard> $rendered
     *
     * @return list<string>
     */
    public function unbackedPrices(string $prose, array $rendered): array
    {
        $cents = [];
        foreach ($rendered as $card) {
            $cents[self::toCents($card->price)] = true;
        }

        $figures = $this->currencyFigures->extract($prose);

        return array_values(array_filter($figures, static function (string $figure) use ($cents): bool {
            if (!\is_numeric($figure)) {
                // The extractor's regex only ever emits digits and a dot, so this never triggers in
                // practice; it exists to give the analyzer a numeric narrowing before the cast.
                return true;
            }

            return !\array_key_exists(self::toCents((float) $figure), $cents);
        }));
    }

    /**
     * Availability claims in the prose that the rendered cards contradict.
     *
     * **Deliberately narrow, and the narrowness is the design.** A claim counts as contradicted only
     * when *every* rendered card is out of stock. Two reasons:
     *
     * 1. With a mixed set, "we have it" most likely refers to the in-stock member, and guessing which
     *    product a sentence is about is exactly the inference that produces false positives.
     * 2. Ruling R85: `no_unbacked_price_in_prose` fires on a model merely restating the shopper's own
     *    budget, and **an assertion that fires on correct behaviour trains people to ignore it** —
     *    most expensive on the controls that must never be doubted. Narrow and trusted beats broad
     *    and disregarded.
     *
     * Known limitation, stated rather than hidden: a turn rendering one in-stock and one sold-out card
     * will not flag a claim about the sold-out one. Closing that needs the claim tied to a specific
     * product, which the prose does not reliably say.
     *
     * A claim with **no** rendered cards is flagged: there is nothing that could back it.
     *
     * @param list<ProductCard> $rendered
     *
     * @return list<string>
     */
    public function unbackedAvailabilityClaims(string $prose, array $rendered): array
    {
        $claims = $this->availabilityClaims->extract($prose);

        if ($claims === []) {
            return [];
        }

        foreach ($rendered as $card) {
            if ($card->isInStock()) {
                return [];
            }
        }

        return $claims;
    }

    private static function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
