<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Retrieval;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Retrieval\FacetProbe;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

final class FacetProbeTest extends TestCase
{
    public function testProbesOnceAndRecordsCacheHitsInTheTrace(): void
    {
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
        $recorder = new TraceRecorder();
        $probe = new FacetProbe($gateway, $recorder);
        $scope = new CatalogScope();

        $first = $probe->probe($scope);
        $second = $probe->probe($scope);

        self::assertSame($first->fields(), $second->fields());
        self::assertSame('cache', $recorder->payload('facet.probe')['source']);
    }
}
