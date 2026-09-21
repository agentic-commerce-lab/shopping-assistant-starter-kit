<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

use Swag\AssistantStarterKit\Core\Commerce\Dal\DalFacetReader;
use Swag\AssistantStarterKit\Core\Commerce\Dal\DalFilterTranslator;
use Swag\AssistantStarterKit\Core\Retrieval\PriceSort;

final readonly class ProductQuery
{
    /** @param list<FilterClause> $filters */
    // @mago-expect lint:excessive-parameter-list
    // One parameter per orthogonal dimension of a catalogue query — what to match, how to filter,
    // how many to return, how to sort, how wide to retrieve, and where the shopper is standing.
    // Grouping any of them behind a sub-object would hide the query this class exists to be, and
    // every one of them is independently optional at the call site.
    public function __construct(
        public ?string $term = null,
        public array $filters = [],
        public int $limit = 10,
        public ?PriceSort $sort = null,
        public ?int $candidateLimit = null,
        /**
         * The category the shopper is browsing, when the storefront reported one.
         *
         * A constraint, never a scope. {@see CatalogScope::$includeCategoryIds} is OR-ed and is the
         * merchant's; this is AND-ed with it and is the shopper's, so it can only ever narrow what
         * the merchant already allowed.
         */
        public ?string $categoryId = null,
    ) {}

    /**
     * How many units to RETRIEVE, as opposed to how many to return.
     *
     * A gateway applies sort and limit together, so whatever it truncates is gone before
     * variant resolution can disambiguate it — and ranking's in-stock bias sorts a sold-out
     * unit last, which is exactly the unit a variant question is usually about. Retrieval
     * therefore reads this, and the caller narrows to {@see self::$limit} only after
     * resolution has run over the whole window.
     *
     * A candidate window narrower than the return limit is meaningless — it would let a
     * caller reintroduce the truncation this split exists to remove — so it is ignored
     * rather than honoured.
     */
    /**
     * Whether the shopper named a concrete unit rather than describing a kind of product.
     *
     * ## What it decides
     *
     * Whether a search may withhold anything. {@see \Swag\AssistantStarterKit\Core\Commerce\Dal\DalDiscoveryFilters}
     * keeps unbuyable products out of discovery, and that is right while somebody is browsing. It is
     * wrong the moment they have named the thing: found on staging 2026-09-21, *"habt ihr das Trail
     * Jersey in Blau, Größe M?"* came back as *"a jersey by that exact name was not found"* — for a
     * product the shop carries, whose Blue/M is an out-of-stock closeout variant.
     *
     * That is the exact failure the discovery/lookup split exists to prevent, and the split did not
     * prevent it: `get_product` and `variantsOf()` are protected, and the model never calls them for
     * this question. It makes one `search_products` call. A search carrying option selections **is**
     * a lookup, whatever tool it arrived through.
     *
     * ## Why a brand and a price do not count
     *
     * *"Do you have Shimano brakes"* names a maker, *"under 80 euro"* names a budget. Neither is a
     * thing on a shelf, and a shopper who has not picked a size is still browsing — which is where
     * hiding the unbuyable belongs. Only an option narrows to a unit.
     *
     * A property group that is not a variant axis (`Season`, say) counts too, and deliberately: the
     * query cannot tell which groups a shop uses as axes, and the error costs opposite amounts.
     * Counting one too many shows a product somebody cannot buy; counting one too few tells them a
     * product they named does not exist.
     */
    public function namesAVariant(): bool
    {
        foreach ($this->filters as $filter) {
            if ($filter->field === DalFilterTranslator::MANUFACTURER_FIELD) {
                continue;
            }

            if (str_starts_with($filter->field, DalFacetReader::GROUP_PREFIX)) {
                return true;
            }
        }

        return false;
    }

    public function retrievalLimit(): int
    {
        return max($this->limit, $this->candidateLimit ?? $this->limit);
    }

    /**
     * The same query with every limit removed, for {@see \Swag\AssistantStarterKit\Core\Commerce\MatchCountReader}.
     *
     * A counting caller needs the predicate and none of the bounds. Expressed here rather than at the
     * call site so "the same query, uncapped" means one thing across implementations — the fixture
     * counts rows and the DAL asks the database, and they must agree about what they are counting.
     */
    public function withoutLimits(): self
    {
        return new self(
            term: $this->term,
            filters: $this->filters,
            limit: \PHP_INT_MAX,
            sort: $this->sort,
            categoryId: $this->categoryId,
        );
    }

    /**
     * The same query without the shopper's location, for the retry P9 mandates: a category is a
     * helpful default, not a cage, and asking for gloves in the jersey aisle must return gloves.
     */
    public function withoutCategory(): self
    {
        return new self(
            term: $this->term,
            filters: $this->filters,
            limit: $this->limit,
            sort: $this->sort,
            candidateLimit: $this->candidateLimit,
        );
    }

    /**
     * The same query with different words, so two of {@see \Swag\AssistantStarterKit\Core\Retrieval\RetrievalPass}'s
     * relaxations can be applied together instead of one at a time.
     *
     * Measured on staging, 2026-09-03: *"i want purple tyres"* against a shop with four tyres
     * returned nothing, because dropping the colour kept the plural ("tyres" matches no name) and
     * relaxing the plural kept the colour. Reaching the tyres needs both, and neither retry could
     * express the other's change without this.
     */
    public function withTerm(?string $term): self
    {
        return new self(
            term: $term,
            filters: $this->filters,
            limit: $this->limit,
            sort: $this->sort,
            candidateLimit: $this->candidateLimit,
            categoryId: $this->categoryId,
        );
    }
}
