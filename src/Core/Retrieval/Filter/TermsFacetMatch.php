<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval\Filter;

/**
 * The outcome of {@see TermsFacetFinder::findContaining()}: the facet field that
 * held a matching option, paired with that option's canonical spelling as stored
 * in the catalog — not the model's raw casing. Both field and value must come
 * from the catalog, never from the model, which is the guarantee this task exists
 * to enforce.
 */
final readonly class TermsFacetMatch
{
    public function __construct(
        public string $field,
        public string $value,
    ) {}
}
