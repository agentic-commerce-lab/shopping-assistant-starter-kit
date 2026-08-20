<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval;

use Swag\AssistantStarterKit\Core\Commerce\Dto\FilterClause;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;

final readonly class QueryBuildResult
{
    /**
     * @param list<string>           $droppedFields
     * @param list<FilterClause>     $selectionFilters the subset of `$query->filters` that came
     *     from option selections rather than from price or brand. Carried separately so a caller
     *     can retry WITHOUT them — see
     *     {@see \Swag\AssistantStarterKit\Core\Retrieval\UnmatchedOptionRetry} — without
     *     having to guess which clause came from where, and without dropping a price bound the
     *     shopper actually stated.
     * @param list<VariantSelection> $canonicalSelections each of $intent->selections,
     *     re-expressed with the catalog's own spelling of its option value — see
     *     {@see \Swag\AssistantStarterKit\Core\Retrieval\Filter\VariantSelectionResolution}.
     *     Callers resolving a variant (never the retrieval filter, which is already
     *     built from this same canonicalisation) must use these, not the raw
     *     selections on the originating {@see ShopperIntent}.
     */
    public function __construct(
        public ProductQuery $query,
        public array $droppedFields,
        public array $canonicalSelections = [],
        public array $selectionFilters = [],
    ) {}
}
