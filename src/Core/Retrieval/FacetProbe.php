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
 * Caches per {@see CatalogScope} for the lifetime of this instance. TTL-based
 * invalidation across requests is a later plan's concern — an instance of
 * this class lives for one request.
 */
final class FacetProbe
{
    /** @var array<string, FacetSet> */
    private array $cache = [];

    public function __construct(
        private readonly CommerceGatewayInterface $gateway,
        private readonly TraceRecorder $trace,
    ) {}

    public function probe(CatalogScope $scope): FacetSet
    {
        $key = self::scopeKey($scope);

        $cached = $this->cache[$key] ?? null;
        if ($cached !== null) {
            $this->trace->record('facet.probe', ['source' => 'cache', 'fields' => $cached->fields()]);

            return $cached;
        }

        $facets = $this->gateway->facets($scope);
        $this->cache[$key] = $facets;
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
