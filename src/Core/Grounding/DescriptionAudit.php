<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Checks a reply's claims about what a product **includes** or is **rated as** against the shop's own
 * prose.
 *
 * The third audit and the third source of truth. {@see ProseAudit} compares prose against the cards
 * the server *rendered*; {@see PassageAudit} against the shop-information documents it *retrieved*;
 * this against the product descriptions it *handed over*. Splitting on the source of truth is the
 * pattern those two already set, and it is what keeps each inside the gate's per-class complexity
 * budget.
 *
 * ## Why the closed-vocabulary audits could not do this
 *
 * Read out of the trace export of 34 real conversations: `validate` reported
 * `inventedProductIds: []` on **all 104 turns** — the card layer was already honest — and the
 * expensive inventions were all in the prose, in claims no vocabulary enumerates. A bracket that
 * does not exist, a security rating nobody issued, a cut time nobody measured.
 * {@see SuppliedFactClaimExtractor} explains how those are found without a list of them.
 *
 * ## Support is asserted, not merely present
 *
 * A claim counts as supported when the shop's prose **asserts** one of its content words, which is
 * strictly narrower than containing it — and the difference is the whole point of reusing
 * {@see PropertyMention::assertedIn()} rather than `str_contains()`:
 *
 * - The lock's description says *"supplied with two keys and no bracket"*. A reply claiming *"the
 *   included bracket"* names a word the description contains, and the description **denies** it. A
 *   containment check would exempt the reply on the strength of the very sentence that refutes it.
 * - The locks say *"no cut-resistance time and no security rating are published for it"*. A reply
 *   claiming *"rated for high-security use"* is contradicted the same way.
 *
 * So this reports two different failures under one name: a claim the shop is **silent** about, and a
 * claim the shop **contradicts**. They are not distinguished in the output, because the remedy is the
 * same and because telling them apart reliably needs to know which product each clause is about —
 * a relevance judgement, the wall `PassageAudit` documents hitting.
 *
 * ## No shopper-message exemption, unlike prices
 *
 * {@see ProseAudit::unbackedPrices()} exempts a figure the shopper introduced (ruling R85) and this
 * deliberately does not, for the reason `PassageAudit` gives about periods: a shopper restating a
 * price is a constraint, but a shopper *asking* "does it come with a bracket?" is asking a question
 * whose answer is exactly the claim at issue. Echoing it back as fact is the failure, not an
 * exemption from it.
 *
 * ## Recorded, not shown to the shopper — for now
 *
 * The caller writes this to `claims.audit` and stops there, which is the stopping point
 * {@see \Swag\AssistantStarterKit\Core\Agent\AssistantRunner::auditPeriods()} chose for the same
 * reason: a detector whose false-positive rate has not been measured against a real corpus must not
 * annotate a shopper's reply. Ruling R85 is the standing warning — a control that fires on correct
 * behaviour teaches shoppers to ignore the ones that matter. Surfacing it is a one-field addition to
 * {@see \Swag\AssistantStarterKit\Core\Agent\Warnings} once the trace says what it costs.
 */
final readonly class DescriptionAudit
{
    public function __construct(
        private SuppliedFactClaimExtractor $claims = new SuppliedFactClaimExtractor(),
        private PropertyClaimExtractor $properties = new PropertyClaimExtractor(),
    ) {}

    /**
     * Scope-of-delivery and certification claims the shop's own prose does not assert.
     *
     * A claim with no content words survives unflagged — *"this includes VAT"* attaches its grammar
     * to nothing checkable, and reporting it would be reporting the absence of a noun.
     *
     * @param list<string> $shopProse the descriptions and passages this run handed the model, as
     *                                {@see \Swag\AssistantStarterKit\Core\Tool\GivenDescriptions::from()}
     *                                and {@see \Swag\AssistantStarterKit\Core\ShopInfo\RetrievedPassages::from()}
     *                                return them
     *
     * @return list<string>
     */
    public function unsupportedFactClaims(string $prose, array $shopProse, FacetSet $facets = new FacetSet()): array
    {
        // Joined with a full stop so one description's last clause cannot form a clause with the
        // next one's first — the clause boundary is what keeps PropertyMention's negation window
        // honest, and concatenating on a space would hand it a sentence the shop never wrote.
        $given = mb_strtolower(implode('. ', $shopProse));

        return array_values(array_filter(
            $this->claims->extract($prose),
            fn(string $claim): bool => !$this->supported($claim, $given, $facets),
        ));
    }

    /**
     * {@see self::unsupportedFactClaims()}, written to `claims.audit` when it finds anything.
     *
     * **Recorded and not surfaced to the shopper**, which is the stopping point
     * {@see \Swag\AssistantStarterKit\Core\Agent\AssistantRunner::auditPeriods()} chose and for the
     * same reason. Replayed over the 104 real replies this fires on four, and all four are the
     * documented inventions — but a precision figure taken from the corpus a detector was written
     * against is not a precision figure, and ruling R85 is unforgiving about a control that fires on
     * correct behaviour. `claims.audit` rather than a new stage, because
     * {@see FactRenderer} already writes unbacked prices there and the Administration already renders
     * it: this arrives where a merchant is already looking for "the sentence disagrees with the
     * facts".
     *
     * `descriptionsGiven` travels with it because zero is the interesting case — a claim made when
     * the server handed over nothing is a different failure from one that contradicts a sentence it
     * was given, and the count is what tells them apart in the trace view.
     *
     * @param list<string> $shopProse
     */
    public function recordUnsupportedFactClaims(
        TraceRecorder $trace,
        string $prose,
        array $shopProse,
        FacetSet $facets = new FacetSet(),
    ): void {
        $unsupported = $this->unsupportedFactClaims($prose, $shopProse, $facets);

        if ($unsupported === []) {
            return;
        }

        $trace->record('claims.audit', [
            'unsupportedFactClaims' => $unsupported,
            'descriptionsGiven' => \count($shopProse),
        ]);
    }

    private function supported(string $claim, string $given, FacetSet $facets): bool
    {
        $tokens = ClaimTokens::of($claim);

        if ($tokens === [] || $this->closedVocabularyOnly($claim, $tokens, $facets)) {
            return true;
        }

        foreach ($tokens as $token) {
            if (PropertyMention::assertedIn($given, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the claim says nothing beyond the shop's own facet vocabulary — in which case it is
     * {@see ProseAudit::unbackedProperties()}'s to judge and not this one's.
     *
     * **The single largest source of false positives, measured.** Replaying this audit over the 104
     * real replies, *"the one rated for trail and gravel"* was reported five times across four
     * conversations. `Trail` and `Gravel` are Terrain values the rendered card carries: the reply is
     * restating an attribute, the closed-vocabulary audit already checks exactly that against the
     * card, and reporting it here would both double-count it and flag a true statement.
     *
     * **Every token, not any.** *"trail-rated helmets"* contributes `trail` — a facet value — and
     * `helmet`, which is not, and that reply is one of the measured inventions. One token outside the
     * vocabulary is enough to make the claim this audit's business.
     *
     * @param list<string> $tokens
     */
    private function closedVocabularyOnly(string $claim, array $tokens, FacetSet $facets): bool
    {
        $vocabulary = mb_strtolower(implode(' ', $this->properties->extract($claim, $facets)));

        if ($vocabulary === '') {
            return false;
        }

        foreach ($tokens as $token) {
            if (!str_contains($vocabulary, $token)) {
                return false;
            }
        }

        return true;
    }
}
