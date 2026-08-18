<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

final readonly class Facet
{
    /** @param list<string> $values */
    public function __construct(
        public string $field,
        public FacetType $type,
        public array $values = [],
        public ?float $min = null,
        public ?float $max = null,
    ) {}
}
