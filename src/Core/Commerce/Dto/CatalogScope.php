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
 * is *this whole branch*. `blockedStreamIds` is *whatever the merchant said*, as a Dynamic Product
 * Group: the only one of the three that scales past a catalogue somebody can click through.
 *
 * All three are absolute and all three are OR-ed into one exclusion. The group carries one caveat the
 * id lists do not: {@see \Swag\AssistantStarterKit\Core\Policy\BlocklistFilter} re-checks ids as a
 * second line of defence and cannot re-check a group without a query per card, so a group is enforced
 * at retrieval only. That is the same guarantee the shop's own listing pages have.
 *
 * ## `hideOutOfStock` is the soft scope this docblock used to say did not exist
 *
 * The section above promised that a genuine soft scope — *"not volunteered, but discussed when a
 * shopper names the product outright"* — would need building rather than renaming. This is it, and
 * it is the only field here that is not absolute.
 *
 * A blocked id is never fetched by anything. `hideOutOfStock` is honoured by **discovery reads
 * only**: {@see \Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface::search()} and the
 * match count beside it, so the two agree about what a shopper may find. A lookup by id, a variant
 * resolution and the cart's pre-check ignore it deliberately — asked *"is the blue M still
 * available?"*, an assistant that cannot see the sold-out variant answers *"no such product"*,
 * which is a different lie from the one the setting exists to prevent but still a lie. The
 * asymmetry is the feature; it is recorded here rather than left to be discovered in
 * {@see \Swag\AssistantStarterKit\Core\Commerce\Dal\DalDiscoveryFilters}.
 */
final readonly class CatalogScope
{
    /**
     * @param list<string> $includeCategoryIds programmatic only; the settings form exposes no allowlist
     * @param list<string> $blockedProductIds
     * @param list<string> $blockedCategoryIds
     * @param bool         $hideOutOfStock     merchant setting; discovery reads only — see the class docblock
     * @param list<string> $blockedStreamIds   Dynamic Product Group ids; absolute, like the two lists above
     */
    // @mago-expect lint:excessive-parameter-list
    // Standing-constraints carve-out, the same one ProductCard and AssistantConfig take: this is a
    // flat value object whose whole purpose is to be flat. Every field is an independent merchant
    // decision the gateway reads on its own, and grouping them behind a sub-object would hide which
    // ones a given filter consults — the exact thing this class's docblock exists to spell out.
    public function __construct(
        public array $includeCategoryIds = [],
        public array $blockedProductIds = [],
        public array $blockedCategoryIds = [],
        public int $minDescriptionWords = 0,
        public bool $hideOutOfStock = false,
        public array $blockedStreamIds = [],
    ) {}
}
