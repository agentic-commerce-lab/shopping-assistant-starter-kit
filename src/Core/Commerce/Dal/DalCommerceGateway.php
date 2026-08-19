<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\StatsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartSummary;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;

/**
 * {@see CommerceGatewayInterface} over the Shopware DAL — the implementation that makes this
 * plugin answer from a real catalogue instead of a fixture.
 *
 * It composes rather than implements: criteria building, entity mapping, facet reading, variant
 * finding and cart access each live in their own class. That is not decoration — a single class
 * doing all five trips the complexity gate, and the standing constraints answer a complexity
 * finding with a split rather than a suppression.
 *
 * **The seam rule holds from here down, not here up.** `SalesChannelProductEntity`,
 * `SalesChannelContext` and `Criteria` may appear inside this namespace and nowhere else in the
 * plugin. Only DTOs leave.
 */
final readonly class DalCommerceGateway implements CommerceGatewayInterface
{
    /**
     * Facet probing needs one product's worth of rows at most — the values come from the
     * aggregations, which are computed over the whole matching set regardless of this limit.
     */
    private const FACET_ROW_LIMIT = 1;

    /**
     * Bounds how much catalogue vocabulary reaches the model. Ruling R54 already caps the prompt
     * block at 25 values per field and 30 fields; capping the aggregation too means the database
     * is not asked for thousands of buckets that get discarded.
     */
    private const FACET_VALUE_LIMIT = 50;

    public function __construct(
        private SalesChannelRepository $productRepository,
        private DalCriteriaBuilder $criteriaBuilder,
        private DalProductCardMapper $mapper,
        private DalFacetReader $facetReader,
        private SalesChannelContextProvider $contextProvider,
    ) {}

    public function facets(CatalogScope $scope): FacetSet
    {
        $context = $this->contextProvider->current();

        $criteria = $this->criteriaBuilder->build(
            new ProductQuery(limit: self::FACET_ROW_LIMIT),
            $scope,
            $context->getSalesChannelId(),
        );

        // `properties` and `options` are aggregated separately because Shopware stores them
        // separately, and folded back into one namespace by DalFacetReader — see its docblock
        // for why splitting them would silently drop every colour and size constraint.
        $criteria->addAggregation(new StatsAggregation(DalFilterTranslator::PRICE_FIELD, 'price'));
        $criteria->addAggregation(
            new TermsAggregation(
                'properties',
                'properties.group.name',
                self::FACET_VALUE_LIMIT,
                null,
                new TermsAggregation('values', 'properties.name', self::FACET_VALUE_LIMIT),
            ),
        );
        $criteria->addAggregation(
            new TermsAggregation(
                'options',
                'options.group.name',
                self::FACET_VALUE_LIMIT,
                null,
                new TermsAggregation('values', 'options.name', self::FACET_VALUE_LIMIT),
            ),
        );
        // Named for the logical field BrandFilterResolver asks for, so the facet it looks up
        // exists; DalFilterTranslator maps the resulting clause back to manufacturer.name.
        $criteria->addAggregation(new TermsAggregation(
            DalFilterTranslator::MANUFACTURER_FIELD,
            'manufacturer.name',
            self::FACET_VALUE_LIMIT,
        ));

        return $this->facetReader->read($this->productRepository->aggregate($criteria, $context));
    }

    public function search(ProductQuery $query, CatalogScope $scope): array
    {
        $context = $this->contextProvider->current();
        $criteria = $this->criteriaBuilder->build($query, $scope, $context->getSalesChannelId());

        return $this->mapAll(
            $this->productRepository->search($criteria, $context)->getElements(),
            $context->getCurrency()->getIsoCode(),
        );
    }

    public function product(string $productId, CatalogScope $scope): ?ProductCard
    {
        $context = $this->contextProvider->current();

        // The scope goes in as well as the id: this is the direct-lookup half of the blocklist
        // guarantee, so a blocked product is refused at the point of lookup rather than fetched
        // and then removed. A caller cannot tell the two apart, and does not need to.
        $criteria = $this->criteriaBuilder->build(new ProductQuery(limit: 1), $scope, $context->getSalesChannelId());
        $criteria->addFilter(new EqualsFilter('id', $productId));

        $cards = $this->mapAll(
            $this->productRepository->search($criteria, $context)->getElements(),
            $context->getCurrency()->getIsoCode(),
        );

        return $cards[0] ?? null;
    }

    public function resolveVariant(string $parentId, array $selections, CatalogScope $scope): ?ProductCard
    {
        throw new \LogicException('Variant resolution over the DAL lands with DalVariantFinder.');
    }

    public function addToCart(string $variantId, int $quantity): CartSummary
    {
        throw new \LogicException('Cart writes over the DAL land with DalCartAdapter.');
    }

    public function cart(): CartSummary
    {
        throw new \LogicException('Cart reads over the DAL land with DalCartAdapter.');
    }

    /**
     * Maps every retrieved row, deciding the stock source per row rather than per query.
     *
     * **That conditional is the whole of D4 at this layer.** A row with a parent is a variant and
     * may report its own stock; a row without one is a parent product and must say so, because a
     * parent's aggregate stock presented as a variant's is the claim that cancels orders. It is
     * decided here, from the row, rather than passed in by a caller who might be wrong.
     *
     * @param array<mixed> $entities
     *
     * @return list<ProductCard>
     */
    private function mapAll(array $entities, string $currency): array
    {
        $cards = [];

        foreach ($entities as $entity) {
            if (!$entity instanceof SalesChannelProductEntity) {
                continue;
            }

            $cards[] = $this->mapper->map(
                $entity,
                $entity->getParentId() !== null ? StockSource::Variant : StockSource::Parent,
                $currency,
            );
        }

        return $cards;
    }
}
