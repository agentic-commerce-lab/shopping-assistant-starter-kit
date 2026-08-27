<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

/**
 * Checks a shopper-facing reply against the shop-information passages the server retrieved.
 *
 * The counterpart of {@see ProseAudit}, and separate from it because the source of truth is different:
 * that class compares prose against the product cards the server *rendered*, this one against document
 * text the server *retrieved*. Splitting also keeps each inside Mago's per-class budget, which is the
 * same reason `ProseAudit` was itself split out of {@see FactRenderer}.
 *
 * Stateless, so it is trivially testable against a hand-written passage list.
 */
final readonly class PassageAudit
{
    public function __construct(
        private PeriodClaimExtractor $periodClaims = new PeriodClaimExtractor(),
    ) {}

    /**
     * Periods the reply states that no retrieved shop-information passage supports.
     *
     * The third kind of contradiction, and the one shop information introduces. Spec R6 hands the
     * model passage *text* and lets it paraphrase, and spec R3a then makes the model responsible for
     * noticing when a retrieved passage does not answer the question. Both are necessary and neither
     * is a control: the failure they leave open is a reply stating a revocation deadline, a retention
     * period or a warranty term the merchant's documents never granted. Unlike a wrong price, **no
     * card contradicts it** — there is nothing rendered beside the sentence to check it against, which
     * is what makes it the shop-information analogue of an availability claim.
     *
     * **Compared as normalised periods, never as words.** The model is *supposed* to paraphrase, so a
     * passage saying "binnen dreissig Tagen" legitimately becomes "30 Tage" — measured on the lab
     * shop. A substring check would report that correct answer as an invention, and this class already
     * knows from {@see self::unbackedPrices()} what a warning that fires on correct behaviour costs.
     * {@see PeriodClaimExtractor} reduces both sides to one token per period first.
     *
     * **No shopper-message exemption here, unlike prices** (ruling R85), and the asymmetry is
     * deliberate. A shopper restating a price ceiling is a constraint; a shopper *asking* "do I get 30
     * days to return this?" is asking a question whose answer is exactly the number at issue. Echoing
     * it back as fact is the failure, not an exemption from it.
     *
     * **What it cannot see, measured rather than reasoned about.** It checks whether a *number* the
     * reply states appears in the passages — not whether that number applies to what was asked. Run
     * against a weak 8B model on 2026-08-26, three inventions came back and this caught one:
     *
     * - *"The statutory warranty in Germany is two years"* — **caught**, `2 year` appears nowhere.
     * - *"the cooling-off period for custom-made orders is 14 days"* — **missed**, and it is the worse
     *   error: the document *excludes* custom items from withdrawal entirely, so the reply states the
     *   opposite of the source while quoting a number the source does contain.
     * - *"you have 14 days to cancel a subscription"* — **missed**, same shape.
     *
     * So this is a floor, not a guarantee. Catching a misapplied number needs something that can tell
     * which claim a passage supports, which is a relevance judgement rather than a lookup — the same
     * wall spec R3a hit. The strong model got all three right, which is the honest summary: the
     * primary control on this class of error remains the model, and this is what notices when the
     * model is not up to it.
     *
     * @param list<string> $passages the retrieved passage texts the model was given
     *
     * @return list<string>
     */
    public function unsupportedPeriods(string $prose, array $passages): array
    {
        $supported = [];

        foreach ($passages as $passage) {
            foreach ($this->periodClaims->extract($passage) as $period) {
                $supported[PeriodEquivalence::keyFor($period)] = true;
            }
        }

        $stated = $this->periodClaims->extract($prose);

        // Compared by equivalence key rather than by string: a passage granting fourteen days supports
        // a reply saying two weeks, and calling that an invention would be the false alarm that makes
        // the whole check ignorable — see PeriodEquivalence.
        return array_values(array_filter(
            $stated,
            static fn(string $period): bool => !isset($supported[PeriodEquivalence::keyFor($period)]),
        ));
    }
}
