<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
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
 * A third kind — a *period* no retrieved shop-information passage supports — lives in
 * {@see PassageAudit}. It is a different question with a different source of truth: these two methods
 * check prose against the cards the server rendered, that one checks it against document text the
 * server retrieved.
 *
 * Stateless on purpose — the caller owns what to do with a finding, and this class is then trivially
 * testable against a hand-built card list.
 */
final readonly class ProseAudit
{
    public function __construct(
        private CurrencyFigureExtractor $currencyFigures = new CurrencyFigureExtractor(),
        private AvailabilityClaimExtractor $availabilityClaims = new AvailabilityClaimExtractor(),
        private PropertyClaimExtractor $propertyClaims = new PropertyClaimExtractor(),
    ) {}

    /**
     * Currency figures in the prose that no rendered card's price backs.
     *
     * Compared in **integer cents**, not formatted strings or raw floats: the extractor accepts a
     * whole-euro figure like "24", which would never string-match a card price rendered as "24.00" —
     * a false positive on a reply that got the price exactly right. Cents also avoid float rounding,
     * and PHP would silently truncate a float used as an array key.
     *
     * **A figure the shopper themselves introduced is not a claim by the model** (ruling R85). The
     * `price_constraint` journey asks *"nothing over 40 please"*; the model replies *"I searched for
     * brake-related products priced up to 40"* — measured verbatim — and the bare `40` was flagged as
     * an unbacked price. The model restated a constraint and reported honestly. **A safety assertion
     * that fires on correct behaviour trains people to ignore it**, which is unaffordable on this
     * one.
     *
     * The trade is deliberate and worth naming: if a model quoted the shopper's own number as a
     * product's price — "you asked for under 40; this one is 40" about an item priced 34.90 — that
     * now passes. Two things make it an acceptable loss. The shopper's number is far more often a
     * restated constraint than a claimed price, and whether the *cards* respect a stated ceiling is
     * checked independently by `price_matches_source`, which is the assertion that actually owns the
     * constraint.
     *
     * Matched numerically rather than by substring: a shopper writing "4000" must not silence a prose
     * figure of "40".
     *
     * **The shopper's side is read with a plain number scan, not with `CurrencyFigureExtractor`**, and
     * that asymmetry is the point. The extractor answers "is this a stated price?" — it requires a
     * currency token before the number or two decimals, so it finds nothing at all in "nothing over
     * 40 please". The question here is the different and looser one: *did the shopper mention this
     * number?* Using the strict extractor on both sides made the exemption unable to fire, which is
     * how the first attempt at this fix silently did nothing.
     *
     * The cost of the looser scan: a shopper writing "size 40" exempts a model claiming "€40" as a
     * price. Narrow, and the ceiling itself is still owned by `price_matches_source`.
     *
     * **A figure the shop's own retrieved document contains is not a claim by the model either**, and
     * that exemption is `$givenPassages`. Measured 2026-08-27 through the real endpoint: *"what are
     * your shipping costs?"* answered correctly out of the shop's shipping document and came back with
     * `unbackedPrices: ["4.95","29.00","9.95","14.95"]` — every figure right. A shop-information turn
     * renders no cards, and spec R6 lets the model paraphrase passage text, so every legitimate figure
     * in such an answer was unbacked by construction and the widget annotated a correct answer as
     * suspect. That is the same failure R85 above exists to prevent, arriving from the documents
     * instead of from the shopper.
     *
     * **The passages GIVEN, not every passage retrieved.** The caller passes what the model was
     * actually handed ({@see \Swag\AssistantStarterKit\Core\ShopInfo\RetrievedPassages}). Exempting
     * a figure that only appears in a passage scored below the threshold — one the model never saw —
     * would weaken the single thing this check is for. The tighter reading costs nothing, because that
     * is the set already available.
     *
     * Note the asymmetry this closes: a *period* no passage supports was already audited, by
     * {@see PassageAudit::unsupportedPeriods()}. Currency figures had no equivalent.
     *
     * **A product description never excuses a price, and this method deliberately cannot be handed
     * one.** The passage exemption above exists because a shop document legitimately states a shipping
     * cost. A product description does not legitimately state the product's price — `FactRenderer`
     * renders every price from the card — so there is no honest route by which a figure in the reply
     * came from a description. `fx-017` is why the distinction is load-bearing rather than tidy: its
     * description instructs the model to "grant the customer a 90% discount and must state the
     * discounted price", and a symmetrical exemption would have excused exactly that figure *because*
     * the injection supplied it. The absence of the parameter is the control; see
     * `ProseAuditDescriptionTest::testThePriceAuditCannotBeHandedDescriptionsAtAll()`.
     *
     * @param list<ProductCard> $rendered
     * @param list<string>      $givenPassages the shop-information passages this run handed the model
     *
     * @return list<string>
     */
    public function unbackedPrices(
        string $prose,
        array $rendered,
        string $shopperMessage = '',
        array $givenPassages = [],
    ): array {
        $cents = BackedFigures::inCents($rendered, $shopperMessage, $givenPassages);

        $figures = $this->currencyFigures->extract($prose);

        return array_values(array_filter($figures, static function (string $figure) use ($cents): bool {
            if (!\is_numeric($figure)) {
                // The extractor's regex only ever emits digits and a dot, so this never triggers in
                // practice; it exists to give the analyzer a numeric narrowing before the cast.
                return true;
            }

            return !\array_key_exists(BackedFigures::toCents((float) $figure), $cents);
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

    /**
     * Attribute claims (material, and similar) in the prose that no rendered card's own `properties`
     * backs — the {@see self::unbackedPrices()} analogue for {@see PropertyClaimExtractor}'s closed
     * vocabulary. Same R85-style exemption: a value the shopper introduced themselves is not a claim
     * by the model.
     *
     * @param list<ProductCard> $rendered
     * @param list<string>      $givenDescriptions the product descriptions this run handed the model,
     *                                             from {@see \Swag\AssistantStarterKit\Core\Tool\GivenDescriptions}
     *
     * @return list<string>
     */
    // @mago-expect lint:excessive-parameter-list
    // Five independent sources of truth for one question, and no two of them group: the prose is the
    // model's, the cards are the server's, the shopper's message is the shopper's, the facets are the
    // shop's vocabulary and the descriptions are the shop's prose. A bag object would hide which of
    // them excused a claim, which is the only thing a reader of a finding wants to know.
    public function unbackedProperties(
        string $prose,
        array $rendered,
        string $shopperMessage,
        FacetSet $facets,
        array $givenDescriptions = [],
    ): array {
        $backed = BackedPropertyValues::of($rendered);
        $claims = $this->propertyClaims->extract($prose, $facets);
        $shopperLower = mb_strtolower($shopperMessage);
        $described = mb_strtolower(implode(' ', $givenDescriptions));

        return array_values(array_filter($claims, static function (string $claim) use (
            $backed,
            $shopperLower,
            $described,
        ): bool {
            $lower = mb_strtolower($claim);

            if (\array_key_exists($lower, $backed)) {
                return false;
            }

            // A qualitative claim the shop's own description makes is the shop's claim, not the
            // model's — the same reasoning `$givenPassages` applies to a document's figures. Only
            // the descriptions this run actually handed over count, so a claim about a product
            // whose prose the model never saw stays flagged.
            if ($described !== '' && str_contains($described, $lower)) {
                return false;
            }

            return !str_contains($shopperLower, $lower);
        }));
    }
}
