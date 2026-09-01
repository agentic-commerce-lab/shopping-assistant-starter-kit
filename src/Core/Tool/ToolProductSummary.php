<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * The shape a tool returns for a retrieved product: **id, name and option values — and nothing
 * else.**
 *
 * ## Why this exists at all
 *
 * Tools used to return bare ids, which is the strictest possible reading of D3 ("the model emits
 * ids, the server renders facts"). The first trace ever read end to end on this branch showed what
 * that costs. Asked *"do you have the trail jersey in blue, size M?"* against the real catalogue,
 * the model searched by term, received **seven opaque ids**, and then called `get_product` once per
 * id purely to find out which was which. It exhausted `maxToolCallsPerTurn` on the fifth call and
 * the turn ended in `tool_limit_exceeded`.
 *
 * That is not a model weakness, it is arithmetic: **with opaque ids, identifying one of N candidates
 * costs N tool calls**, so any family larger than the call budget is unanswerable. Against the
 * fixture catalogue no family exceeds four variants, which is why it looked like run-to-run variance
 * instead of a structural bound — and it is the most likely real cause of the handoff's known-issue 4
 * (`cart_add` 0/3, "2 of 6 runs exhausted the tool-call budget"): the budget is spent on identifying
 * products before `add_to_cart` is ever reachable.
 *
 * ## Why this does not weaken D3
 *
 * D3's substance is that **the model never supplies a figure**. Nothing here is a figure: no price,
 * no stock, no delivery time, no availability. Those are still rendered exclusively by
 * {@see \Swag\AssistantStarterKit\Core\Grounding\FactRenderer} from the card the server holds.
 *
 * Option values are not new information to the model either — since ruling R54 the system prompt
 * already carries the catalogue's own vocabulary, including every option value. So this opens no
 * fabrication surface that was not already open; it only lets the model tell two retrieved products
 * apart without paying a round trip for each.
 *
 * **`soldOut` is the one buyability signal, and only in the negative.** It is a boolean, not a
 * quantity — no count, no threshold, no "low stock" — so nothing here lets the model quote a figure it
 * did not earn. See {@see \Swag\AssistantStarterKit\Tests\Core\Tool\ToolProductSummarySoldOutTest}
 * for the live turn that made it necessary and for why there is deliberately no `soldOut: false`.
 *
 * **Never widen this with a figure.** A price or stock number here would let the model quote a figure
 * it did not have to earn — the one thing this whole pipeline exists to prevent. `properties` is a
 * deliberate exception: it is closed-vocabulary (drawn from the shop's own facet values) and audited
 * by {@see \Swag\AssistantStarterKit\Core\Grounding\ProseAudit::unbackedProperties()}, the same way
 * price is audited.
 *
 * **`description` was the second exception, and only through {@see self::withDescriptions()}.** This
 * class used to say it "must never be added here", on the grounds that free text has no closed
 * vocabulary to audit against. That reasoning still holds for `search_products`, which is why
 * {@see self::of()} is unchanged. What it cost elsewhere was measured on 2026-08-31: `sk-101 Trail
 * Helmet` and `bk-helmet-gravel` carry identical properties, so on everything the model could see they
 * were the same product. The design and its limits are in
 * `docs/superpowers/specs/2026-08-31-product-descriptions-in-the-comparison-path-design.md`.
 */
final class ToolProductSummary
{
    private function __construct() {}

    /**
     * @param list<ProductCard>           $cards
     * @param array<string, list<string>> $reasons reason codes keyed by product id, from
     *                                              {@see MatchReasons::of()} — empty unless
     *                                              enableMatchReasons is on
     *
     * @return list<array{id: string, name: string, options: array<string, string>, properties: array<string, list<string>>, soldOut?: true, reasons?: list<string>}>
     */
    public static function of(array $cards, array $reasons = []): array
    {
        return array_map(
            static function (ProductCard $card) use ($reasons): array {
                $summary = [
                    'id' => $card->id,
                    'name' => $card->name,
                    'options' => $card->options,
                    // Structured, closed-vocabulary attributes only (material, and similar) — never
                    // `description`, which is free text with no closed vocabulary to audit against. See
                    // BoundedProperties for the per-product cap, and ProseAudit::unbackedProperties() for
                    // the audit this now requires: a value stated in prose must be backed by a rendered
                    // card.
                    'properties' => BoundedProperties::of($card->properties),
                ];

                // **Only ever true, never false.** An absent key means what it always meant: the model
                // has been told nothing about buyability and may claim none. A `false` would be a
                // statement the model could repeat as "this is available", which is the one claim the
                // shop must render from its own record.
                //
                // Added after a live turn offered to add an out-of-stock product to the cart. It
                // claimed no availability — the audit was satisfied — but it proposed something that
                // cannot happen, because it had no way to know. See ToolProductSummarySoldOutTest for
                // why the direction is chosen by what each error costs.
                if (!$card->isInStock()) {
                    $summary['soldOut'] = true;
                }

                $codes = $reasons[$card->id] ?? [];

                if ($codes !== []) {
                    $summary['reasons'] = $codes;
                }

                return $summary;
            },
            $cards,
        );
    }

    /**
     * {@see self::of()} plus each product's own description, for the comparison path only.
     *
     * **A named method rather than a flag on `of()`**, so the call site says which contract it asked
     * for. `search_products` must keep the narrow shape — its minimality has its own measured reason,
     * the tool-call blow-up described above — and a boolean argument makes that distinction invisible
     * at exactly the place a reviewer looks.
     *
     * A product with nothing to say gets **no key at all**, not an empty one. An empty string is a
     * value the model can reason about, and "the shop says nothing about this product" is an inference
     * worth denying it.
     *
     * The excerpt is plain prose, capped, and never a defence — see
     * {@see DescriptionExcerpt} for what the cap is and is not.
     *
     * @param list<ProductCard>           $cards
     * @param array<string, list<string>> $reasons
     *
     * @return list<array{id: string, name: string, options: array<string, string>, properties: array<string, list<string>>, soldOut?: true, reasons?: list<string>, description?: string}>
     */
    public static function withDescriptions(array $cards, array $reasons = []): array
    {
        $summaries = self::of($cards, $reasons);

        foreach ($cards as $index => $card) {
            $excerpt = DescriptionExcerpt::of($card->description);

            if ($excerpt !== '') {
                $summaries[$index]['description'] = $excerpt;
            }
        }

        return $summaries;
    }
}
