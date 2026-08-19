<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
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
 * Deliberately does NOT register the added card with {@see \Swag\AssistantStarterKit\Core\Grounding\FactRenderer}:
 * the shopper already chose it, and widening the turn's retrieved set here
 * would let the model re-quote it as a fresh recommendation.
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
    public function __construct(
        private readonly CommerceGatewayInterface $gateway,
        private readonly BlocklistFilter $blocklist,
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

        // Ruling R32 is why maxCartValue reads the live cart total (gateway->cart())
        // rather than trusting the call's own argument; this bound must read the live
        // line quantity the same way. Checking only the increment let repeated small
        // calls accumulate past the limit one call at a time — exactly how a model
        // would do it, and exactly the pattern maxCartValue was already hardened
        // against.
        $existingQuantity = $this->existingLineQuantity($variantId);
        if (($existingQuantity + $quantity) > $this->config->maxItemQuantity) {
            return $this->blocked(PolicyDecision::block('cart_limit', sprintf(
                'You can add at most %d of one item.',
                $this->config->maxItemQuantity,
            )));
        }

        $card = $this->gateway->product($variantId, $this->config->scope);
        if ($card === null) {
            $this->trace->record('tool.call', [
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
        if ($projectedTotal > $this->config->maxCartValue) {
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
        $this->trace->record('tool.call', [
            'name' => 'add_to_cart',
            'policyVerdict' => 'allow',
            'policyReasonCode' => 'allowed',
            'variantId' => $variantId,
            'quantity' => $quantity,
        ]);

        return [
            'cart' => [
                'itemCount' => $cart->itemCount,
                'total' => $cart->total,
                'currency' => $cart->currency,
                'checkoutUrl' => $cart->checkoutUrl,
            ],
            'note' => sprintf('Added %d to the cart.', $quantity),
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
        $this->trace->record('tool.call', [
            'name' => 'add_to_cart',
            'policyVerdict' => 'block',
            'policyReasonCode' => $decision->reasonCode,
        ]);

        return ['note' => $decision->message];
    }
}
