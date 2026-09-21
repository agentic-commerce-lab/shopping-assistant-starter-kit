<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\TermsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;

/**
 * Which of these families still have a variant in stock — **one query for all of them**.
 *
 * Asked once per search result by {@see \Swag\AssistantStarterKit\Core\Policy\UnbuyableFamilies},
 * and only when the merchant asked for sold-out products to be left out. See that class for the
 * staging failure that made it necessary.
 *
 * ## An aggregation, not a read
 *
 * The question is membership, not content: *does anything exist under this parent that is in stock?*
 * A {@see TermsAggregation} over `parentId` answers it for every family at once and fetches no
 * product rows, so a result holding eight families costs one round trip instead of eight. The row
 * limit stays at one for the reason `DalCommerceGateway::countMatches()` gives for its own: an
 * aggregation is computed over the whole matching set regardless of how many rows come back beside
 * it.
 *
 * ## Built from the shared criteria, deliberately
 *
 * Through {@see DalCriteriaBuilder::build()} rather than a hand-rolled criteria, so the merchant's
 * scope is applied here by the same code that applies it everywhere else. That matters for the
 * answer and not only for tidiness: a family held up **only** by blocked variants is not buyable,
 * because a blocked variant is not stock the assistant may offer. A second copy of `applyScope()`
 * here is exactly the drift `BlocklistFilter` exists to catch after the fact.
 *
 * `build()` and never `buildForDiscovery()`: the discovery filters would re-ask the stock question
 * this method is here to answer, and `stock > 0` below already says it precisely.
 */
final readonly class DalStockedFamilies
{
    /** The name the {@see TermsAggregation} is registered under and read back by. */
    private const AGGREGATION = 'stocked_families';

    public function __construct(
        private SalesChannelRepository $productRepository,
        private DalCriteriaBuilder $criteriaBuilder,
    ) {}

    /**
     * @param list<string> $parentIds
     *
     * @return list<string>
     */
    public function of(array $parentIds, CatalogScope $scope, SalesChannelContext $context): array
    {
        // Same guard every id-taking read here keeps: an unparseable id raises inside the DAL, far
        // from whoever supplied it.
        $usable = DalLookupIds::usable($parentIds);

        if ($usable === []) {
            return [];
        }

        $criteria = $this->criteriaBuilder->build(new ProductQuery(limit: 1), $scope, $context->getSalesChannelId());
        $criteria->setLimit(1);
        $criteria->addFilter(new EqualsAnyFilter('parentId', $usable));
        $criteria->addFilter(new RangeFilter('stock', [RangeFilter::GT => 0]));
        $criteria->addAggregation(new TermsAggregation(self::AGGREGATION, 'parentId'));

        $result = $this->productRepository->aggregate($criteria, $context)->get(self::AGGREGATION);

        if (!$result instanceof TermsResult) {
            // A shop whose aggregation came back in a shape this does not understand keeps every
            // family, which is what it did before this class existed. Never the other direction.
            return $usable;
        }

        return array_values($result->getKeys());
    }
}
