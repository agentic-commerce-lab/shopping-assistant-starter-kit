<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Tool;

use Swag\AssistantStarterKit\Core\Commerce\CommerceGatewayInterface;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\FamilyVariantLookup;

/**
 * Merges {@see FamilyAlternatives} onto a search result's summaries — the sizes a shopper could buy
 * instead, on the path their question really takes. Traced 2026-09-21, *"habt ihr das in 700x32?"*
 * produced one `search_products` call and never reached `get_product`, where these used to be
 * attached.
 *
 * Its own class so {@see SearchProductsTool} gains one call and no branches — that class is measured
 * against a cyclomatic budget summed across its methods, and the setting check plus the capability
 * check plus the merge is three of them for a feature that is off in most shops.
 *
 * The capability check is `instanceof` rather than a wider type, matching
 * {@see WholeFamilyResolver}: reading a family is optional, and a gateway without it degrades to the
 * result it produced before.
 */
final class ShortlistAlternatives
{
    private function __construct() {}

    /**
     * @param list<array{id: string, name: string, options: array<string, string>, properties: array<string, list<string>>, propertiesWithheld?: array<string, int>, bundle?: list<array{name: string, quantity?: int, optional?: true}>, documents?: list<string>, department?: string, soldOut?: true, available?: true, reasons?: list<string>, description?: string, alternatives?: list<array<string, string>>, alternatives_truncated?: true}> $products summaries, positionally aligned with `$cards`
     * @param list<ProductCard> $cards
     *
     * @return list<array{id: string, name: string, options: array<string, string>, properties: array<string, list<string>>, propertiesWithheld?: array<string, int>, bundle?: list<array{name: string, quantity?: int, optional?: true}>, documents?: list<string>, department?: string, soldOut?: true, available?: true, reasons?: list<string>, description?: string, alternatives?: list<array<string, string>>, alternatives_truncated?: true}>
     */
    public static function merge(
        array $products,
        array $cards,
        CommerceGatewayInterface $gateway,
        CatalogScope $scope,
        bool $enabled,
    ): array {
        $lookup = $enabled && $gateway instanceof FamilyVariantLookup ? $gateway : null;

        foreach (FamilyAlternatives::forAll($cards, $lookup, $scope) as $index => $key) {
            $products[$index] += $key;
        }

        return $products;
    }
}
