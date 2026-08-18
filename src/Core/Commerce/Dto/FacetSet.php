<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

final readonly class FacetSet
{
    /** @param list<Facet> $facets */
    public function __construct(
        public array $facets = [],
    ) {}

    public function has(string $field): bool
    {
        foreach ($this->facets as $facet) {
            if ($facet->field === $field) {
                return true;
            }
        }

        return false;
    }

    public function get(string $field): ?Facet
    {
        foreach ($this->facets as $facet) {
            if ($facet->field === $field) {
                return $facet;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function fields(): array
    {
        return array_map(static fn(Facet $f): string => $f->field, $this->facets);
    }
}
