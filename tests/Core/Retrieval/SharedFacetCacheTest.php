<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Retrieval;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Retrieval\FacetProbe;
use Swag\AssistantStarterKit\Core\Retrieval\SharedFacetCache;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * The cross-request tier of the facet probe.
 *
 * Measured on a real 10,000-product shop before this existed: the probe cost 558–578 ms live and
 * 0.3–6 ms once cached, but the cache lived only as long as its own instance — and an instance lives
 * for one request. So every turn that reached retrieval paid the full half-second, roughly ten times
 * the search it exists to prepare.
 *
 * @see docs/superpowers/reports/2026-08-25-catalogue-scale-phase-b.md — Finding 1
 */
final class SharedFacetCacheTest extends TestCase
{
    private static function gateway(): FixtureCommerceGateway
    {
        return FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
    }

    public function testAColdSharedCacheIsPopulatedAndTheProbeReportsLive(): void
    {
        $shared = new InMemorySharedFacetCache();
        $recorder = new TraceRecorder();

        $facets = (new FacetProbe(self::gateway(), $recorder, $shared))->probe(new CatalogScope());

        self::assertNotSame([], $facets->facets);
        self::assertSame('live', $recorder->payload('facet.probe')['source']);
        self::assertSame(1, $shared->writes, 'a live probe must populate the shared cache');
    }

    /**
     * The whole point: a SECOND probe instance — which is what a second request gets — must not go
     * to the gateway at all.
     */
    public function testASecondInstanceReadsTheSharedCacheInsteadOfTheGateway(): void
    {
        $shared = new InMemorySharedFacetCache();
        $scope = new CatalogScope();

        (new FacetProbe(self::gateway(), new TraceRecorder(), $shared))->probe($scope);

        $recorder = new TraceRecorder();
        $countingGateway = new CountingFacetGateway(self::gateway());
        (new FacetProbe($countingGateway, $recorder, $shared))->probe($scope);

        self::assertSame(0, $countingGateway->facetCalls, 'the second request must not hit the catalogue');
        self::assertSame('shared', $recorder->payload('facet.probe')['source']);
    }

    /** The instance tier still wins, and still says `cache`, so existing traces keep their meaning. */
    public function testTheInstanceTierIsStillPreferredAndStillNamedCache(): void
    {
        $shared = new InMemorySharedFacetCache();
        $recorder = new TraceRecorder();
        $probe = new FacetProbe(self::gateway(), $recorder, $shared);
        $scope = new CatalogScope();

        $probe->probe($scope);
        $probe->probe($scope);

        self::assertSame('cache', $recorder->payload('facet.probe')['source']);
        self::assertSame(1, $shared->writes, 'the second probe must not rewrite the shared entry');
    }

    /** Without a shared cache the class behaves exactly as it did before — the eval suite's path. */
    public function testWithoutASharedCacheNothingChanges(): void
    {
        $recorder = new TraceRecorder();
        $probe = new FacetProbe(self::gateway(), $recorder);
        $scope = new CatalogScope();

        $probe->probe($scope);
        self::assertSame('live', $recorder->payload('facet.probe')['source']);

        $probe->probe($scope);
        self::assertSame('cache', $recorder->payload('facet.probe')['source']);
    }

    public function testADifferentScopeIsADifferentEntry(): void
    {
        $shared = new InMemorySharedFacetCache();
        $probe = new FacetProbe(self::gateway(), new TraceRecorder(), $shared);

        $probe->probe(new CatalogScope());
        $probe->probe(new CatalogScope(includeCategoryIds: ['bikes']));

        self::assertSame(2, $shared->writes, 'scopes must not share one entry');
    }
}

/** @internal a shared cache that lives in this process, standing in for the pool */
final class InMemorySharedFacetCache implements SharedFacetCache
{
    public int $writes = 0;

    /** @var array<string, FacetSet> */
    private array $entries = [];

    public function get(string $scopeKey): ?FacetSet
    {
        return $this->entries[$scopeKey] ?? null;
    }

    public function set(string $scopeKey, FacetSet $facets): void
    {
        ++$this->writes;
        $this->entries[$scopeKey] = $facets;
    }
}
