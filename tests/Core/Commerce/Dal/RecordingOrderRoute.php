<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Dal;

use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\SalesChannel\AbstractOrderRoute;
use Shopware\Core\Checkout\Order\SalesChannel\OrderRouteResponse;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal an order route that records how it was called and returns nothing
 *
 * It exists so {@see DalOrderHistoryTest} can assert that the order history is read THROUGH the
 * route — the one thing the reflection and string assertions beside it cannot see. A version of
 * `DalOrderHistory` that kept its `AbstractOrderRoute` parameter and quietly read orders through
 * DBAL would satisfy every other test in that file and fail this one.
 */
final class RecordingOrderRoute extends AbstractOrderRoute
{
    public int $loadCalls = 0;

    public ?int $lastLimit = null;

    /** @var array<string, mixed> field => value, for every EqualsFilter on the last criteria */
    public array $lastEqualsFilters = [];

    /** @var list<string> the field of every RangeFilter on the last criteria */
    public array $lastRangeFields = [];

    public function getDecorated(): AbstractOrderRoute
    {
        throw new \LogicException('not decorated');
    }

    public function load(Request $request, SalesChannelContext $context, Criteria $criteria): OrderRouteResponse
    {
        ++$this->loadCalls;
        $this->lastLimit = $criteria->getLimit();

        // Captured so a test can assert that a lookup by order number reached the ROUTE as a filter,
        // rather than being applied to an unfiltered result afterwards. The decorator adds the
        // employee filter to this same criteria; a narrowing done later would miss it.
        $this->lastEqualsFilters = [];

        $this->lastRangeFields = [];

        foreach ($criteria->getFilters() as $filter) {
            if ($filter instanceof EqualsFilter) {
                $this->lastEqualsFilters[$filter->getField()] = $filter->getValue();
            }

            if ($filter instanceof RangeFilter) {
                $this->lastRangeFields[] = $filter->getField();
            }
        }

        return new OrderRouteResponse(
            new EntitySearchResult(
                OrderDefinition::ENTITY_NAME,
                0,
                new OrderCollection(),
                null,
                $criteria,
                Context::createDefaultContext(),
            ),
        );
    }
}
