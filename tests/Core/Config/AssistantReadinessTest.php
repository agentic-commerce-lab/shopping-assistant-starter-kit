<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Config;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AssistantStarterKit\Core\Config\AssistantReadiness;
use Swag\AssistantStarterKit\Core\Config\SystemConfigLlmSettings;
use Swag\AssistantStarterKit\Tests\LlmEnvironmentGuard;

/**
 * Whether the shop can answer at all — the question the settings page's status card got wrong.
 *
 * Measured 2026-09-03: the card read `assistantEnabled` alone, which defaults to on, so a shop with
 * no model configured showed a green "Running" and "each reply spends model credit on your account"
 * while `/assistant/chat` answered 503 and the storefront rendered no orb.
 */
final class AssistantReadinessTest extends TestCase
{
    use LlmEnvironmentGuard;

    private const PREFIX = 'SwagAssistantStarterKit.config.';

    protected function setUp(): void
    {
        // A developer .env configures the assistant in all three sources, which would make the
        // unconfigured cases below impossible to test — see the guard for why one source is not
        // enough.
        $this->clearLlmEnvironment();
    }

    protected function tearDown(): void
    {
        $this->restoreLlmEnvironment();
    }

    public function testAShopWithNothingConfiguredAnywhereIsNotReady(): void
    {
        $readiness = new AssistantReadiness(
            new SystemConfigLlmSettings(new FakeSystemConfigService([])),
            $this->salesChannels([Uuid::randomHex()]),
        );

        self::assertFalse($readiness->isConfiguredAnywhere());
    }

    public function testGlobalSettingsMakeItReady(): void
    {
        $readiness = new AssistantReadiness(
            new SystemConfigLlmSettings(new FakeSystemConfigService($this->configured())),
            $this->salesChannels([Uuid::randomHex()]),
        );

        self::assertTrue($readiness->isConfiguredAnywhere());
    }

    /**
     * A shop with no sales channels at all still has to answer the question, which is the only case
     * the global check decides on its own: a channel with no override of its own already inherits
     * the global values.
     */
    public function testAShopWithNoSalesChannelsFallsBackToTheGlobalValues(): void
    {
        $readiness = new AssistantReadiness(
            new SystemConfigLlmSettings(new FakeSystemConfigService($this->configured())),
            $this->salesChannels([]),
        );

        self::assertTrue($readiness->isConfiguredAnywhere());
    }

    /**
     * The case that rules out the obvious alternative.
     *
     * Reading the model fields out of the settings form would report this shop as unconfigured,
     * because a channel's own values are not the ones the form shows when it is pointed at "All
     * Sales Channels" — and a shop that configured exactly one storefront is running.
     */
    public function testOneChannelWithItsOwnModelIsEnough(): void
    {
        $channel = Uuid::randomHex();

        $readiness = new AssistantReadiness(
            new SystemConfigLlmSettings(new FakeSystemConfigService([], [$channel => $this->configured()])),
            $this->salesChannels([$channel]),
        );

        self::assertTrue($readiness->isConfiguredAnywhere());
    }

    /**
     * @return array<string, string>
     */
    private function configured(): array
    {
        return [
            self::PREFIX . 'llmBaseUrl' => 'https://openrouter.ai/api',
            self::PREFIX . 'llmModel' => 'anthropic/claude-sonnet-5',
            self::PREFIX . 'llmApiKey' => 'not-a-real-credential',
        ];
    }

    /**
     * @param list<string> $ids
     */
    private function salesChannels(array $ids): EntityRepository
    {
        $rows = [];

        foreach ($ids as $id) {
            $rows[$id] = ['primaryKey' => $id, 'data' => []];
        }

        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->method('searchIds')
            ->willReturnCallback(
                static fn(Criteria $criteria): IdSearchResult => new IdSearchResult(
                    \count($rows),
                    $rows,
                    $criteria,
                    Context::createDefaultContext(),
                ),
            );

        return $repository;
    }
}
