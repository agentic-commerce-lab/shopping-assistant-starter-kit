<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Adding the order tools to `withCoreToolsOnly()` must not change what any OTHER journey sees.
 *
 * The concern is the eval suite's, and it is specific. `withCoreToolsOnly()` is the toolbox every
 * journey runs against, and this factory's own neighbours record that **a reordered toolbox changes
 * which tool a model reaches for first** — so a tool added there can silently move the result of
 * every journey that has nothing to do with it. That is the stated reason `GoToCheckoutToolFactory`
 * was left out rather than added when it was found missing: it has no merchant switch and the
 * harness passes `cartAvailable: true`, so it WOULD appear everywhere.
 *
 * The order tools are different, and this test is that difference measured rather than argued.
 * `enableOrderHistory` defaults to false and `loggedIn` defaults to false, so both factories return
 * null for every journey that does not ask for them — the toolbox is byte-for-byte what it was.
 *
 * If a later change gives either tool a weaker gate, this fails, and it fails here rather than as a
 * drifting eval result somebody attributes to the model.
 */
final class OrderToolsDoNotReachOtherJourneysTest extends TestCase
{
    private const ORDER_TOOLS = ['list_orders', 'get_order'];

    public function testAJourneyThatNeverAsksForOrdersGetsNoOrderTools(): void
    {
        self::assertSame([], array_intersect(self::toolNames(new AssistantConfig()), self::ORDER_TOOLS));
    }

    /** The merchant's switch alone is not enough: a journey has no signed-in shopper either. */
    public function testTheSwitchAloneDoesNotAddThemEither(): void
    {
        self::assertSame(
            [],
            array_intersect(self::toolNames(new AssistantConfig(enableOrderHistory: true)), self::ORDER_TOOLS),
        );
    }

    /** And the tools really are reachable — otherwise the two assertions above prove nothing. */
    public function testTheyAppearWhenTheJourneyAsksForThem(): void
    {
        $names = self::toolNames(new AssistantConfig(enableOrderHistory: true), loggedIn: true);

        self::assertContains('list_orders', $names);
        self::assertContains('get_order', $names);
    }

    /** @return list<string> */
    private static function toolNames(AssistantConfig $config, bool $loggedIn = false): array
    {
        $bundle = AssistantAgentFactory::withCoreToolsOnly()->create(
            FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog-parts.json'),
            $config,
            true,
            new LlmSettings('https://example.test', 'key', 'model'),
            loggedIn: $loggedIn,
        );

        return array_values(array_map(static fn(Tool $tool): string => $tool->getName(), $bundle->toolbox->getTools()));
    }
}
