<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\FamilyVariantLookup;

/**
 * Fetches the full variant set of each family that narrowing truncated — one query per family, and
 * only when there is something to ask about.
 *
 * ## Why
 *
 * Measured: at the model's default `limit: 5` the candidate window is 20, so a 30-variant family's
 * disclosure listed `Size 1` … `Size 20` and could not name the `Size 30` the shopper had asked for.
 * Widening the window was the wrong fix — thirty variants is not a ceiling and a real shop has
 * families of a hundred. This asks the one question that scales: *give me this family*.
 *
 * A spike confirmed it pays before it was built: with the family fully described, the model reads the
 * option value out of the reply, searches again with it, and renders the variant it was asked about.
 *
 * ## Why it is its own class
 *
 * {@see TruncatedFamilies} must stay pure — it is the summariser and its tests build cards by hand
 * with no gateway. {@see SearchProductsTool} is already the largest class in this project and sits on
 * a 400-line file budget. So the I/O lives here, where it is one visible thing.
 *
 * A gateway that does not implement {@see FamilyVariantLookup} yields an empty map and the disclosure
 * falls back to the candidate window — exactly the behaviour before this existed.
 */
final class WholeFamilyResolver
{
    private function __construct() {}

    /**
     * @param list<ProductCard> $survivors
     * @param list<ProductCard> $returned
     *
     * @return array<string, list<ProductCard>>
     */
    public static function resolve(
        CommerceGatewayInterface $gateway,
        array $survivors,
        array $returned,
        CatalogScope $scope,
    ): array {
        if (!$gateway instanceof FamilyVariantLookup) {
            return [];
        }

        $whole = [];

        foreach (TruncatedFamilies::truncatedParentIds($survivors, $returned) as $parentId) {
            $whole[$parentId] = $gateway->variantsOf($parentId, $scope);
        }

        return $whole;
    }
}
