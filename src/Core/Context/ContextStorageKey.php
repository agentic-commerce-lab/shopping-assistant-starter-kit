<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Context;

/**
 * The `sessionStorage` slot a shopper's conversation token lives in.
 *
 * **A slot name, never a credential.** It reaches the browser, so it carries no customer id, no
 * organisation id and nothing reversible: it is an HMAC over the canonical scope using the shop's
 * kernel secret. A shopper who edits it selects a different slot and gets a token the server then
 * refuses, because the token is validated against the *actual* request context and not against
 * whatever key it arrived beside.
 *
 * Keyed by the kernel secret rather than plain hashing so that two shops sharing a browser profile —
 * a staging and a live domain on the same host, say — cannot collide, and so the key cannot be
 * recomputed off-site from a guessed customer id.
 *
 * Truncated to 128 bits: this names one of a handful of slots in one browser session, and a full
 * SHA-256 in a storage key is noise.
 */
final readonly class ContextStorageKey
{
    public function __construct(
        #[\SensitiveParameter]
        private string $secret,
    ) {}

    public function for(ShoppingContext $context): string
    {
        // Field-separated rather than concatenated: without the separator a customer id ending in
        // the channel id's first characters could canonicalise to the same string as a different
        // pairing, and two scopes sharing a slot is the one failure this class must not have.
        $canonical = implode("\0", [
            $context->mode->value,
            $context->salesChannelId,
            $context->customerId ?? '',
            $context->employeeId ?? '',
            $context->organisationId ?? '',
        ]);

        return substr(hash_hmac('sha256', $canonical, $this->secret), 0, 32);
    }
}
