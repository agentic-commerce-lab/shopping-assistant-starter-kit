<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval\Filter;

use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;

/**
 * The outcome of resolving one {@see VariantSelection} against a {@see \Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet}:
 * the {@see FilterResolution} for the retrieval filter (unchanged contract), plus the
 * SAME selection re-expressed with the catalog's own spelling of its option value.
 *
 * Splitting this out (rather than widening {@see FilterResolution} itself) keeps that
 * class's contract — "a filter to apply, a field to record as dropped, or neither,
 * never both" — about the retrieval filter only; `$canonical` is a second, independent
 * output of the same resolution used downstream by {@see \Swag\AssistantStarterKit\Core\Grounding\VariantResolver},
 * which never sees a `FacetSet` itself.
 *
 * `$canonical` is never null: when the option cannot be matched against the catalog
 * (an unknown group, or a value no facet holds), it falls back to the selection as the
 * model gave it — no worse than before this class existed, and {@see FilterResolution::$droppedField}
 * already records that the retrieval filter itself was dropped.
 */
final readonly class VariantSelectionResolution
{
    public function __construct(
        public FilterResolution $filter,
        public VariantSelection $canonical,
    ) {}
}
