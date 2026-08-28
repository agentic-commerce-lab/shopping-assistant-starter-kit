<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
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
 * shopper, let alone added.
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
    description: 'Add a specific product variant to the shopper\'s cart. Use get_product '
    . 'first if a size or colour still has to be resolved — this tool cannot resolve '
    . 'options. Returns the cart totals.',
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
     *
     * @return array{cart?: array{itemCount: int, total: float, currency: string, checkoutUrl: string}, note: string}
     */
    public function __invoke(string $variantId, int $quantity = 1): array
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
            'storedQuantity' => $stored,
        ]);

        return [
            'cart' => [
                'itemCount' => $cart->itemCount,
                'total' => $cart->total,
                'currency' => $cart->currency,
                'checkoutUrl' => $cart->checkoutUrl,
            ],
            'note' => CartCorrectionNote::text($stored, $quantity, CartCorrectionNote::reasonFor($cart, $variantId)),
        ];
    }

    private function existingLineQuantity(string $variantId): int
    {
        foreach ($this->gateway->cart()->lineItems as $line) {
            if ($line->variantId === $variantId) {
                return $line->quantity;
            }
        }

        return 0;
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
