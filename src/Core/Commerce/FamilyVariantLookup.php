<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce;

use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;

/**
 * A gateway that can return one family's variants without going through retrieval.
 *
 * **Deliberately a separate interface rather than a method on {@see CommerceGatewayInterface}**, for
 * the same reason as {@see BatchProductLookup}: that one is marked `@api Public extension point`, so
 * adding a method would break every gateway a merchant has already written.
 *
 * ## Why it exists
 *
 * Measured: a search whose candidate window is 20 cannot describe a family of 30. Asked for
 * `Size 30`, the assistant rendered variants 1 to 5 — and the disclosure beside them listed
 * `Size 1` … `Size 20`, because that is all retrieval had fetched. The reply was honest and useless:
 * *"there are at least 20 and I did not get them all"*, about the one value the shopper had named.
 *
 * A wider candidate window was the wrong fix — thirty variants is not a ceiling, and a real shop has
 * families of a hundred. This asks the one targeted question instead: **give me this family**, once,
 * only when narrowing actually truncated it.
 *
 * A spike on 2026-08-25 confirmed the value before this was built: with the family fully fetched, the
 * model reads the option value out of the reply, searches again with it, and renders the variant it
 * was asked about — `scale_family_beyond_window` green on both archetypes, 3/3 runs each.
 *
 * ## What an implementation owes the caller
 *
 * `$scope` must be honoured exactly as {@see CommerceGatewayInterface::product()} honours it: a
 * blocked product is refused at the point of lookup. Fetching a family must not become a way around
 * the blocklist, which is the one thing a "give me everything under this parent" call could easily
 * become.
 *
 * Order is not part of the contract. The caller reads option values, not positions.
 */
interface FamilyVariantLookup
{
    /**
     * Every sellable unit under `$parentId` that `$scope` allows, or an empty list when the parent is
     * unknown, has no variants, or is itself out of scope.
     *
     * @return list<ProductCard>
     */
    public function variantsOf(string $parentId, CatalogScope $scope): array;
}
