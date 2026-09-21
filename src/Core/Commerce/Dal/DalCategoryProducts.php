<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\TermsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;

/**
 * Which of a set of categories hold at least one available product, in one query.
 *
 * `categoriesRo` is the ancestor-inclusive association, which is the whole reason one aggregation can
 * answer for a level AND its ancestors: a product in `Women > Dresses > Maxi Dresses` appears under all
 * three ids.
 *
 * **Counted through the merchant's scope**, which it was not until 2026-09-21. Found on staging with
 * every helmet blocked by a product group: *"the search found no helmets. We do have a Helmets
 * department — shall I look there?"* Saying yes searches it and finds nothing again. Nothing leaked,
 * and the bug was older than product groups — blocked products and blocked categories were ignored
 * here too. Groups only made it easy to reach, being the first control that empties a department in
 * one click.
 *
 * The criteria therefore comes from {@see DalCriteriaBuilder}, so "available" means here exactly
 * what it means everywhere else, rather than a second opinion assembled from a `ProductAvailableFilter`
 * and whatever this class remembered to add.
 *
 * Split from {@see DalCategoryTreeReader} so that class stays under the complexity budget with its two
 * queries and its mapping.
 */
final class DalCategoryProducts
{
    private const BUCKET = 'assistant_category_products';

    private function __construct() {}

    /**
     * Split from the read so the scope's arrival can be asserted without a database — this suite has
     * none. See {@see \Swag\AssistantStarterKit\Tests\Core\Commerce\Dal\DalCategoryProductsScopeTest}.
     *
     * `build()` and not `buildForDiscovery()`: a department that holds only sold-out products still
     * holds products, and a shopper sent there sees them exactly as the shop's own menu does.
     *
     * @param list<string> $categoryIds
     */
    public static function criteriaFor(
        array $categoryIds,
        CatalogScope $scope,
        DalCriteriaBuilder $criteriaBuilder,
        string $salesChannelId,
    ): Criteria {
        $criteria = $criteriaBuilder->build(new ProductQuery(limit: 1), $scope, $salesChannelId);
        $criteria->addFilter(new EqualsAnyFilter('categoriesRo.id', $categoryIds));
        $criteria->addAggregation(new TermsAggregation(self::BUCKET, 'categoriesRo.id'));
        // One row, not zero: the DAL reads zero as "no limit" and would fetch every match.
        $criteria->setLimit(1);

        return $criteria;
    }

    /**
     * @param list<string> $categoryIds
     *
     * @return array<string, true> the ids that hold something
     */
    public static function withProducts(
        array $categoryIds,
        CatalogScope $scope,
        SalesChannelRepository $productRepository,
        DalCriteriaBuilder $criteriaBuilder,
        SalesChannelContext $context,
    ): array {
        if ($categoryIds === []) {
            return [];
        }

        $criteria = self::criteriaFor($categoryIds, $scope, $criteriaBuilder, $context->getSalesChannelId());
        // **No bucket limit, and the first version's limit was a bug worth recording.** It passed
        // `count($categoryIds)`, reasoning that we only care about those ids. But the aggregation buckets
        // `categoriesRo.id` across the MATCHING PRODUCTS, and each product carries its own leaf category
        // plus every ancestor — so the top-N buckets by count are mostly other categories, and the ids
        // actually asked about get crowded out. Measured on the lab shop: seven top-level departments
        // went in, one unrelated bucket came back, and the assistant told a shopper "this shop focuses
        // on Movies".
        //
        // The intersection below is what narrows the result, so the aggregation must not narrow it
        // first. Unbounded is safe here because the buckets are bounded by the distinct categories of
        // the matching products, not by the catalogue.
        $result = $productRepository->search($criteria, $context)->getAggregations()->get(self::BUCKET);

        if (!$result instanceof TermsResult) {
            return [];
        }

        $stocked = [];

        foreach ($result->getKeys() as $key) {
            $stocked[(string) $key] = true;
        }

        return $stocked;
    }
}
