<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Commerce\Fixture;

use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;
use Swag\AssistantStarterKit\Core\Commerce\VariantSelectionMatcher;

/**
 * Whether one sellable unit matches every given {@see VariantSelection}, for
 * {@see \Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway::resolveVariant()}.
 *
 * The logic itself moved to {@see VariantSelectionMatcher} when the DAL gateway needed the same
 * rules, and this now delegates. That is not indirection for its own sake:
 * {@see \Swag\AssistantStarterKit\Core\Grounding\VariantResolver} cannot tell the two gateways
 * apart, so two independent matchers would let the fixture suite prove a contract the real shop
 * did not implement — and this is the contract that decides whether a shopper is told a sold-out
 * variant is available.
 *
 * Kept as a name rather than deleted because the fixture gateway's collaborators are all named
 * `Fixture*`, and its call sites read better for it.
 */
final class FixtureVariantMatcher
{
    private function __construct() {}

    /** @param list<VariantSelection> $selections */
    public static function matchesAll(ProductCard $unit, array $selections): bool
    {
        return VariantSelectionMatcher::matchesAll($unit, $selections);
    }
}
