<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Grounding;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * FactRenderer is the reason the assistant is structurally incapable of
 * inventing a price, a stock figure, a URL or an image: the model only ever
 * emits product ids, and every shopper-facing figure is substituted back in
 * from the turn's own retrieval — never from anything the model said.
 *
 * {@see self::registerRetrieved()} builds an id-keyed map of every card this
 * turn actually retrieved — the "authoritative set". {@see self::validate()}
 * checks the model's returned ids against that set, separating ids the
 * retrieval backs from ids the model invented (hallucinated, or planted by a
 * prompt injection with no other way to reach the shopper). {@see
 * self::render()} then reads the accepted cards straight out of the map,
 * ignoring anything the model claimed about them.
 *
 * {@see self::unbackedPricesInProse()} closes the remaining gap: nothing stops
 * the model's free-text reply from *describing* a price that was never
 * rendered — e.g. a product description that says "state the discounted
 * price" cannot change a card, but it can still talk. This method flags any
 * monetary figure in the prose that no rendered card actually backs, so a
 * caller can refuse to ship that reply, or strip the offending sentence.
 */
final class FactRenderer
{
    /** @var array<string, ProductCard> */
    private array $retrieved = [];

    /** @var list<ProductCard> */
    private array $renderedCards = [];

    /** @var list<string> */
    private array $unbackedPrices = [];

    public function __construct(
        private readonly TraceRecorder $trace,
        private readonly CurrencyFigureExtractor $currencyFigureExtractor = new CurrencyFigureExtractor(),
    ) {}

    /**
     * @param list<ProductCard> $cards
     */
    public function registerRetrieved(array $cards): void
    {
        foreach ($cards as $card) {
            // A later registration for the same id overwrites the earlier one:
            // a resolved variant supersedes its parent.
            $this->retrieved[$card->id] = $card;
        }
    }

    /**
     * @param list<string> $returnedIds
     */
    public function validate(array $returnedIds): ValidationResult
    {
        $accepted = [];
        $invented = [];

        foreach ($returnedIds as $id) {
            if (\array_key_exists($id, $this->retrieved)) {
                $accepted[] = $id;

                continue;
            }

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
            $card = $this->retrieved[$id] ?? null;
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
    public function unbackedPricesInProse(string $prose): array
    {
        // Compare numerically, in integer cents rather than as formatted strings or raw
        // floats: the brief's regex accepts a whole-euro figure like "24" with no decimals,
        // which would never string-match a card price formatted to two decimals ("24.00"),
        // producing a false positive on a model reply that got the price exactly right.
        // Cents avoid both that mismatch and float rounding error; PHP would also silently
        // truncate a float used directly as an array key, so cents are cast to int instead.
        $renderedPriceCents = [];
        foreach ($this->renderedCards as $card) {
            $renderedPriceCents[self::toCents($card->price)] = true;
        }

        $figures = $this->currencyFigureExtractor->extract($prose);

        $unbacked = array_values(array_filter($figures, static function (string $figure) use (
            $renderedPriceCents,
        ): bool {
            if (!\is_numeric($figure)) {
                // The extractor's regex only ever emits digits and a dot, so this
                // never triggers in practice; it exists purely to give the analyzer
                // a numeric-string narrowing before the cast below.
                return true;
            }

            return !\array_key_exists(self::toCents((float) $figure), $renderedPriceCents);
        }));

        $this->unbackedPrices = $unbacked;

        if ($unbacked !== []) {
            $this->trace->record('claims.audit', ['modelClaimsDiscarded' => $unbacked]);
        }

        return $unbacked;
    }

    private static function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
