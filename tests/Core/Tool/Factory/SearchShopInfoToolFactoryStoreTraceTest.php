<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool\Factory;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Config\SystemConfigLlmSettings;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\ShopInfo\EmbedderFactory;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoAvailability;
use Swag\AssistantStarterKit\Core\ShopInfo\VectorSupport;
use Swag\AssistantStarterKit\Core\Tool\Factory\SearchShopInfoToolFactory;
use Swag\AssistantStarterKit\Core\Tool\Factory\ToolContext;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Swag\AssistantStarterKit\ShopInfo\DalPortablePassageStore;
use Swag\AssistantStarterKit\Tests\Core\Config\FakeSystemConfigService;

/**
 * The evidence spec D6 requires.
 *
 * Moving `symfony/ai-maria-db-store` to `suggest` changes behaviour for MariaDB shops in a way that is
 * easy to miss, because retrieval still works: package absent means an O(n) scan instead of an index,
 * experienced only as "it got slower". Replacing an honest error with an invisible degradation is the
 * opposite of what the rest of this project does, so the choice is recorded on every turn.
 *
 * Recorded by the FACTORY, not the store: `PassageStore` is published as an extension point in
 * `docs/extending.md`, and a new method on it would break every store somebody else wrote.
 */
final class SearchShopInfoToolFactoryStoreTraceTest extends TestCase
{
    private function availability(bool $vectorsWork, bool $nativeStoreInstalled = true): ShopInfoAvailability
    {
        $vectors = new class($vectorsWork) implements VectorSupport {
            public function __construct(
                private readonly bool $works,
            ) {}

            public function isAvailable(): bool
            {
                return $this->works;
            }

            public function describe(): string
            {
                return $this->works ? 'MariaDB 11.8' : 'MySQL 8.0.46';
            }
        };

        return new ShopInfoAvailability($vectors, packagesInstalled: true, nativeStoreInstalled: $nativeStoreInstalled);
    }

    private function factory(ShopInfoAvailability $availability): SearchShopInfoToolFactory
    {
        // `SystemConfigLlmSettings` is `final readonly`, so PHPUnit cannot stub it — build the real
        // one over `FakeSystemConfigService`, the way `SystemConfigLlmSettingsTest` already does.
        // Nothing in this test reaches the provider: the factory never embeds anything.
        return new SearchShopInfoToolFactory(
            new EmbedderFactory(new SystemConfigLlmSettings(new FakeSystemConfigService([]))),
            new DalPortablePassageStore($this->createStub(Connection::class)),
            $availability,
        );
    }

    private function context(TraceRecorder $trace): ToolContext
    {
        return new ToolContext($trace, new AssistantConfig(embeddingModel: 'baai/bge-m3'));
    }

    public function testItNamesTheMariaDbStoreOnAShopThatCanRunIt(): void
    {
        $trace = new TraceRecorder();
        $this->factory($this->availability(true))->create($this->context($trace));

        self::assertSame('mariadb', $trace->payload('retrieve.shopinfo.store')['store'] ?? null);
    }

    public function testItNamesThePortableStoreAndWhyOnAShopThatCannot(): void
    {
        $trace = new TraceRecorder();
        $this->factory($this->availability(false))->create($this->context($trace));

        $payload = $trace->payload('retrieve.shopinfo.store');

        self::assertSame('portable', $payload['store'] ?? null);
        self::assertStringContainsString('MariaDB', (string) ($payload['reason'] ?? ''));
    }

    /**
     * The case spec D6 was written for: the database is fine but `symfony/ai-maria-db-store` is not
     * in the shop's vendor tree, the state a MariaDB shop actually lands in after Task 5 moved that
     * package to `suggest`. The reason must point at the `composer require`, not at the database.
     */
    public function testItNamesThePortableStoreAndWhyWhenOnlyThePackageIsMissing(): void
    {
        $trace = new TraceRecorder();
        $this->factory($this->availability(true, nativeStoreInstalled: false))->create($this->context($trace));

        $payload = $trace->payload('retrieve.shopinfo.store');

        self::assertSame('portable', $payload['store'] ?? null);
        self::assertStringContainsString(
            'composer require symfony/ai-maria-db-store',
            (string) ($payload['reason'] ?? ''),
        );
    }

    /**
     * Nothing is recorded when the feature is off. A trace line about a store that never answered
     * would be noise on every product turn in the shop.
     */
    public function testItRecordsNothingWhenShopKnowledgeIsSwitchedOff(): void
    {
        $trace = new TraceRecorder();
        $this->factory($this->availability(true))->create(new ToolContext($trace, new AssistantConfig()));

        self::assertNull($trace->payload('retrieve.shopinfo.store'));
    }
}
