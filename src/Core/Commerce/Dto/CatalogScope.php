<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Dto;

/**
 * What the assistant is allowed to see of the catalogue.
 *
 * ## `excludeCategoryIds` is gone, and it was never a second thing
 *
 * The settings form used to carry two category lists — *excluded* and *blocked* — whose names
 * promised a soft/hard distinction: out of scope versus never, under any circumstances. Nothing
 * implemented it. {@see \Swag\AssistantStarterKit\Core\Commerce\Dal\DalCriteriaBuilder} concatenated
 * the two into one array and applied them as a single filter, and the fixture gateway checked them
 * with two `array_intersect` calls whose results were OR-ed. Identical behaviour, two names, two
 * places for a merchant to look and get it half right.
 *
 * The one asymmetry was worse than no asymmetry: {@see \Swag\AssistantStarterKit\Core\Policy\BlocklistFilter}
 * re-checked the blocked list and not the excluded one. That pass exists as a second line of defence
 * for a gateway whose scope mapping is incomplete — so the field with the *softer* name was the one
 * covered twice, and the difference was invisible to anyone who had not read both classes.
 *
 * If a genuine soft scope is ever wanted — a category the assistant does not volunteer but will
 * discuss when a shopper names the product outright — it needs building, not renaming. There is no
 * concept of "retrieved but not offered" anywhere in the retrieval layer today.
 *
 * ## Two fields, one sentence each
 *
 * `blockedProductIds` is *this product*, and it also matches on `parentId`, so blocking one parent
 * removes every variant beneath it — something a category list cannot express. `blockedCategoryIds`
 * is *this whole branch*. That is the entire distinction, and it survives being said out loud.
 */
final readonly class CatalogScope
{
    /**
     * @param list<string> $includeCategoryIds programmatic only; the settings form exposes no allowlist
     * @param list<string> $blockedProductIds
     * @param list<string> $blockedCategoryIds
     */
    public function __construct(
        public array $includeCategoryIds = [],
        public array $blockedProductIds = [],
        public array $blockedCategoryIds = [],
        public int $minDescriptionWords = 0,
    ) {}
}
