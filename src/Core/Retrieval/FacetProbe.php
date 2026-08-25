<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval;

use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Asks the catalog which filter fields actually exist before any query is
 * built, so {@see QueryBuilder} never has to invent one.
 *
 * **Two tiers.** The array below caches per {@see CatalogScope} for the lifetime of this instance,
 * and an instance lives for one request. Behind it sits an optional {@see SharedFacetCache} that
 * outlives the request — because a measurement said it had to: on a 10,000-product shop the live
 * probe cost 558–578 ms while the search it prepares cost 33–65 ms, and the instance tier meant
 * every turn paid the half-second. The trace names which tier answered: `live`, `shared`, or
 * `cache`.
 *
 * The shared tier is optional, and null means exactly the old behaviour — which is what the eval
 * suite runs, since it builds its own agent with no container.
 *
 * @see docs/superpowers/reports/2026-08-25-catalogue-scale-phase-b.md — Finding 1
 */
final class FacetProbe
{
    /** @var array<string, FacetSet> */
    private array $cache = [];

    public function __construct(
        private readonly CommerceGatewayInterface $gateway,
        private readonly TraceRecorder $trace,
        private readonly ?SharedFacetCache $shared = null,
    ) {}

    public function probe(CatalogScope $scope): FacetSet
    {
        $key = self::scopeKey($scope);

        $cached = $this->cache[$key] ?? null;
        if ($cached !== null) {
            $this->trace->record('facet.probe', ['source' => 'cache', 'fields' => $cached->fields()]);

            return $cached;
        }

        $shared = $this->shared?->get($key);
        if ($shared !== null) {
            $this->cache[$key] = $shared;
            $this->trace->record('facet.probe', ['source' => 'shared', 'fields' => $shared->fields()]);

            return $shared;
        }

        $facets = $this->gateway->facets($scope);
        $this->cache[$key] = $facets;
        $this->shared?->set($key, $facets);
        $this->trace->record('facet.probe', ['source' => 'live', 'fields' => $facets->fields()]);

        return $facets;
    }

    private static function scopeKey(CatalogScope $scope): string
    {
        $json = json_encode($scope);
        if ($json === false) {
            throw new \RuntimeException('Unable to encode catalog scope for facet probe caching.');
        }

        return md5($json);
    }
}
