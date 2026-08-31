<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\PluginLifecycle;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Migration\MigrationCollection;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Swag\AssistantStarterKit\SwagAssistantStarterKit;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The merchant's answer to "keep user data?" is the whole contract here, and it points both ways.
 *
 * Unticking it is an explicit instruction to remove what the plugin stored — `transcript` holds what
 * shoppers typed, so ignoring it was the data-protection half of the defect. Leaving it ticked is an
 * equally explicit instruction not to, and a plugin that dropped the tables anyway would destroy a
 * trace history the merchant asked to keep. Both directions are asserted, because only checking the
 * dropping half would let "always drop" pass.
 */
final class UninstallHonoursKeepUserDataTest extends TestCase
{
    private function plugin(Connection $connection): SwagAssistantStarterKit
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($connection);

        $plugin = new SwagAssistantStarterKit(true, __DIR__);
        $plugin->setContainer($container);

        return $plugin;
    }

    /**
     * `MigrationCollection` is stubbed rather than built: `UninstallContext` requires one, and nothing
     * in this plugin's `uninstall()` reads it — Shopware has already removed the migration records by
     * the time it is called.
     */
    private function context(bool $keepUserData): UninstallContext
    {
        return new UninstallContext(
            new SwagAssistantStarterKit(true, __DIR__),
            Context::createDefaultContext(),
            '6.7.0.0',
            '1.0.0',
            $this->createStub(MigrationCollection::class),
            $keepUserData,
        );
    }

    public function testItDropsThePluginsTablesWhenTheMerchantDeclinesToKeepData(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::atLeastOnce())->method('executeStatement')->willReturn(0);

        $this->plugin($connection)->uninstall($this->context(keepUserData: false));
    }

    /**
     * The direction that would otherwise never be tested, and the one that destroys something when it
     * is wrong.
     */
    public function testItDropsNothingWhenTheMerchantAsksToKeepData(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $this->plugin($connection)->uninstall($this->context(keepUserData: true));
    }
}
