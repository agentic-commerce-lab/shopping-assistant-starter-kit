<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * FactRenderer is the reason the assistant is structurally incapable of
 * inventing a price, a stock figure, a URL or an image: every shopper-facing
 * figure is substituted back in from the turn's own retrieval — never from
 * anything the model said. The card set to render is not read out of the
 * model's prose; it defaults to whatever the last tool call actually
 * returned, and prose is only ever used to check for invention or to narrow
 * that default (see {@see \Swag\AssistantStarterKit\Core\Agent\GroundingOutputProcessor}
 * for the selection policy).
 *
 * {@see self::registerRetrieved()} builds an id-keyed map of every card this
 * turn actually retrieved — the "authoritative set" — and tracks the most
 * recent call's own ids separately via {@see self::lastRetrievedBatch()}.
 * {@see self::validate()} checks a list of candidate ids (however sourced)
 * against the authoritative set, separating ids the retrieval backs from ids
 * that are invented (hallucinated, or planted by a prompt injection with no
 * other way to reach the shopper). {@see self::render()} then reads the
 * accepted cards straight out of the map, ignoring anything the model claimed
 * about them.
 *
 * {@see self::unbackedPricesInProse()} closes the remaining gap: nothing stops
 * the model's free-text reply from *describing* a price that was never
 * rendered — e.g. a product description that says "state the discounted
 * price" cannot change a card, but it can still talk. This method flags any
 * monetary figure in the prose that no rendered card actually backs, so a
 * caller can refuse to ship that reply, or strip the offending sentence.
 *
 * The authoritative set itself — the id-keyed map plus the case-insensitive lookup
 * a candidate id is resolved through, since case is not meaningful in either a
 * Shopware entity id or this project's fixture ids — is held by
 * {@see RetrievedProductIndex}, not inline here: the case-insensitive lookup this
 * class otherwise needed in both {@see self::validate()} and {@see self::render()}
 * pushed this class's own cyclomatic-complexity total over this project's threshold
 * (mago sums it per class, across every method).
 */
final class FactRenderer
{
    private RetrievedProductIndex $index;

    /** @var list<string> */
    private array $lastBatchIds = [];

    /** @var list<ProductCard> */
    private array $renderedCards = [];

    /** @var list<string> */
    private array $unbackedPrices = [];

    /** @var list<string> */
    private array $unbackedAvailability = [];

    /**
     * The shopper's own message for this turn.
     *
     * Held so the price audit can tell a figure the model *claimed* from one the shopper introduced
     * and the model merely restated — see {@see ProseAudit::unbackedPrices()} and ruling R85.
     */
    private string $shopperMessage = '';

    /** Incremented by {@see self::registerShopperMessage()}. See {@see self::turnSequence()}. */
    private int $turnSequence = 0;

    public function __construct(
        private readonly TraceRecorder $trace,
        private readonly ProseAudit $proseAudit = new ProseAudit(),
    ) {
        $this->index = new RetrievedProductIndex();
    }

    /**
     * @param list<ProductCard> $cards
     */
    public function registerRetrieved(array $cards): void
    {
        $this->index->register($cards);

        // Replaced, never merged — including with an empty $cards array, which resets
        // this to []. If the last tool call returned nothing, the honest default card
        // set is nothing, matching the tool's own "No matching products in this shop."
        // note. See self::lastRetrievedBatch().
        $this->lastBatchIds = array_map(static fn(ProductCard $card): string => $card->id, $cards);
    }

    /**
     * @return list<string> every id this turn has retrieved so far, in registration order
     */
    public function retrievedIds(): array
    {
        return $this->index->ids();
    }

    /**
     * @return list<string> the ids the most recent registerRetrieved() call carried
     */
    public function lastRetrievedBatch(): array
    {
        return $this->lastBatchIds;
    }

    /**
     * @param list<string> $returnedIds
     */
    public function validate(array $returnedIds): ValidationResult
    {
        $accepted = [];
        $invented = [];

        foreach ($returnedIds as $id) {
            $canonicalId = $this->index->canonicalId($id);

            if ($canonicalId !== null) {
                // Accepted carries the CANONICAL id, never the model's casing — render()
                // looks candidates up by exact key, and rendered_ids_exactly compares
                // against the canonical id too.
                $accepted[] = $canonicalId;

                continue;
            }

            // Invented keeps the id as the model actually wrote it, so the trace shows
            // what was really emitted rather than a normalised version of it.
            $invented[] = $id;
        }

        $this->trace->record('validate', [
            'inventedProductIds' => $invented,
            'droppedCount' => \count($invented),
        ]);

        return new ValidationResult($accepted, $invented);
    }

    /**
     * @param list<string> $acceptedIds
     *
     * @return list<ProductCard>
     */
    public function render(array $acceptedIds): array
    {
        $cards = [];
        foreach ($acceptedIds as $id) {
            // Resolved through the same index as validate() — accepted ids are already
            // canonical in practice, but resolving here too means an id reaching render()
            // by any other route still lands on the right card under its canonical id,
            // rather than a silent miss against the exact-case key.
            $canonicalId = $this->index->canonicalId($id);
            $card = $canonicalId !== null ? $this->index->card($canonicalId) : null;
            if ($card !== null) {
                $cards[] = $card;
            }
        }

        $this->renderedCards = $cards;

        $this->trace->record('render', [
            'renderedIds' => $acceptedIds,
            'fieldsSubstituted' => ['price', 'stock', 'url', 'imageUrl'],
        ]);

        return $cards;
    }

    /**
     * @return list<ProductCard> the cards the last {@see self::render()} call produced
     */
    public function renderedCards(): array
    {
        return $this->renderedCards;
    }

    /**
     * @return list<string> the figures the last {@see self::unbackedPricesInProse()} call found
     */
    public function unbackedPrices(): array
    {
        return $this->unbackedPrices;
    }

    /**
     * @return list<string>
     */
    /**
     * Records the shopper's message for this turn, before the model is called.
     *
     * Request-scoped like everything else on this class: one renderer per turn, so there is no way
     * for one shopper's message to reach another's audit.
     *
     * **This is also the turn boundary**, and the only one that exists on the read side.
     * {@see \Swag\AssistantStarterKit\Core\Agent\AssistantRunner::run()} calls it once per turn,
     * on every path that reaches the model, in production and in the eval harness alike — so
     * anything needing to act once per turn can key on {@see self::turnSequence()} rather than being
     * handed a reset from outside and hoping every caller remembers.
     */
    public function registerShopperMessage(string $message): void
    {
        $this->shopperMessage = $message;
        ++$this->turnSequence;
    }

    /**
     * Which turn this renderer is on, counting from 1 at the first
     * {@see self::registerShopperMessage()}.
     *
     * Exists so a collaborator can tell "same turn" from "next turn" without a lifecycle callback.
     * The number itself is meaningless outside that comparison and is deliberately not traced.
     */
    public function turnSequence(): int
    {
        return $this->turnSequence;
    }

    /**
     * `$givenPassages` are the shop-information passages this run handed the model, supplied by the
     * caller rather than read here: this class never touches the trace, and the passages live there
     * because {@see \Swag\AssistantStarterKit\Core\Tool\SearchShopInfoTool} sits on the
     * unprivileged tier and cannot reach a renderer. See `ProseAudit::unbackedPrices()` for why a
     * figure from the shop's own document is not an unbacked claim.
     *
     * @param list<string> $givenPassages
     *
     * @return list<string>
     */
    public function unbackedPricesInProse(string $prose, array $givenPassages = []): array
    {
        $unbacked = $this->proseAudit->unbackedPrices(
            $prose,
            array_values($this->renderedCards),
            $this->shopperMessage,
            $givenPassages,
        );

        $this->unbackedPrices = $unbacked;

        if ($unbacked !== []) {
            $this->trace->record('claims.audit', ['modelClaimsDiscarded' => $unbacked]);
        }

        return $unbacked;
    }

    /**
     * @return list<string> the figures the last {@see self::unbackedAvailabilityInProse()} call found
     */
    public function unbackedAvailability(): array
    {
        return $this->unbackedAvailability;
    }

    /**
     * Availability claims in the prose that the rendered cards contradict.
     *
     * The gap this closes was measured, not theorised: a live turn replied *"Yes, the Trail Jersey is
     * available in Blue, size M"* beside a rendered card reporting **stock 0**, and nothing fired
     * because the audit covered currency figures only. For a sold-out item that is worse than an
     * unbacked price — it is the expectation D4 exists to prevent, arriving through the prose instead
     * of the stock field.
     *
     * **Deliberately narrow, and the narrowness is the design.** A claim is only counted as
     * contradicted when *every* rendered card is out of stock. Two reasons:
     *
     * 1. With a mixed set, "we have it" most likely refers to the in-stock member, and guessing which
     *    product a sentence is about is exactly the kind of inference that produces false positives.
     * 2. Ruling R85: `no_unbacked_price_in_prose` fires on a model merely restating the shopper's own
     *    budget, and **an assertion that fires on correct behaviour trains people to ignore it.**
     *    Narrow and trusted beats broad and disregarded, on a control that must never be doubted.
     *
     * Known limitation, stated rather than hidden: a turn rendering one in-stock card and one
     * sold-out card will not flag a claim about the sold-out one. Widening that needs the claim tied
     * to a specific product, which the prose does not reliably say.
     *
     * A claim with **no** rendered cards at all is also flagged: there is then nothing that could
     * back it.
     *
     * @return list<string>
     */
    public function unbackedAvailabilityInProse(string $prose): array
    {
        $unbacked = $this->proseAudit->unbackedAvailabilityClaims($prose, array_values($this->renderedCards));

        $this->unbackedAvailability = $unbacked;

        if ($unbacked !== []) {
            $this->trace->record('claims.audit', [
                'unbackedAvailabilityClaims' => $unbacked,
                'renderedCardCount' => \count($this->renderedCards),
            ]);
        }

        return $unbacked;
    }

    private static function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
