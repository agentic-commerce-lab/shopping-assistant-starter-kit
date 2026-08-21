<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Controller;

use Symfony\Component\HttpFoundation\Request;

/**
 * Identifies the caller a per-client rate window is counted against.
 *
 * **The client IP, not the conversation token.** A token is minted by
 * {@see \Swag\AssistantStarterKit\Core\Trace\ConversationStore::start()} whenever a request arrives
 * without one, so a loop that simply omits it would be handed a fresh allowance every message — a
 * limit that resets itself is not a limit. The address is the cheapest thing a caller cannot rotate
 * for free.
 *
 * `getClientIp()` honours Symfony's trusted-proxy configuration, so a shop behind a load balancer or
 * a CDN needs `framework.trusted_proxies` set correctly or every request arrives from one address and
 * shares one window. That is the shop's existing configuration, not something this plugin can decide:
 * trusting `X-Forwarded-For` from an untrusted source would let a caller pick its own bucket per
 * request, which is worse than one shared bucket.
 *
 * **Hashed, because the result becomes a cache key.** Cache keys surface in Redis dumps, cache
 * viewers and debug output; an IP is personal data. A digest answers "is this the same caller?"
 * without recording who they are, which is all a throttle needs to know.
 */
final readonly class ClientKey
{
    /**
     * A caller with no resolvable address shares one bucket rather than escaping the limit — an
     * empty key would be an unbounded window, which is the failure this class exists to prevent.
     */
    private const UNKNOWN_CALLER = 'unknown';

    public static function of(Request $request, string $salesChannelId): string
    {
        $caller = $request->getClientIp() ?? self::UNKNOWN_CALLER;

        // The channel is part of the digest because the limit is a per-channel merchant setting:
        // without it, one shopper browsing two of a merchant's storefronts shares one allowance.
        return hash('sha256', $salesChannelId . '|' . $caller);
    }
}
