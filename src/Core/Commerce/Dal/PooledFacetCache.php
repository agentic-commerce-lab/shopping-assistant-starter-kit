<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Retrieval\SharedFacetCache;

/**
 * The cross-request facet cache, backed by a PSR-6 pool and scoped to one sales channel.
 *
 * Why it exists is a number: on a 10,000-product shop the live facet probe cost 558–578 ms while the
 * search it prepares cost 33–65 ms, and {@see \Swag\AssistantStarterKit\Core\Retrieval\FacetProbe}
 * cached only for its own instance — which lives for one request. Every turn paid the half-second.
 *
 * **The sales channel is part of the key, and that is not optional.**
 * {@see DalCommerceGateway::facets()} builds its criteria from
 * `$context->getSalesChannelId()`, so two channels legitimately have different vocabularies. Keying
 * on the catalogue scope alone would let one shop read another's spellings out of the cache — a
 * cross-tenant leak created by a performance fix, which is a bad trade at any speed.
 *
 * **This lives in `Dal\` rather than `Core\Retrieval\` on purpose.** It needs
 * {@see SalesChannelContextProvider}, and the seam rule keeps `Core\` free of anything that knows
 * what a sales channel is. `Core` sees only the {@see SharedFacetCache} interface.
 */
final class PooledFacetCache implements SharedFacetCache
{
    /**
     * How long a cached vocabulary may be stale.
     *
     * Five minutes, chosen against what being wrong costs rather than what feels tidy: the block is
     * explicitly a *vocabulary*, not an inventory — `CatalogVocabulary`'s heading says so and the
     * `vocabulary_not_inventory` journey enforces it. So a stale entry cannot misreport stock or
     * price; the worst case is that a word added to the catalogue five minutes ago is not yet offered
     * as a spelling, and the disclosure note already tells the model the list may be incomplete.
     *
     * Against that: at ~560 ms a miss, a shorter TTL buys freshness nobody asked for at a price
     * every shopper pays.
     */
    public const TTL_SECONDS = 300;

    /**
     * Bump to invalidate every entry at once.
     *
     * Needed because a PSR-6 pool stores serialized {@see FacetSet} objects: if that DTO's shape ever
     * changes, old bytes would deserialize into the new class and produce a subtly wrong object
     * rather than a clean miss. A key nobody can hit is the cheapest possible migration.
     */
    private const VERSION = 'v1';

    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly SalesChannelContextProvider $contexts,
    ) {}

    /** Dots and hex only: PSR-6 reserves `{}()/\@:` and lets a pool reject a key that uses them. */
    public static function cacheKey(string $salesChannelId, string $scopeKey): string
    {
        return \sprintf('swag_assistant.facets.%s.%s.%s', self::VERSION, $salesChannelId, $scopeKey);
    }

    public function get(string $scopeKey): ?FacetSet
    {
        try {
            $item = $this->pool->getItem($this->key($scopeKey));
        } catch (InvalidArgumentException) {
            // A rejected key means no cache, not a broken turn. See the note on set().
            return null;
        }

        if (!$item->isHit()) {
            return null;
        }

        $cached = $item->get();

        // A pool can hand back anything a previous version of this code put there. Anything that is
        // not a FacetSet is treated as a miss rather than trusted or thrown over: a
        // benchmark-driven cache must never be the reason a shopper sees an error.
        return $cached instanceof FacetSet ? $cached : null;
    }

    /**
     * A failure to cache is swallowed on purpose.
     *
     * This class exists to save ~560 ms; it is not allowed to cost correctness or availability. A
     * pool that rejects the key, or cannot write, degrades to what the code did before it existed —
     * a live probe every request.
     *
     * That is not silent, though, which is what makes swallowing acceptable: `FacetProbe` records
     * `source: live` or `source: shared` on every `facet.probe` trace event, so a cache that never
     * works shows up as `live` on every turn in the trace view and in
     * `swag:assistant:benchmark`. A broken cache is visible without being fatal.
     */
    public function set(string $scopeKey, FacetSet $facets): void
    {
        try {
            $item = $this->pool->getItem($this->key($scopeKey));
            $item->set($facets);
            $item->expiresAfter(self::TTL_SECONDS);

            $this->pool->save($item);
        } catch (InvalidArgumentException) {
            return;
        }
    }

    private function key(string $scopeKey): string
    {
        return self::cacheKey($this->contexts->current()->getSalesChannelId(), $scopeKey);
    }
}
