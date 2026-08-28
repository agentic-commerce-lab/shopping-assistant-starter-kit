<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\GroundingOutputProcessor;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\Facet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Output;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;

/**
 * Split out of {@see GroundingOutputProcessorTest} (too-many-methods) rather than suppressed —
 * this is the third `FacetSet` constructor argument Task 9 wired in, exercised on its own because
 * it needs a facet vocabulary the other cases in that file have no reason to build.
 */
final class GroundingOutputProcessorPropertyClaimTest extends TestCase
{
    public function testFlagsAnUnbackedPropertyClaimWhenFacetsAreProvided(): void
    {
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
        $trace = new TraceRecorder();
        $renderer = new FactRenderer($trace);

        $product = $gateway->product('fx-017', new CatalogScope());
        self::assertNotNull($product);
        $renderer->registerRetrieved([$product]);

        // fx-017's own properties (per the fixture) do not include "Nylon" — see catalog.json.
        $facets = new FacetSet([new Facet('properties.Material', FacetType::Terms, ['Nylon'])]);
        $output = new Output('gpt-x', new TextResult('It is made of Nylon.'), new MessageBag());

        (new GroundingOutputProcessor($renderer, $trace, $facets))->processOutput($output);

        self::assertSame(['Nylon'], $renderer->unbackedProperties());
    }
}
