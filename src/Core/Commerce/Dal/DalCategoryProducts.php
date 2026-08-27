<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Content\Product\SalesChannel\ProductAvailableFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\TermsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Which of a set of categories hold at least one available product, in one query.
 *
 * `categoriesRo` is the ancestor-inclusive association, which is the whole reason one aggregation can
 * answer for a level AND its ancestors: a product in `Women > Dresses > Maxi Dresses` appears under all
 * three ids. `ProductAvailableFilter` is what makes "available" mean what a shopper would mean.
 *
 * Split from {@see DalCategoryTreeReader} so that class stays under the complexity budget with its two
 * queries and its mapping.
 */
final class DalCategoryProducts
{
    private const BUCKET = 'assistant_category_products';

    private function __construct() {}

    /**
     * @param list<string> $categoryIds
     *
     * @return array<string, true> the ids that hold something
     */
    public static function withProducts(
        array $categoryIds,
        SalesChannelRepository $productRepository,
        SalesChannelContext $context,
    ): array {
        if ($categoryIds === []) {
            return [];
        }

        $criteria = new Criteria();
        $criteria->addFilter(new ProductAvailableFilter($context->getSalesChannelId()));
        $criteria->addFilter(new EqualsAnyFilter('categoriesRo.id', $categoryIds));
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
        $criteria->addAggregation(new TermsAggregation(self::BUCKET, 'categoriesRo.id'));
        // One row, not zero: the DAL reads zero as "no limit" and would fetch every match.
        $criteria->setLimit(1);

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
