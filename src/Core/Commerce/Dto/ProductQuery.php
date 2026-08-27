<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

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
        public ?string $sort = null,
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
}
