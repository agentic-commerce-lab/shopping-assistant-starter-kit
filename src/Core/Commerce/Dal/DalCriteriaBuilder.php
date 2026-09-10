<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dal;

use Shopware\Core\Content\Product\SalesChannel\ProductAvailableFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Retrieval\PriceSort;

/**
 * Translates a {@see ProductQuery} plus a {@see CatalogScope} into a DAL {@see Criteria}.
 *
 * Three things here are load-bearing:
 *
 * 1. **The limit comes from `retrievalLimit()`, never `$limit`.** The gateway applies sort and
 *    limit together, so anything truncated here is gone before variant resolution runs, and
 *    ranking's in-stock bias sorts a sold-out unit last. Narrowing to what the model asked for
 *    happens in the caller, after resolution.
 * 2. **Scope exclusions are retrieval filters, not a post-pass.** A blocked product must never
 *    enter the model's context (D5), and not fetching it is strictly less exposure than fetching
 *    it and removing it afterwards. Callers still apply their own blocklist as a second line.
 * 3. **No closeout or stock filter is added.** `ProductAvailableFilter` checks visibility and
 *    `active`, not stock, and that is exactly right: a sold-out variant must stay retrievable,
 *    or "is the blue M in stock?" gets answered with "no such product".
 */
final readonly class DalCriteriaBuilder
{
    public function __construct(
        private DalFilterTranslator $translator = new DalFilterTranslator(),
        private BundleSupport $bundles = new BundlesUnavailable(),
    ) {}

    public function build(ProductQuery $query, CatalogScope $scope, string $salesChannelId): Criteria
    {
        $criteria = new Criteria();
        $criteria->setLimit($query->retrievalLimit());

        if ($query->term !== null && $query->term !== '') {
            $criteria->setTerm($query->term);
        }

        $criteria->addFilter(new ProductAvailableFilter($salesChannelId));

        foreach ($query->filters as $clause) {
            $criteria->addFilter($this->translator->translate($clause));
        }

        $this->applyScope($criteria, $scope);

        // ANDed with the scope filters above, which is the point: a page category can only narrow
        // what the merchant already allows. Never fold this into `applyScope()` — its include list
        // is OR-ed, and a client-supplied value there would widen merchant policy (P8).
        if ($query->categoryId !== null) {
            $criteria->addFilter(new EqualsAnyFilter('categoriesRo.id', [$query->categoryId]));
        }

        // Field AND direction, both from the enum: a shopper asking for the cheapest gets ascending,
        // and the field can only ever be the accessor {@see PriceSort} names. See its docblock for
        // why a sort string from a tool call is not something this line should ever have accepted.
        if ($query->sort !== null) {
            $criteria->addSorting(new FieldSorting($query->sort->field(), $query->sort->direction()));
        }

        // The mapper reads these and drops anything it cannot resolve, so a missing
        // association costs every card its options silently rather than loudly.
        $criteria->addAssociations(['options.group', 'properties.group', 'deliveryTime', 'cover.media']);

        // **Guarded, because naming an unknown field fails the whole read.** `bundleItems` exists
        // only where Shopware Commercial's `ProductExtension` registered it, and this builder is on
        // the path of every product read the assistant makes — search, direct lookup and the cart's
        // own pre-check — so an unconditional association would break all three in any shop without
        // Commercial. The nested `.product` is what carries the member's name; the join row alone
        // holds an id and a quantity. See DalBundleItems for the read at the other end.
        if ($this->bundles->isAvailable()) {
            $criteria->addAssociation(DalBundleItems::ASSOCIATION . '.product');
        }

        return $criteria;
    }

    /**
     * Exclusions are added as one `NotFilter` over an OR of the blocked sets, and only when
     * something is actually blocked: an empty `NotFilter` excludes nothing while still costing
     * a join, and it would make a test for "the exclusion exists" pass for the wrong reason.
     *
     * `CatalogScope::$minDescriptionWords` has **no DAL equivalent** — a word count is not a
     * filterable field — so it is not applied here. It is honoured by callers that can compute
     * it, and silently ignoring it is recorded rather than hidden.
     */
    private function applyScope(Criteria $criteria, CatalogScope $scope): void
    {
        if ($scope->includeCategoryIds !== []) {
            $criteria->addFilter(new EqualsAnyFilter('categoriesRo.id', $scope->includeCategoryIds));
        }

        $exclusions = [];

        if ($scope->blockedProductIds !== []) {
            $exclusions[] = new EqualsAnyFilter('id', $scope->blockedProductIds);
            // A blocked parent must also block its variants: a variant is a separate row with
            // its own id, so an id-only exclusion would let the child straight through.
            $exclusions[] = new EqualsAnyFilter('parentId', $scope->blockedProductIds);
        }

        if ($scope->blockedCategoryIds !== []) {
            $exclusions[] = new EqualsAnyFilter('categoriesRo.id', $scope->blockedCategoryIds);
        }

        if ($exclusions === []) {
            return;
        }

        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_OR, $exclusions));
    }
}
