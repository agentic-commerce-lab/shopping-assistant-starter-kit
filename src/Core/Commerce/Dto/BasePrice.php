<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

/**
 * What a product costs per unit of measure — the figure German law calls the *Grundpreis*.
 *
 * ## Why it is on the card at all
 *
 * Four chain oils in the seeded catalogue cost €10.00 each. They hold 50 ml, 100 ml, 100 ml and
 * 500 ml, which is €200.00, €100.00, €100.00 and €20.00 per litre — a tenfold spread behind an
 * identical price tag. A shopper shown four cards reading `€10.00` has been given no way to choose,
 * and the storefront those cards link to shows the difference, because the Preisangabenverordnung
 * requires it of anything sold by volume or weight.
 *
 * The assistant was the one surface of the shop that knew less than the others.
 *
 * ## It never reaches the model, and that is deliberate
 *
 * Prices do not cross into the prompt — {@see \Swag\AssistantStarterKit\Core\Tool\ToolProductSummary}
 * carries no figure of any kind, so the model cannot state one and the audit catches it if it tries.
 * A base price is a price. It is computed for the CARD, rendered by the shop, and the model is never
 * told it exists.
 *
 * **Specifically it must not enter {@see \Swag\AssistantStarterKit\Core\Grounding\BackedFigures}.**
 * That class builds the set of figures a reply may contain from the rendered cards' prices, and
 * every figure added to it is one more number a model could hallucinate without being caught. The
 * four oils above would contribute `200.00`, `100.00` and `20.00` — three plausible-looking prices
 * the audit would then wave through. The model has no legitimate reason to say them, so they stay
 * out. {@see \Swag\AssistantStarterKit\Tests\Core\Grounding\BasePriceIsNotABackedFigureTest} pins
 * it.
 */
final readonly class BasePrice
{
    public function __construct(
        /** The price for one `$referenceUnit` of `$unit`, already divided. */
        public float $price,
        /** How much of `$unit` the reference price is quoted per — `1` in `€25.56 / 1 Liter`. */
        public float $referenceUnit,
        /** The unit's own name, as the shop spells it: `Liter`, `Kilogramm`. */
        public string $unit,
    ) {}

    /**
     * The base price of `$price`, or null when this product has no unit of measure.
     *
     * Null rather than zero for everything sold by the piece: a brake pad has no base price, and a
     * figure invented for it would be worse than the silence.
     *
     * **A `purchaseUnit` of zero yields null, not a division by zero.** Shopware permits the field
     * to be set to `0`, and a product configured that way is a broken product rather than a free
     * one — the honest response is to say nothing about its base price.
     */
    public static function of(float $price, ?float $purchaseUnit, ?float $referenceUnit, ?string $unit): ?self
    {
        if ($purchaseUnit === null || $referenceUnit === null || $unit === null || $purchaseUnit <= 0.0) {
            return null;
        }

        return new self(
            // Rounded to the cent, as a price is: 6.39 / 0.25 * 1 is 25.560000000000002 in binary
            // floating point, and a card must not print that.
            price: round(($price / $purchaseUnit) * $referenceUnit, 2),
            referenceUnit: $referenceUnit,
            unit: $unit,
        );
    }
}
