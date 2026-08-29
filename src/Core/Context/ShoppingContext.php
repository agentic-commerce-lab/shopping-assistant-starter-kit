<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Context;

/**
 * Who is shopping, resolved once per request and compared on every conversation access.
 *
 * Deliberately Shopware-free: it holds resolved facts and asks nothing. Resolution lives in
 * {@see ShoppingContextResolver}, which is the only class here allowed to see a `SalesChannelContext`.
 *
 * **Two guests match, and that is not a hole.** There is no identity to compare, so what keeps one
 * guest out of another's transcript is the conversation token itself — 128 bits of randomness that
 * never leaves the browser that was issued it. Pretending otherwise would mean inventing a guest
 * identity, which is a tracking identifier with better manners and no better security.
 *
 * `employeeId` and `organisationId` are always null today. They are compared anyway so the
 * Commercial bridge only has to populate them: under Shopware's B2B Components every employee of one
 * company presents the *same* customer id, so customer identity alone would let colleagues read each
 * other's conversations.
 */
final readonly class ShoppingContext
{
    public function __construct(
        public ShoppingMode $mode,
        public string $salesChannelId,
        public ?string $customerId = null,
        public ?string $employeeId = null,
        public ?string $organisationId = null,
    ) {}

    public function matches(self $other): bool
    {
        return (
            $this->mode === $other->mode
            && $this->salesChannelId === $other->salesChannelId
            && $this->customerId === $other->customerId
            && $this->employeeId === $other->employeeId
            && $this->organisationId === $other->organisationId
        );
    }
}
