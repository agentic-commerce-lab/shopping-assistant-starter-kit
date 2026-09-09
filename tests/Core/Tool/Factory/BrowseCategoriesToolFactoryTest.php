<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool\Factory;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\CategoryTreeReader;
use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Grounding\VariantResolver;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Retrieval\FacetProbe;
use Swag\AssistantStarterKit\Core\Retrieval\QueryBuilder;
use Swag\AssistantStarterKit\Core\Tool\BrowseCategoriesTool;
use Swag\AssistantStarterKit\Core\Tool\Factory\BrowseCategoriesToolFactory;
use Swag\AssistantStarterKit\Core\Tool\Factory\GroundedToolContext;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * `CategoryTreeReader` is optional, so the tool is a capability of the gateway rather than a
 * merchant setting. A gateway that cannot describe its tree cannot answer an assortment question,
 * and the model must not be shown a tool that would fail — capability control is toolbox
 * construction (D6).
 */
#[CoversClass(BrowseCategoriesToolFactory::class)]
final class BrowseCategoriesToolFactoryTest extends TestCase
{
    public function testAGatewayThatReadsItsTreeGetsTheTool(): void
    {
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../../../Fixtures/catalog.json');

        self::assertInstanceOf(CategoryTreeReader::class, $gateway, 'the fixture gateway reads its tree');
        self::assertInstanceOf(
            BrowseCategoriesTool::class,
            (new BrowseCategoriesToolFactory())->create($this->context($gateway)),
        );
    }

    /**
     * A stub of the gateway interface alone, so it implements exactly that and not the optional
     * `CategoryTreeReader` beside it — which is the shape of a contributed gateway that skipped it.
     */
    public function testAGatewayWithoutACategoryTreeNeverSeesIt(): void
    {
        $gateway = self::createStub(CommerceGatewayInterface::class);

        self::assertNotInstanceOf(CategoryTreeReader::class, $gateway);
        self::assertNull((new BrowseCategoriesToolFactory())->create($this->context($gateway)));
    }

    private function context(CommerceGatewayInterface $gateway): GroundedToolContext
    {
        $trace = new TraceRecorder();

        return new GroundedToolContext(
            gateway: $gateway,
            trace: $trace,
            config: new AssistantConfig(),
            renderer: new FactRenderer($trace),
            facetProbe: new FacetProbe($gateway, $trace),
            blocklist: new BlocklistFilter(),
            variantResolver: new VariantResolver($gateway, $trace),
            queryBuilder: new QueryBuilder(),
            cartAvailable: false,
        );
    }
}
