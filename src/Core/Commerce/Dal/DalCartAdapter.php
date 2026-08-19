<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\LineItemFactoryRegistry;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartSummary;
use Symfony\Component\Routing\RouterInterface;

/**
 * The shopper's real Shopware cart, behind the gateway's two cart methods.
 *
 * This is the only write authority in the plugin, and the only place where a mistake has
 * consequences a shopper can see on the cart page. Two things follow from that:
 *
 * - **The cart is always read live, never cached.** `AddToCartTool` enforces `maxItemQuantity` and
 *   `maxCartValue` against `gateway->cart()`, precisely so that repeated small additions cannot
 *   accumulate past either limit one call at a time. A stale read would defeat both guardrails.
 * - **The guardrails are not reimplemented here.** They live in `AddToCartTool`, which is where the
 *   policy decision and its reason code belong. This class puts the line in the cart and reports
 *   what is in it.
 *
 * Currency and totals come from the cart and the sales-channel context, never from a constant: the
 * shopper's own channel decides both.
 */
final readonly class DalCartAdapter
{
    public function __construct(
        private CartService $cartService,
        private LineItemFactoryRegistry $lineItemFactory,
        private RouterInterface $router,
        private DalCartSummariser $summariser = new DalCartSummariser(),
    ) {}

    public function add(string $variantId, int $quantity, SalesChannelContext $context): CartSummary
    {
        $lineItem = $this->lineItemFactory->create([
            'type' => LineItem::PRODUCT_LINE_ITEM_TYPE,
            'referencedId' => $variantId,
            'quantity' => $quantity,
        ], $context);

        $cart = $this->cartService->getCart($context->getToken(), $context);
        $cart = $this->cartService->add($cart, $lineItem, $context);

        return $this->summarise($cart, $context);
    }

    public function summary(SalesChannelContext $context): CartSummary
    {
        return $this->summarise($this->cartService->getCart($context->getToken(), $context), $context);
    }

    private function summarise(Cart $cart, SalesChannelContext $context): CartSummary
    {
        return $this->summariser->summarise(
            $cart,
            $context->getCurrency()->getIsoCode(),
            $this->router->generate('frontend.checkout.confirm.page'),
        );
    }
}
