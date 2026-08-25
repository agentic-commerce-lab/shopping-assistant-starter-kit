<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval;

use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;

/**
 * A facet cache that outlives one request.
 *
 * {@see FacetProbe}'s own array is the fast tier and dies with the request. This is the tier behind
 * it, and the reason it exists is a measurement: on a 10,000-product shop the live probe cost
 * 558–578 ms against 33–65 ms for the search it prepares, and it was paid on every turn because
 * nothing survived between requests.
 *
 * **Deliberately not a PSR-6 pool in this signature.** The seam rule that keeps `Core\` free of
 * Shopware types is worth the same respect for framework types: an implementation may use a Symfony
 * adapter, Redis, or an array, and `Core` should not be able to tell. It also makes the tiering in
 * {@see FacetProbe} testable with ten lines of fake instead of a configured cache pool.
 *
 * **Implementations must isolate tenants.** The DAL derives facets from the sales-channel context, so
 * two channels have genuinely different vocabularies. A key that ignores the channel would serve one
 * shop's spellings to another — see
 * {@see \Swag\AssistantStarterKit\Core\Commerce\Dal\PooledFacetCache}, which folds the channel id
 * into the key for exactly that reason. `$scopeKey` alone is NOT a sufficient key.
 *
 * @see docs/superpowers/reports/2026-08-25-catalogue-scale-phase-b.md — Finding 1
 */
interface SharedFacetCache
{
    /** Null on a miss, so a caller can tell "absent" from "empty facet set". */
    public function get(string $scopeKey): ?FacetSet;

    public function set(string $scopeKey, FacetSet $facets): void;
}
