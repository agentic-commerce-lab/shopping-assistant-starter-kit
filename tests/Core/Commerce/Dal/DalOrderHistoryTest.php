<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\SalesChannel\AbstractOrderRoute;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalOrderHistory;

/**
 * This test is the security model of the feature, written down.
 *
 * Under Shopware Commercial's B2B Components an employee is not a customer: the company is the
 * customer and employees are logins hanging off it, so every employee of one company presents the
 * **same** customer id. What separates them is Commercial's `DecoratedOrderRoute`, which adds a
 * filter restricting the result to the employee's own orders unless their role carries the
 * permission `order.read.all` — and it is fail-closed, because an employee with no role still gets
 * the filter.
 *
 * That is a decoration of a **service**. Querying `order.repository` returns every order of the
 * business partner instead: silently, with no error, and with nothing in a trace to show for it. The
 * failure mode is one employee reading a colleague's orders and invoices, which is exactly the
 * scenario the feature was asked for.
 *
 * So the dependency is asserted by type rather than left to a docblock nobody rereads. A future
 * change that swaps in a repository for convenience fails here, in this repository, rather than in
 * somebody's shop.
 *
 * **Injecting the ABSTRACT class is what makes it work.** Symfony resolves `AbstractOrderRoute` to
 * the outermost decorator; naming the concrete `OrderRoute` would bypass the B2B filter while
 * looking entirely correct.
 */
final class DalOrderHistoryTest extends TestCase
{
    public function testDependsOnTheOrderRouteAndNotARepository(): void
    {
        $constructor = (new \ReflectionClass(DalOrderHistory::class))->getConstructor();
        self::assertNotNull($constructor);

        $types = array_map(
            static fn(\ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $constructor->getParameters(),
        );

        self::assertContains(
            AbstractOrderRoute::class,
            $types,
            'the order history must be read through the route Commercial decorates',
        );
    }

    public function testDependsOnNoRepositoryAtAll(): void
    {
        $constructor = (new \ReflectionClass(DalOrderHistory::class))->getConstructor();
        self::assertNotNull($constructor);

        foreach ($constructor->getParameters() as $parameter) {
            $type = (string) $parameter->getType();

            self::assertStringNotContainsString(
                'Repository',
                $type,
                sprintf(
                    'parameter $%s is a repository: that bypasses the B2B employee filter silently',
                    $parameter->getName(),
                ),
            );
        }
    }

    /**
     * The wiring, not just the type — this is the assertion that would have caught the real mistake.
     *
     * The first version of `services.xml` injected `AbstractOrderRoute`, reasoning that Symfony
     * resolves an abstract id to the outermost decorator. It does not: **`AbstractOrderRoute` is not
     * a registered service at all**, and the container would have failed to compile on any real shop.
     * No unit test saw it, because the suite never builds the container.
     *
     * What is true, verified on a shop running Commercial 7.13.0: the concrete
     * `Shopware\Core\Checkout\Order\SalesChannel\OrderRoute` is a *public alias* for
     * `DecoratedOrderRoute`, because Symfony's decoration gives the decorator the decorated service's
     * id. So the concrete id is the correct one, and the dangerous neighbour is
     * `DecoratedOrderRoute.inner` — the undecorated original, which would compile, run, and hand
     * every employee the whole company's orders.
     */
    public function testIsWiredToTheRouteIdThatCarriesTheDecoration(): void
    {
        $services = file_get_contents(__DIR__ . '/../../../../src/Resources/config/services.xml');
        self::assertIsString($services);

        $definition = self::definitionOf($services, DalOrderHistory::class);

        self::assertStringContainsString(
            'Shopware\Core\Checkout\Order\SalesChannel\OrderRoute',
            $definition,
            'the order route must be injected by the id Commercial aliases to its decorator',
        );

        self::assertStringNotContainsString(
            'AbstractOrderRoute',
            $definition,
            'AbstractOrderRoute is not a registered service: the container will not compile',
        );

        self::assertStringNotContainsString(
            '.inner',
            $definition,
            'the .inner service is the UNDECORATED route and would bypass the B2B employee filter',
        );

        self::assertStringNotContainsString(
            'repository',
            $definition,
            'a repository bypasses the B2B employee filter silently',
        );
    }

    /** The one `<service>` block for the given class, so neighbouring definitions cannot satisfy it. */
    private static function definitionOf(string $services, string $class): string
    {
        $start = strpos($services, '<service id="' . $class . '"');
        self::assertIsInt($start, 'no service definition for ' . $class);

        $end = strpos($services, '</service>', $start);
        self::assertIsInt($end);

        return substr($services, $start, $end - $start);
    }
}
