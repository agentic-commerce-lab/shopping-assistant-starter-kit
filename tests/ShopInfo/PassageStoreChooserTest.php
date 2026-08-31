<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\ShopInfo;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoAvailability;
use Swag\AssistantStarterKit\Core\ShopInfo\VectorSupport;
use Swag\AssistantStarterKit\ShopInfo\AiStorePassageStore;
use Swag\AssistantStarterKit\ShopInfo\DalPortablePassageStore;
use Swag\AssistantStarterKit\ShopInfo\PassageStoreChooser;
use Swag\AssistantStarterKit\ShopInfo\ShopInfoVectorTable;

/**
 * Which store answers, decided once per request from a live probe.
 *
 * The direction that matters most is the first one: a shop that CAN run the indexed store must get
 * it. Falling back where the fast path exists would be a silent performance regression on exactly
 * the shops with the largest document sets, and no test above this one would notice.
 */
final class PassageStoreChooserTest extends TestCase
{
    private function availability(bool $vectorsWork, bool $packageInstalled = true): ShopInfoAvailability
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

        return new ShopInfoAvailability($vectors, packagesInstalled: true, nativeStoreInstalled: $packageInstalled);
    }

    private function mariaDb(): AiStorePassageStore
    {
        return new AiStorePassageStore(new ShopInfoVectorTable($this->createStub(Connection::class)));
    }

    private function portable(): DalPortablePassageStore
    {
        return new DalPortablePassageStore($this->createStub(Connection::class));
    }

    public function testItPrefersTheMariaDbStoreWhenTheDatabaseCanRunIt(): void
    {
        $store = PassageStoreChooser::choose($this->availability(true), $this->mariaDb(), $this->portable());

        self::assertInstanceOf(AiStorePassageStore::class, $store);
    }

    public function testItFallsBackToThePortableStoreOtherwise(): void
    {
        $store = PassageStoreChooser::choose($this->availability(false), $this->mariaDb(), $this->portable());

        self::assertInstanceOf(DalPortablePassageStore::class, $store);
    }

    /**
     * The case a MariaDB shop actually hits once `symfony/ai-maria-db-store` is only *suggested*: the
     * database would serve `VEC_DISTANCE_COSINE` happily, but the bridge that writes it is not in the
     * shop's vendor tree. Choosing the indexed store here would fatal on the first query — the
     * container can build `AiStorePassageStore` without the package, but it cannot call it.
     */
    public function testItFallsBackWhenTheDatabaseCouldButThePackageIsMissing(): void
    {
        $store = PassageStoreChooser::choose(
            $this->availability(true, packageInstalled: false),
            $this->mariaDb(),
            $this->portable(),
        );

        self::assertInstanceOf(DalPortablePassageStore::class, $store);
    }
}
