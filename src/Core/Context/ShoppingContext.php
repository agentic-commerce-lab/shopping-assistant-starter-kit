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
 * guest out of another's transcript is the conversation token itself — and that token is the
 * conversation's own primary key, not a secret minted for this purpose. Shopware 6.7's
 * `Uuid::randomHex()` is a UUIDv7: roughly 48 bits are a millisecond timestamp, so the unpredictable
 * part is the remaining ~74 bits of CSPRNG output, not the full 128. It also does not stay in the
 * browser that was issued it — this repository ships three places it leaves: {@see
 * \Swag\AssistantStarterKit\Core\Trace\Sink\LoggerTraceSink} logs it, {@see
 * \Swag\AssistantStarterKit\Core\Trace\Export\TraceExportSummary} writes it into every exported trace
 * file, and the Administration's trace detail route carries it in the URL. A merchant running with
 * trace logging enabled should treat conversation ids as sensitive. None of this is new in this
 * branch — every row was reachable this way before it too, guest and customer alike; scoping narrows
 * who a *valid* token lets in, it does not change what the token itself is. ~74 bits of unpredictable
 * material is still what stands between one guest and another's transcript, and inventing a guest
 * identity to do better would mean adding a tracking identifier with better manners and no better
 * security.
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
