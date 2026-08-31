<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed\Bike;

/**
 * Resolves one product's three references against the shop, recording whatever it cannot find.
 *
 * Split from {@see BikeSeedPlan} because it is where that class's complexity actually was: four
 * lookups, each of which may fail and each of which has to say so rather than return a null the
 * payload would carry into a write. Keeping the "look it up, or name what is missing" pattern in one
 * place also stops them from drifting apart, which is how one of them ends up failing silently.
 *
 * The two option lookups delegate to {@see OptionLookup}: they differ in where their map comes from
 * and what the caller does with the result, never in how a value becomes an id.
 *
 * @phpstan-import-type ProductSpec from BikeProducts
 */
final class ProductReferences
{
    private function __construct() {}

    /**
     * All four lookups, or nothing.
     *
     * **Every miss is recorded before the null comes back.** The four are deliberately not
     * short-circuited: a catalogue with a renamed category *and* a deleted brand must report both in
     * one pass, which is the whole reason {@see UnresolvedReferences} collects instead of throwing.
     * An early return on the first failure would have quietly undone that.
     *
     * The variant axes are resolved for their side effect only — {@see BikeVariantFamily} looks the
     * ids up again as it builds the cross product, and what matters here is finding out beforehand
     * whether it will be able to.
     *
     * @param ProductSpec $product
     */
    public static function resolveAll(
        array $product,
        BikeSeedContext $ctx,
        UnresolvedReferences $unresolved,
    ): ?ResolvedProduct {
        $categoryId = self::category($product, $ctx->categoryIds, $unresolved);
        $manufacturerId = self::manufacturer($product, $ctx->shop, $unresolved);
        $properties = self::properties($product, $ctx->optionIds, $unresolved);
        $axesResolve = self::options($product, $ctx->optionIds, $unresolved);

        if ($categoryId === null || $manufacturerId === null || $properties === null || !$axesResolve) {
            return null;
        }

        return new ResolvedProduct($categoryId, $manufacturerId, $properties);
    }

    /**
     * @param ProductSpec           $product
     * @param array<string, string> $categoryIds
     */
    public static function category(array $product, array $categoryIds, UnresolvedReferences $unresolved): ?string
    {
        $id = $categoryIds[$product['category']] ?? null;

        if (!\is_string($id)) {
            $unresolved->add('category "' . $product['category'] . '"');

            return null;
        }

        return $id;
    }

    /** @param ProductSpec $product */
    public static function manufacturer(array $product, ShopTaxonomy $shop, UnresolvedReferences $unresolved): ?string
    {
        $id = $shop->manufacturerIdsByName[$product['manufacturer']] ?? null;

        if (!\is_string($id)) {
            $unresolved->add('manufacturer "' . $product['manufacturer'] . '"');

            return null;
        }

        return $id;
    }

    /**
     * True when every value on every variant axis resolves to an option id.
     *
     * Checked up front rather than while building the family: {@see BikeVariantFamily} asserts on a
     * missing option, and an assertion is the wrong way to tell somebody their catalogue names a
     * colour the shop does not have.
     *
     * @param ProductSpec                          $product
     * @param array<string, array<string, string>> $optionIds
     */
    public static function options(array $product, array $optionIds, UnresolvedReferences $unresolved): bool
    {
        // The ids themselves are discarded: BikeVariantFamily looks them up again as it builds the
        // cross product, and what this call is for is finding out — before any write starts — whether
        // it will be able to.
        return OptionLookup::resolve($product['variants'] ?? [], $optionIds, $unresolved, 'option') !== null;
    }

    /**
     * One product's descriptive properties as DAL option references, or null when any of them names a
     * value the shop does not have.
     *
     * **Null rather than a partial list**, unlike {@see self::options()} which returns a bool: the
     * caller uses this value directly as the payload's `properties`, so a silently shortened list
     * would write a product that looks correct and is missing exactly the attribute the assistant
     * needed to compare it by. Both behaviours — the null and the collected misses — belong to
     * {@see OptionLookup}.
     *
     * @param ProductSpec                          $product
     * @param array<string, array<string, string>> $optionIds
     *
     * @return ?list<array{id: string}>
     */
    public static function properties(array $product, array $optionIds, UnresolvedReferences $unresolved): ?array
    {
        return OptionLookup::resolve($product['properties'], $optionIds, $unresolved, 'property');
    }
}
