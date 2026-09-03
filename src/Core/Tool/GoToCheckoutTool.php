<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Reads the shopper's cart so the shop can offer its own checkout link.
 *
 * **Two reported defects, one cause: nothing told the assistant about the cart.** Its only cart
 * input was `cartAvailable: true` — a capability flag, decided per request and never a fact about
 * contents. A card's add-to-cart button posts straight to Shopware's own
 * `frontend.checkout.line-item.add` (see `orb.html.twig`) and reports back to nothing here, so a
 * shopper could fill their cart and then be told by the assistant that it was empty. The model was
 * not guessing; it was answering from the only cart history it had, which was its own.
 *
 * **And "take me to checkout" escalated.** {@see \Swag\AssistantStarterKit\Core\Prompt\SystemPrompt}
 * ends its rules on "You cannot … create orders, take payment", and the escalation clause that
 * follows says "If asked about any of those, escalate" — which is exactly what checkout looks like.
 * The reply then carried the merchant's *contact* link and a sentence promising a checkout link that
 * no code rendered. The prompt now says checkout is not one of those things; this tool is what it
 * has instead.
 *
 * **The model is handed no URL and no figures.** It gets a boolean and a note. That is the same rule
 * as prices and the contact link (D3) — and it is the direct fix for the reported wording: while
 * `AddToCartTool` returned `checkoutUrl` to the model, the model was holding a URL the rules forbade
 * it to write, and "here is a link to the checkout" with no link is the only reply that satisfies
 * both instructions at once. The link is rendered server-side by
 * {@see \Swag\AssistantStarterKit\Controller\CheckoutPayload}, from the route rather than a literal
 * path, because a sales-channel domain can carry a prefix and `/checkout/confirm` is wrong on
 * `example.com/de`.
 *
 * No merchant switch. Unlike `add_to_cart` this writes nothing and unlike `escalate` it needs no
 * configured destination: it reads a cart the shopper already owns and points at the shop's own
 * checkout. A toggle for that would be a setting with no failure mode to protect against. The one
 * gate is `cartAvailable` — see {@see \Swag\AssistantStarterKit\Core\Tool\Factory\GoToCheckoutToolFactory}.
 */
#[AsTool(
    name: 'go_to_checkout',
    description: 'Check whether the shopper has anything in their cart and offer the shop\'s '
    . 'checkout link. Call this whenever they want to check out, pay, or place their order, and '
    . 'whenever they ask what is in their cart: you are not told what the cart holds, they can '
    . 'fill it without you, and this is the only way to find out.',
)]
final class GoToCheckoutTool
{
    public const TRACE_STAGE = 'checkout.offered';

    /**
     * What the model is told when the cart has something in it.
     *
     * The wording follows {@see EscalateTool::NOTE_WITH_DESTINATION}, which earned its shape the
     * hard way: a note that merely described the situation was paraphrased into claims the shop had
     * not made. So this states what to say, prohibits the URL explicitly, and prohibits describing
     * the cart — the boolean above says *something* is in there and nothing more, and a model told
     * only "not empty" will otherwise reach for "you have a few items".
     */
    private const NOTE_READY =
        'The cart has something in it. Say the shopper can go to checkout and that a checkout link '
            . 'follows your message. Do not write a URL yourself. Do not say how many items are in '
            . 'the cart, what they are, or what they cost — you have not been told any of that.';

    /**
     * And when it is empty.
     *
     * It must not announce a link: none is rendered for an empty cart, and a reply promising one is
     * the defect this tool was built to remove rather than relocate.
     */
    private const NOTE_EMPTY =
        'The cart is empty, so there is nothing to check out and no link accompanies your message. '
            . 'Say that plainly and offer to help find something.';

    public function __construct(
        private readonly CommerceGatewayInterface $gateway,
        private readonly TraceRecorder $trace,
    ) {}

    /**
     * @return array{empty: bool, note: string}
     */
    public function __invoke(): array
    {
        // Read live, every call, for the reason `AddToCartTool` reads live: the shopper may have
        // changed the cart in another tab, on the cart page, or with the button beside a card the
        // previous reply rendered. A cached answer would reintroduce the exact defect.
        //
        // `lineItems`, not `itemCount`: a promotion line carries no variant id and no quantity this
        // plugin counts, but a cart holding one is not an empty cart.
        $empty = $this->gateway->cart()->lineItems === [];

        $this->trace->record(self::TRACE_STAGE, ['empty' => $empty]);

        return [
            'empty' => $empty,
            'note' => $empty ? self::NOTE_EMPTY : self::NOTE_READY,
        ];
    }
}
