<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\SalesChannel\AbstractOrderRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalOrderDocuments;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalOrderHistory;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalOrderMapper;
use Swag\AssistantStarterKit\Core\Commerce\Dal\SalesChannelContextProvider;
use Swag\AssistantStarterKit\Core\Commerce\Dto\OrderDetail;
use Swag\AssistantStarterKit\Tests\Core\Commerce\Dal\RecordingOrderRoute;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

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
 * **The type is abstract; the wired service id is concrete**, and getting that backwards was the
 * real mistake here — see `testIsWiredToTheRouteIdThatCarriesTheDecoration` for what was tried
 * first, why no test saw it, and which neighbouring id is the dangerous one.
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

    /**
     * The data path itself, which the three assertions above do not constrain at all.
     *
     * They are reflection and string checks: they pin the constructor's types and the service
     * wiring, and say nothing about what `orders()` does with either. An implementation that kept
     * the `AbstractOrderRoute` parameter unused and read orders through `Doctrine\DBAL\Connection`
     * would satisfy every one of them — `Connection` does not contain the substring `Repository` —
     * and would hand every employee the whole business partner's orders with the suite green.
     *
     * So this asserts the only thing that actually holds the invariant: the summaries come from the
     * route's own response, and nowhere else. A route that returns nothing yields nothing.
     */
    public function testReadsItsOrdersFromTheRouteAndNowhereElse(): void
    {
        $route = new RecordingOrderRoute();
        $contexts = new SalesChannelContextProvider(new RequestStack());

        $history = new DalOrderHistory(
            $route,
            $contexts,
            new RequestStack(),
            new DalOrderMapper(new DalOrderDocuments($this->createMock(RouterInterface::class))),
        );

        $orders = $contexts->use($this->createMock(SalesChannelContext::class), static fn(): array => $history->orders(
            5,
        ));

        self::assertSame([], $orders, 'a route returning no orders must yield none');
        self::assertSame(1, $route->loadCalls, 'orders() must go through the route exactly once');
        self::assertSame(5, $route->lastLimit, 'the bound must reach the route, not be applied after');
    }

    /**
     * A single order is looked up BY FILTERING THE ROUTE, never by fetching it and checking after.
     *
     * This is the security assertion of phase 2. Commercial's decorator adds the employee filter to
     * the criteria this call passes, so an order number belonging to a colleague matches nothing and
     * comes back as `null`. An implementation that loaded orders unfiltered and picked the matching
     * one in PHP would return the same answer for the shopper's own order — and the colleague's
     * order for anyone who reused that unfiltered call.
     */
    public function testLooksOneOrderUpAsAFilterOnTheRoute(): void
    {
        $route = new RecordingOrderRoute();
        $contexts = new SalesChannelContextProvider(new RequestStack());

        $history = new DalOrderHistory(
            $route,
            $contexts,
            new RequestStack(),
            new DalOrderMapper(new DalOrderDocuments($this->createMock(RouterInterface::class))),
        );

        $found = $contexts->use(
            $this->createMock(SalesChannelContext::class),
            static fn(): ?OrderDetail => $history->order('10023'),
        );

        self::assertNull($found, 'a route matching nothing must yield null, not an empty detail');
        self::assertSame(1, $route->loadCalls);
        self::assertSame(
            '10023',
            $route->lastEqualsFilters['orderNumber'] ?? null,
            'the order number must reach the route as a filter, not narrow its result afterwards',
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
