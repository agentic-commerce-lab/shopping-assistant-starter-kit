<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

final readonly class ProductQuery
{
    /** @param list<FilterClause> $filters */
    public function __construct(
        public ?string $term = null,
        public array $filters = [],
        public int $limit = 10,
        public ?string $sort = null,
        public ?int $candidateLimit = null,
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
}
