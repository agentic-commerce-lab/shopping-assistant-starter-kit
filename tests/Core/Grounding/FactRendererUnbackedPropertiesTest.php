<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\Facet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

final class FactRendererUnbackedPropertiesTest extends TestCase
{
    public function testFlagsAndRecordsAnUnbackedPropertyClaim(): void
    {
        $trace = new TraceRecorder();
        $renderer = new FactRenderer($trace);

        $card = new ProductCard(
            id: 'fx-001',
            parentId: null,
            name: 'Trail Jersey',
            description: null,
            price: 54.90,
            currency: 'EUR',
            stock: 5,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/p/fx-001',
            imageUrl: null,
            properties: ['Material' => ['Merino']],
        );

        $renderer->registerRetrieved([$card]);
        $renderer->render(['fx-001']);

        $facets = new FacetSet([new Facet('properties.Material', FacetType::Terms, ['Merino', 'Nylon'])]);
        $unbacked = $renderer->unbackedPropertiesInProse('It is Nylon.', $facets);

        self::assertSame(['Nylon'], $unbacked);
        self::assertSame(['Nylon'], $renderer->unbackedProperties());
    }

    public function testABackedClaimFlagsNothing(): void
    {
        $trace = new TraceRecorder();
        $renderer = new FactRenderer($trace);

        $card = new ProductCard(
            id: 'fx-001',
            parentId: null,
            name: 'Trail Jersey',
            description: null,
            price: 54.90,
            currency: 'EUR',
            stock: 5,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/p/fx-001',
            imageUrl: null,
            properties: ['Material' => ['Merino']],
        );

        $renderer->registerRetrieved([$card]);
        $renderer->render(['fx-001']);

        $facets = new FacetSet([new Facet('properties.Material', FacetType::Terms, ['Merino'])]);

        self::assertSame([], $renderer->unbackedPropertiesInProse('It is Merino.', $facets));
    }
}
