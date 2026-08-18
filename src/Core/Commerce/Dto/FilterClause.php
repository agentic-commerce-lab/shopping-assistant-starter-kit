<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

final readonly class FilterClause
{
    public function __construct(
        public string $field,
        public FilterOperator $operator,
        public mixed $value,
    ) {}
}
