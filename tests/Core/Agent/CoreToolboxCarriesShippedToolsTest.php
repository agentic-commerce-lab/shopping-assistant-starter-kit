<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\AssistantAgentFactory;

/**
 * `withCoreToolsOnly()` must list every SHIPPED tool factory, not merely the ones it listed first.
 *
 * It is the toolbox the eval harness builds, and it hardcodes its factories rather than reading the
 * container's tags. So a tool added to `services.xml` — the only place production reads — is absent
 * from every journey, silently.
 *
 * **That is not a theoretical gap.** `list_orders` and `get_order` shipped without being added here,
 * and `order_history_shown` reported GREEN across three runs of two archetypes: every assertion on
 * an order journey passes on a turn that declined, because declining invents nothing, names no
 * foreign order and claims no handoff. The suite was measuring a capability it did not have.
 *
 * Comparing against `services.xml` rather than a hand-written list, because a hand-written list is
 * the same mistake one layer up.
 *
 * Two factories are exempt, for opposite reasons:
 *
 * - `SearchShopInfoToolFactory` is reachable, just by another route — `JourneyAttempt` adds it per
 *   journey through `withAdditionalFactories()`, because it needs an embedding model that only some
 *   journeys configure, and giving it to all of them would change what every other model sees.
 * - **`GoToCheckoutToolFactory` is a genuine gap, and it is older than this test.** `go_to_checkout`
 *   ships, needs no configuration, and no eval journey can reach it. Adding it here would be right
 *   and is deliberately NOT done in the commit that found it: this factory's own neighbours document
 *   that a reordered toolbox changes which tool a model reaches for first, so it would silently move
 *   every existing journey's result. It wants its own change and its own re-measurement.
 */
final class CoreToolboxCarriesShippedToolsTest extends TestCase
{
    public function testEveryFactoryInServicesXmlIsAlsoInTheCoreToolbox(): void
    {
        $services = file_get_contents(__DIR__ . '/../../../src/Resources/config/services.xml');
        self::assertIsString($services);

        $registered = [];
        preg_match_all('#Core\\\\Tool\\\\Factory\\\\(\w+ToolFactory)"#', $services, $registered);

        /** @var list<string> $factories */
        $factories = $registered[1] ?? [];

        $source = file_get_contents(__DIR__ . '/../../../src/Core/Agent/AssistantAgentFactory.php');
        self::assertIsString($source);

        $coreBlock = substr(
            $source,
            (int) strpos($source, 'withCoreToolsOnly'),
            (int) strpos($source, 'withAdditionalFactories') - (int) strpos($source, 'withCoreToolsOnly'),
        );

        $exempt = ['SearchShopInfoToolFactory', 'GoToCheckoutToolFactory'];

        $missing = array_values(array_filter(
            array_unique($factories),
            static fn(string $factory): bool => (
                !\in_array($factory, $exempt, true) && !str_contains($coreBlock, $factory . '()')
            ),
        ));

        self::assertSame(
            [],
            $missing,
            sprintf('shipped but absent from withCoreToolsOnly(), so no eval journey can reach them: %s', implode(
                ', ',
                $missing,
            )),
        );
    }
}
