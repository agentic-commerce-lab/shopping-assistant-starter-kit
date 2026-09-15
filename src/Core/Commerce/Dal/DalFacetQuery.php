<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\Facet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;

/**
 * What the catalogue can be asked about: its price range, its manufacturers, and its attribute
 * values.
 *
 * Its own class rather than a method on {@see DalCommerceGateway}, for the reason
 * {@see DalDepartments} and {@see DalCategoryTreeReader} are: that class sits on this project's
 * cyclomatic-complexity gate, and teaching the facet query a second way to find values pushed it
 * over. The seam is the right one either way — everything here is about one question the catalogue
 * answers once per turn, and everything left in the gateway is about products.
 *
 * ## Two ways to the same answer
 *
 * Attribute values are read per VALUE when the scope allows it ({@see DalPropertyValuesInUse}) and
 * aggregated per PRODUCT when it does not. The two produce identical sets — verified against
 * 118,632 products, both directions, including variant options — and differ only in what they cost:
 * 176 ms against 1,767 ms at that size, and the gap widens with every product a shop adds.
 *
 * The price range and the manufacturer list are aggregated either way. Manufacturers measured at
 * 3 ms, which is not worth a second code path. The price range is a different story and a
 * deliberate omission from this change: {@see \Swag\AssistantStarterKit\Core\Retrieval\Filter\PriceFilterResolver}
 * reads only whether the facet EXISTS, never its bounds, so a full min/max scan — 145 ms here,
 * ~2.2 s at 1.8M products — answers a question nobody asked. Removing it needs its own design,
 * because a `Range` facet without bounds is one `QueryBuilder` drops.
 */
final readonly class DalFacetQuery
{
    /**
     * One row is enough: the aggregations run over everything the criteria matches, not over the
     * page, so a larger limit would only hydrate entities nobody reads.
     */
    private const ROW_LIMIT = 1;

    private const VALUE_LIMIT = 50;

    /**
     * @param SalesChannelRepository<\Shopware\Core\Content\Product\ProductCollection> $products
     */
    public function __construct(
        private SalesChannelRepository $products,
        private DalCriteriaBuilder $criteriaBuilder,
        private DalFacetReader $facetReader,
        private DalPropertyValuesInUse $valuesInUse,
    ) {}

    /**
     * @throws \Doctrine\DBAL\Exception from the per-value read; the aggregation path can fail the
     *                                 same way, and FacetProbe's caller decides what a broken
     *                                 catalogue read means for a turn
     */
    public function facets(CatalogScope $scope, SalesChannelContext $context): FacetSet
    {
        $perValue = DalPropertyValuesInUse::canAnswer($scope);

        $criteria = $this->criteriaBuilder->build(
            new ProductQuery(limit: self::ROW_LIMIT),
            $scope,
            $context->getSalesChannelId(),
        );

        // Named for the logical field BrandFilterResolver asks for, so the facet it looks up
        // exists; DalFilterTranslator maps the resulting clause back to manufacturer.name.
        $criteria->addAggregation(new TermsAggregation(
            DalFilterTranslator::MANUFACTURER_FIELD,
            'manufacturer.name',
            self::VALUE_LIMIT,
        ));

        foreach ($perValue ? [] : self::valueAggregations() as $aggregation) {
            $criteria->addAggregation($aggregation);
        }

        $facets = $this->facetReader->read($this->products->aggregate($criteria, $context));

        if (!$perValue) {
            return new FacetSet([self::price(), ...$facets->facets]);
        }

        return new FacetSet([
            self::price(),
            ...$facets->facets,
            ...$this->valuesInUse->facets(
                $context->getSalesChannelId(),
                $context->getContext()->getLanguageIdChain(),
                self::VALUE_LIMIT,
            ),
        ]);
    }

    /**
     * The price facet, stated rather than computed.
     *
     * **Its only consumer asks whether it EXISTS.**
     * {@see \Swag\AssistantStarterKit\Core\Retrieval\Filter\PriceFilterResolver} reads
     * `$facets->has('price')` and nothing else — the bounds it applies come from the shopper's own
     * words, never from here. Outside the CLI's `--facets` table, `min` and `max` are read nowhere
     * in this plugin.
     *
     * They cost a `StatsAggregation` over every product to produce: 145 ms on a 118,632-product
     * shop, and the last term in this probe that grows with the catalogue — roughly 2.2 s at 1.8M.
     * A number nobody reads is not worth a table scan.
     *
     * **Always present, because against a real Shopware the answer is always yes.** `price` is a
     * product field the DAL can always range-filter on, so "can this catalogue be filtered by
     * price" has no false case here. An empty or fully-scoped-out catalogue is the one edge, and
     * there the filter is applied to nothing instead of dropped — the same empty result, differing
     * only in which word the trace records.
     *
     * The bounds stay null, which {@see Facet} has always allowed and
     * {@see DalFacetReader::float()} has always produced for a non-numeric aggregation.
     */
    private static function price(): Facet
    {
        return new Facet(field: 'price', type: FacetType::Range);
    }

    /**
     * `properties` and `options` are aggregated separately because Shopware stores them separately,
     * and folded back into one namespace by {@see DalFacetReader} — see its docblock for why
     * splitting them would silently drop every colour and size constraint.
     *
     * @return list<TermsAggregation>
     */
    private static function valueAggregations(): array
    {
        return [
            new TermsAggregation(
                'properties',
                'properties.group.name',
                self::VALUE_LIMIT,
                null,
                new TermsAggregation('values', 'properties.name', self::VALUE_LIMIT),
            ),
            new TermsAggregation(
                'options',
                'options.group.name',
                self::VALUE_LIMIT,
                null,
                new TermsAggregation('values', 'options.name', self::VALUE_LIMIT),
            ),
        ];
    }
}
