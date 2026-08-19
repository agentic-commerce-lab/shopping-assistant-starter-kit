<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval\Filter;

use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterClause;

/**
 * Outcome of resolving a single {@see \Swag\AssistantStarterKit\Core\Retrieval\ShopperIntent}
 * constraint against a {@see \Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet}: either
 * a filter to apply, a field to record as dropped, or neither when the intent
 * carried no such constraint at all. Never both at once.
 */
final readonly class FilterResolution
{
    public function __construct(
        public ?FilterClause $filter = null,
        public ?string $droppedField = null,
    ) {}

    public static function none(): self
    {
        return new self();
    }

    public static function applied(FilterClause $filter): self
    {
        return new self(filter: $filter);
    }

    public static function dropped(string $field): self
    {
        return new self(droppedField: $field);
    }
}
