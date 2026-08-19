<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Retrieval;

use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;

final readonly class ShopperIntent
{
    // @mago-expect lint:excessive-parameter-list
    // Signature dictated verbatim by the plan; no natural sub-object to extract
    // without adding indirection for its own sake.
    /**
     * @param list<VariantSelection> $selections
     * @param list<string>           $referencedProductIds
     */
    public function __construct(
        public ?string $term = null,
        public ?float $priceMax = null,
        public ?float $priceMin = null,
        public ?string $brand = null,
        public array $selections = [],
        public array $referencedProductIds = [],
    ) {}
}
