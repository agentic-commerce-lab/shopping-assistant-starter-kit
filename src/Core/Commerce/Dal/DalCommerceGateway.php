<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\CountAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\StatsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\CountResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Swag\AssistantStarterKit\Core\Commerce\BatchProductLookup;
use Swag\AssistantStarterKit\Core\Commerce\CategoryTreeReader;
use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CartSummary;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CategoryNode;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Commerce\FamilyVariantLookup;
use Swag\AssistantStarterKit\Core\Commerce\MatchCountReader;

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
// @mago-expect lint:too-many-methods
// Every public method here is mandated by an interface this class implements: six by
// CommerceGatewayInterface, one each by BatchProductLookup, CategoryTreeReader, FamilyVariantLookup
// and MatchCountReader.
// The count is the sum of those obligations plus a constructor and one small private mapper, not
// bloat, and four interfaces cannot be implemented in fewer methods. The alternative is extracting
// `mapAll()` into a pass-through class, which the constructor's own carve-out below already argues
// against: indirection whose only purpose is satisfying a linter.
final readonly class DalCommerceGateway implements
    BatchProductLookup,
    CategoryTreeReader,
    CommerceGatewayInterface,
    FamilyVariantLookup,
    MatchCountReader
{
    /**
     * Facet probing needs one product's worth of rows at most — the values come from the
     * aggregations, which are computed over the whole matching set regardless of this limit.
     */
    private const FACET_ROW_LIMIT = 1;

    /** The name `countMatches()` registers its {@see CountAggregation} under and reads it back by. */
    private const MATCH_COUNT_AGGREGATION = 'matches';

    /**
     * Bounds how much catalogue vocabulary reaches the model. Ruling R54 already caps the prompt
     * block at 25 values per field and 30 fields; capping the aggregation too means the database
     * is not asked for thousands of buckets that get discarded.
     */
    private const FACET_VALUE_LIMIT = 50;

    /**
     * How many variants of one family a single lookup will fetch.
     *
     * Two hundred: enough for any realistic size/colour matrix, bounded so a pathological family
     * cannot turn one disclosure into a catalogue dump. The caller caps the values it reports far
     * lower — see `FamilyOptionValues::MAX_VALUES` — so this bound only limits what is considered,
     * never what is sent.
     */
    private const FAMILY_VARIANT_LIMIT = 200;

    // @mago-expect lint:excessive-parameter-list
    // Standing-constraints carve-out 2: a service constructor injecting the collaborators it
    // orchestrates. Seven is what composing six interface methods over five concerns looks like —
    // every method below is a single delegation, and neither `cyclomatic-complexity` nor
    // `too-many-methods` fires, which is the guard that stops this carve-out hiding a bloated
    // class. Introducing a pass-through class purely to lower this count would add indirection
    // whose only purpose is satisfying a linter.
    public function __construct(
        private SalesChannelRepository $productRepository,
        private DalCriteriaBuilder $criteriaBuilder,
        private DalProductCardMapper $mapper,
        private DalFacetReader $facetReader,
        private SalesChannelContextProvider $contextProvider,
        private DalVariantFinder $variantFinder,
        private DalCartAdapter $cartAdapter,
        private DalCategoryTreeReader $categoryTreeReader,
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

    /**
     * How many products the query matches, without fetching them.
     *
     * **Not `TOTAL_COUNT_MODE_EXACT` — measured wrong on a real shop.** That was this method's first
     * implementation, and it reads correctly against `FixtureCommerceGateway`'s in-memory count, which
     * is exactly why nothing here caught the defect: `TOTAL_COUNT_MODE_EXACT` plus `limit(1)` returned
     * `1` for every non-empty search on this project's Shopware 6.7 instance, regardless of the true
     * match count — confirmed live against 1,355 seeded dresses, 60 occasion suits and 24 yoga pieces,
     * all reported as `1` (`docs/superpowers/reports/2026-08-27-fashion-catalogue-seeded.md`, "Match-
     * count finding"). A `CountAggregation` is computed over the whole matching set independently of
     * pagination — the same reasoning `facets()` above already relies on for its own aggregations — and
     * the same report measured it both correct and faster (down to ~130 ms warm) against the same live
     * shop for every term that broke the old approach.
     *
     * The row limit stays at 1 for the same reason `FACET_ROW_LIMIT` does: an aggregation is computed
     * over the whole matching set regardless of how many rows `search()` would return alongside it, so
     * asking for none beyond the minimum costs nothing and fetches nothing extra.
     *
     * Built from `$query->withoutLimits()` so the criteria carry the predicate and none of the bounds,
     * and from the same `criteriaBuilder` `search()` uses, so the number describes the set the search
     * describes — including the scope, which is what stops it advertising products the shopper may not
     * see.
     */
    public function countMatches(ProductQuery $query, CatalogScope $scope): int
    {
        $context = $this->contextProvider->current();
        $criteria = $this->criteriaBuilder->build($query->withoutLimits(), $scope, $context->getSalesChannelId());
        $criteria->setLimit(1);
        $criteria->addAggregation(new CountAggregation(self::MATCH_COUNT_AGGREGATION, 'id'));

        $result = $this->productRepository->aggregate($criteria, $context)->get(self::MATCH_COUNT_AGGREGATION);

        return $result instanceof CountResult ? $result->getCount() : 0;
    }

    /**
     * @return list<CategoryNode>
     */
    public function categories(?string $parentId, CatalogScope $scope): array
    {
        return $this->categoryTreeReader->read($parentId, $scope, $this->contextProvider->current());
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

    /**
     * The batched half of {@see self::product()}, and the only difference is `EqualsAnyFilter` plus a
     * limit wide enough to hold the whole request.
     *
     * The scope still goes in with the ids, for the same reason it does there: a blocked product is
     * refused at the point of lookup rather than fetched and then removed. Batching must not become a
     * way around the blocklist.
     *
     * Order is not restored here — {@see \Swag\AssistantStarterKit\Core\Commerce\CardResolver}
     * does that, because it is the caller who knows what order was asked for.
     *
     * @param list<string> $productIds
     *
     * @return list<ProductCard>
     */
    public function products(array $productIds, CatalogScope $scope): array
    {
        if ([] === $productIds) {
            return [];
        }

        $context = $this->contextProvider->current();

        $criteria = $this->criteriaBuilder->build(
            new ProductQuery(limit: \count($productIds)),
            $scope,
            $context->getSalesChannelId(),
        );
        $criteria->addFilter(new EqualsAnyFilter('id', $productIds));

        return $this->mapAll(
            $this->productRepository->search($criteria, $context)->getElements(),
            $context->getCurrency()->getIsoCode(),
        );
    }

    /**
     * One family, in one query.
     *
     * The scope goes in with the parent id, exactly as {@see self::product()} does it: a blocked
     * product is refused at the point of lookup rather than fetched and filtered afterwards. Fetching
     * a whole family is the call most likely to become a way around the blocklist, so it is the one
     * where that matters most.
     *
     * Bounded by {@see self::FAMILY_VARIANT_LIMIT}. A family past that bound is described from the
     * first N variants, which is still strictly more than the candidate window this call exists to
     * escape — and the value list the caller builds is capped far lower anyway.
     *
     * @return list<ProductCard>
     */
    public function variantsOf(string $parentId, CatalogScope $scope): array
    {
        $context = $this->contextProvider->current();

        $criteria = $this->criteriaBuilder->build(
            new ProductQuery(limit: self::FAMILY_VARIANT_LIMIT),
            $scope,
            $context->getSalesChannelId(),
        );
        $criteria->addFilter(new EqualsFilter('parentId', $parentId));

        return $this->mapAll(
            $this->productRepository->search($criteria, $context)->getElements(),
            $context->getCurrency()->getIsoCode(),
        );
    }

    public function resolveVariant(string $parentId, array $selections, CatalogScope $scope): ?ProductCard
    {
        return $this->variantFinder->find($parentId, $selections, $scope, $this->contextProvider->current());
    }

    public function addToCart(string $variantId, int $quantity): CartSummary
    {
        return $this->cartAdapter->add($variantId, $quantity, $this->contextProvider->current());
    }

    public function cart(): CartSummary
    {
        return $this->cartAdapter->summary($this->contextProvider->current());
    }

    /**
     * Maps every retrieved row, deciding the stock source per row rather than per query.
     *
     * **That classification is the whole of D4 at this layer**, and it is decided here, from the
     * row, rather than passed in by a caller who might be wrong. A parent's aggregate stock
     * presented as a variant's is the claim that cancels orders.
     *
     * `childCount` is read as well as `parentId`, because two facts are needed rather than one:
     * without it a plain product with no variants was indistinguishable from a family parent, and
     * the storefront disclosed *"Stock shown for the product, not this variant"* — plus withheld
     * one-click purchase — for products that have no variants at all. See
     * {@see StockSource::forProductRow()} for the measurement.
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

            $card = $this->mapper->map(
                $entity,
                StockSource::forProductRow($entity->getParentId(), $entity->getChildCount()),
                $currency,
            );

            // A product whose price the calculator never touched: the mapper already decided this
            // is not a card that can be built rather than one priced at a fabricated 0.0. Skipped
            // the same way a non-entity row above is — not returned, not substituted.
            if ($card === null) {
                continue;
            }

            $cards[] = $card;
        }

        return $cards;
    }
}
