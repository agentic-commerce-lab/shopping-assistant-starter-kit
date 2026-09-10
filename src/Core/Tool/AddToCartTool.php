<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Policy\PolicyDecision;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * The only tool in this project with authority to change anything, exposed to
 * the model as a Symfony AI tool.
 *
 * Its guardrails are the point, not decoration: a model that adds 999 of an
 * item, or builds a five-figure cart, because nothing told it not to, is a
 * real merchant-facing failure mode. Order of checks matters because it
 * decides which message the shopper sees — the quantity bound is checked
 * before the product is even loaded, so a bad quantity never costs a lookup;
 * the blocklist is checked immediately after the product loads, before any
 * money math, because a blocked product must never be priced for the
 * shopper, let alone added. Right after it, before the cart-value check gets
 * to multiply anything, this tool also refuses a product family — a parent
 * whose `stockSource` is {@see \Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource::Parent}
 * carries the family's aggregate stock and its cheapest variant's price, and
 * pricing that for a cart line is exactly the money math this ordering
 * exists to prevent.
 *
 * The blocklist check here is deliberately explicit, not merely inherited from
 * a scope-honouring gateway: {@see CommerceGatewayInterface::product()} takes a
 * {@see \Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope} so a gateway
 * *can* refuse to return a blocked product, but this is the one tool with
 * write authority and the only one whose mistake has legal consequences (an
 * age-restricted, recalled or region-restricted item reaching a cart), so it
 * does not trust that the gateway remembered.
 *
 * **Correction, 2026-08-20.** This class previously did NOT register the added card, on the grounds
 * that "the shopper already chose it, and widening the turn's retrieved set here would let the model
 * re-quote it as a fresh recommendation". Measured against the real shop, that cost more than it
 * saved: a turn that added Black/M rendered the **parent** product — stock 35 at 79.90 — beside prose
 * that correctly said "Black, size M, €69.90", because `FactRenderer`'s last batch was still whatever
 * the previous search left behind. `claims.audit` then discarded the correct 69.90 as unbacked. The
 * safety net worked; the card was a lie about stock, which is the failure class D4 exists for.
 *
 * The old reasoning was also half wrong about the mechanism. `registerRetrieved()` **replaces** the
 * last batch (which decides what is rendered) and only *adds* to the cumulative authoritative set
 * (which decides what the model may name without being flagged as inventing). So registering here
 * narrows what the shopper sees to the variant they asked for, and the only thing it widens is
 * permission to name a product the shopper explicitly chose — which is not an invention.
 *
 * Availability is not a method on this class. The toolbox is built per
 * request (task 12) and this tool is simply never constructed when
 * `enableAddToCart` is false or no shopper cart exists — that is what keeps
 * "capability control is toolbox construction, never a prompt instruction"
 * true.
 */
#[AsTool(
    name: 'add_to_cart',
    description: 'Add a specific product variant to the shopper\'s cart. Returns the cart totals. '
    . 'For any product that has variants you MUST also pass "options" — the colour, size and so '
    . 'on that THE SHOPPER chose — and they must identify the exact variant you are adding. '
    . 'Copy them verbatim from that product\'s own "options" in the search result: '
    . '"options": [["Colour", "Blue"], ["Size", "L"]]. '
    . 'If the shopper has not said which variant they want, do NOT guess and do not call this '
    . 'tool: ask them, and show the variants. An add without a choice is refused, because a '
    . 'variant the shopper did not pick is a wrong order they only discover after buying it.',
)]
final class AddToCartTool
{
    /**
     * What this tool did, as its own stage rather than a second `tool.call`.
     *
     * **Renamed from `tool.call` on 2026-08-21.** `BoundedToolbox` records `tool.call` when the model
     * asks for a tool; this class recorded `tool.call` again when the tool decided and acted. Two
     * different facts under one name, distinguishable only by their payload keys — read off a live
     * trace:
     *
     * ```
     * 15  tool.call  {"stage":"dispatch","name":"add_to_cart"}
     * 16  tool.call  {"name":"add_to_cart","policyVerdict":"allow","variantId":"a2a2…","quantity":1}
     * ```
     *
     * A merchant reading that sees the same label twice and cannot tell whether it is a duplicate.
     * Worse, the admin trace view maps `tool.call` to its "Understood the question" phase, so the one
     * action in this product that changes anything was filed under understanding it.
     */
    public const TRACE_STAGE = 'cart.add';

    public function __construct(
        private readonly CommerceGatewayInterface $gateway,
        private readonly BlocklistFilter $blocklist,
        private readonly FactRenderer $renderer,
        private readonly TraceRecorder $trace,
        private readonly AssistantConfig $config,
    ) {}

    /**
     * @param string $variantId The exact variant id to add — never a parent product id.
     * @param int    $quantity  How many units to add (1-100, further bounded by the
     *                          shop's own per-item limit).
     * @param ?array<array-key, array<array-key, string>|string> $options The option values THE SHOPPER chose, each a [group, option] pair such as [["Colour", "Blue"], ["Size", "L"]]; a bare option value on its own also works. Required for any product that has variants, and they must identify the exact variant being added — an add the shopper did not choose is refused rather than guessed at.
     *
     * @return array{cart?: array{itemCount: int, total: float, currency: string}, note: string}
     */
    // @mago-expect lint:excessive-parameter-list
    // Three, not two, and the third cannot be folded away: #[AsTool] derives the model-facing JSON
    // Schema from this signature by reflection, so `options` has to be a parameter here to exist at
    // all. It goes last, after `quantity`, so every existing positional call keeps meaning what it
    // meant. Same reasoning as `SearchProductsTool::__invoke()`.
    public function __invoke(string $variantId, int $quantity = 1, ?array $options = null): array
    {
        $variantId = Guard::boundedString($variantId, 64, 'variant_id') ?? '';
        $quantity = Guard::boundedInt($quantity, 1, 100, 'quantity');

        // Both cart limits ship **unlimited** and are skipped entirely when unset. The old defaults
        // — 5 per item, 1000 in cart value — were guesses in an unspecified currency: a furniture
        // shop's single sofa tripped the second one, and neither was protecting the merchant from
        // anything. It is the shopper's own cart, and Shopware's own checkout is what decides
        // whether an order is real. `hasItemQuantityLimit()`/`hasCartValueLimit()` are read rather
        // than the raw fields compared, because a bare `> 0` limit comparison against an unlimited
        // 0 blocks every add — the exact failure this guard is here to avoid.
        //
        // Ruling R32 is why maxCartValue reads the live cart total (gateway->cart())
        // rather than trusting the call's own argument; this bound must read the live
        // line quantity the same way. Checking only the increment let repeated small
        // calls accumulate past the limit one call at a time — exactly how a model
        // would do it, and exactly the pattern maxCartValue was already hardened
        // against.
        $existingQuantity = $this->existingLineQuantity($variantId);
        if ($this->config->hasItemQuantityLimit() && ($existingQuantity + $quantity) > $this->config->maxItemQuantity) {
            return $this->blocked(PolicyDecision::block('cart_limit', sprintf(
                'You can add at most %d of one item.',
                $this->config->maxItemQuantity,
            )));
        }

        $card = $this->gateway->product($variantId, $this->config->scope);
        if ($card === null) {
            $this->trace->record(self::TRACE_STAGE, [
                'name' => 'add_to_cart',
                'policyVerdict' => 'block',
                'policyReasonCode' => 'not_found',
                // The id that was not found, so a merchant reading a trace can tell a model that
                // invented an id apart from a real product this lookup cannot reach. Without it
                // the two are the same event, and telling them apart took a code change.
                'variantId' => $variantId,
            ]);

            return ['note' => 'No such product in this shop.'];
        }

        $filtered = $this->blocklist->apply([$card], $this->config->scope);
        if ($filtered['cards'] === []) {
            return $this->blocked(PolicyDecision::block(
                'blocked_product',
                'This product is not available for purchase.',
            ));
        }

        // A family is not a sellable unit. Shopware's search returns family parents alongside their
        // children, so `product()` answers for a parent id and the card that comes back carries the
        // family's aggregate stock and its cheapest entry price — 19.31 across five variants, in the
        // shop this was measured against. Adding that means a cart line whose colour nobody can name.
        //
        // `card.js` already refuses to render an add button for one. That is a courtesy in the
        // browser; this is the write authority, and the same reasoning that makes this class re-check
        // the blocklist rather than trust the gateway applies here. The reason code is its own, so a
        // merchant reading a trace can tell this apart from a blocklist hit.
        if ($card->stockSource === StockSource::Parent) {
            return $this->blocked(PolicyDecision::block(
                'variant_required',
                'That is a product family rather than a single variant. Ask which options the shopper '
                . 'wants, resolve them with get_product, and add the variant it returns.',
            ));
        }

        // **A variant nobody chose must not reach the cart.** The guard above refuses a family
        // PARENT, which is not a sellable unit; this refuses a real variant the shopper never named,
        // which is the more dangerous case because the cart line is valid and merely wrong. See
        // {@see ChosenVariant} for the reported defect and for why the model cannot fake past it.
        //
        // Placed before the cart-value check for the same reason the blocklist is: a unit the
        // shopper never picked must not be priced for them, let alone added.
        $refusal = ChosenVariant::refusalFor($this->gateway, $this->config->scope, $card, $options);

        if ($refusal !== null) {
            return $this->blocked($refusal);
        }

        $projectedTotal = $this->gateway->cart()->total + ($card->price * $quantity);
        if ($this->config->hasCartValueLimit() && $projectedTotal > $this->config->maxCartValue) {
            return $this->blocked(PolicyDecision::block('cart_limit', sprintf(
                'Adding %d would bring the cart to %.2f %s, above the %.2f limit. Reduce the quantity or remove '
                . 'something else from the cart first.',
                $quantity,
                $projectedTotal,
                $card->currency,
                $this->config->maxCartValue,
            )));
        }

        $cart = $this->gateway->addToCart($variantId, $quantity);

        // What Shopware ACCEPTED, not what was asked for. `ProductCartProcessor` raises an
        // under-minimum line to `minPurchase`, rounds an off-step line onto its step, caps a line
        // at available stock and removes a line with nothing available — recording each as a cart
        // error rather than refusing the call. Reporting the argument instead of the result told a
        // shopper "Added 10 to the cart" over a cart holding 8, and they found out at checkout.
        //
        // The difference, not the line total: the shopper may already have had some of this
        // variant, and `$existingQuantity` was read from the live cart before the write.
        // **A bundle is the exception, because it has no line of its own to count.** Shopware
        // expands it into its member products (see CartCorrectionNote::bundleText()), so the
        // lookup below can only ever return zero for one — which this class then reported as
        // "Nothing was added" over a cart it had just filled. Which of the two a result describes
        // is decided by CartCorrectionNote, where every other wording decision already lives.
        $stored = max(0, CartCorrectionNote::lineQuantity($cart, $variantId) - $existingQuantity);

        // Register the variant that was ACTUALLY added, so it is the card the shopper sees beside
        // the confirmation. Without this, `FactRenderer`'s last registered set is whatever the
        // previous search left behind — measured against the real shop: a turn that added Black/M
        // rendered the PARENT product (stock 35 at 79.90) next to prose correctly saying
        // "Black, size M, €69.90". `claims.audit` caught the resulting mismatch and discarded the
        // 69.90 as unbacked, which is the safety net working — but the card was still wrong, and a
        // UI showing stock 35 for a variant with 3 is a shopper-visible lie.
        //
        // `$filtered['cards']` rather than `$card`: the blocklist has already passed it, so this
        // registers the survivor rather than the pre-check lookup.
        $this->renderer->registerRetrieved($filtered['cards']);

        $this->trace->record(self::TRACE_STAGE, [
            'name' => 'add_to_cart',
            'policyVerdict' => 'allow',
            'policyReasonCode' => 'allowed',
            'variantId' => $variantId,
            // Requested, and kept under its original key so existing trace readers do not shift
            // meaning underneath them. What the cart holds is the new key beside it.
            'quantity' => $quantity,
            // For a bundle there is no line to read a stored quantity off, and reporting the
            // requested figure as though Shopware had confirmed it would be the same guess this
            // stage exists to avoid. The flag says which case a merchant is looking at.
            ...CartCorrectionNote::traceFields($card, $stored),
        ]);

        return [
            'cart' => [
                'itemCount' => $cart->itemCount,
                'total' => $cart->total,
                'currency' => $cart->currency,
                // No `checkoutUrl`. It was here, and the rules forbid the model from writing a URL
                // — so a model that had just added something was holding a checkout address it was
                // not allowed to state, and a live shop answered "here is a link to the checkout"
                // with no link, which is the only reply that satisfies both instructions. The link
                // is the shop's to render: `go_to_checkout` asks for it, `CheckoutPayload` draws it.
            ],
            'note' => CartCorrectionNote::noteFor(
                $card,
                $stored,
                $quantity,
                CartCorrectionNote::reasonFor($cart, $variantId),
            ),
        ];
    }

    /**
     * Delegates to {@see CartCorrectionNote::lineQuantity()} rather than repeating its loop —
     * this class already depends on that one for the note text, and a second copy of the same
     * "find the line for this variant" search is one more place to fix the next time it changes.
     */
    private function existingLineQuantity(string $variantId): int
    {
        return CartCorrectionNote::lineQuantity($this->gateway->cart(), $variantId);
    }

    /** @return array{note: string} */
    private function blocked(PolicyDecision $decision): array
    {
        $this->trace->record(self::TRACE_STAGE, [
            'name' => 'add_to_cart',
            'policyVerdict' => 'block',
            'policyReasonCode' => $decision->reasonCode,
        ]);

        return ['note' => $decision->message];
    }
}
