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
    ) {}
}
