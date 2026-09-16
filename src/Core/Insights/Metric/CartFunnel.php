<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Insights\Metric;

use Swag\AssistantStarterKit\Core\Insights\ConversationTrace;

/**
 * Conversations, conversations with a cart, conversations that reached checkout.
 *
 * **Per conversation, never per turn.** A shopper who adds twice is one conversation with a cart;
 * counting turns would inflate the middle of the funnel and make the drop-off look better than it
 * is. This is the one metric here a managing director reads first, so it must not flatter.
 */
final readonly class CartFunnel
{
    private function __construct(
        public int $conversations,
        public int $cartAdded,
        public int $checkoutOffered,
    ) {}

    /** @param list<ConversationTrace> $traces */
    public static function of(array $traces): self
    {
        $cart = 0;
        $checkout = 0;

        foreach ($traces as $trace) {
            $outcomes = array_map(
                static fn(array $e): string => (string) ($e['payload']['outcome'] ?? ''),
                $trace->eventsOfStage('turn.end'),
            );

            if (\in_array('cart_added', $outcomes, true)) {
                ++$cart;
            }

            if (\in_array('checkout_offered', $outcomes, true)) {
                ++$checkout;
            }
        }

        return new self(\count($traces), $cart, $checkout);
    }
}
