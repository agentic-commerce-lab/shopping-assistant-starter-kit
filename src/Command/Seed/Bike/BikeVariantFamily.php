<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed\Bike;

use Swag\AssistantStarterKit\Command\Seed\SeedId;
use Swag\AssistantStarterKit\Command\Seed\SizeFamily;

/**
 * Expands a product's variant axes into the sellable units they describe.
 *
 * **Why not {@see SizeFamily}.** That walks exactly one axis, because every sized garment in the
 * fashion catalogue varies by size and by nothing else. A bicycle shop does not: a club jersey comes
 * in five sizes AND three colours — fifteen units — while a rotor comes in three diameters and one
 * colour. Reusing `SizeFamily` would have forced either one axis per product, or colour pretending
 * to be a size, and the second of those would have taught the assistant's `CatalogVocabulary` that
 * "Black" is a size.
 *
 * `SizeFamily::grossPrice()` is still shared: the price shape is the same one every payload in this
 * feature carries, and it belongs written once.
 */
final class BikeVariantFamily
{
    private function __construct() {}

    /**
     * @param array<string, list<string>>          $axes      group name => the values this product offers
     * @param array<string, array<string, string>> $optionIds group name => value => property option id
     *
     * @return array{children: list<array<string, mixed>>, configuratorSettings: list<array<string, mixed>>}
     */
    // @mago-expect lint:excessive-parameter-list
    // Five facts about one product plus the option table to resolve against, and no two of them
    // belong together: the id and number identify the parent, the price and stock are its own, the
    // axes are the catalogue's, and the option ids are the shop's. Grouping any of them would invent
    // an object whose only purpose was this signature — and the fashion side's `SizeFamily::build()`
    // takes the same five for the same reason.
    public static function build(
        string $parentId,
        string $parentNumber,
        float $price,
        int $stock,
        array $axes,
        array $optionIds,
    ): array {
        $children = [];
        $used = [];
        $offset = 0;

        foreach (self::combinations($axes) as $combination) {
            $options = [];
            $suffix = [];

            foreach ($combination as $group => $value) {
                $optionId = $optionIds[$group][$value] ?? null;

                // Unreachable for a catalogue BikeCatalogueTest passes — every axis value is checked
                // against the declared groups there — so this is the assertion that keeps that test
                // load-bearing rather than a silently skipped option on a live write.
                \assert(\is_string($optionId), description: \sprintf('No option id for %s = %s', $group, $value));

                $options[] = ['id' => $optionId];
                $used[$optionId] = true;
                $suffix[] = self::slug($value);
            }

            $number = $parentNumber . '-' . implode('-', $suffix);

            $children[] = [
                'id' => SeedId::forPath('bike-variant', $parentId . '/' . $number),
                'productNumber' => $number,
                'price' => SizeFamily::grossPrice($price),
                // A family the catalogue put out of stock stays out of stock in every unit: that is
                // the case ruling R75 exists for, and spreading "a little stock everywhere" would
                // make it untestable. Otherwise the spread is deterministic and uneven, so a shopper
                // sees a realistic mix rather than the same number on every size.
                'stock' => $stock === 0 ? 0 : 1 + (($stock + ($offset * 3)) % $stock),
                'options' => $options,
            ];

            ++$offset;
        }

        $configuratorSettings = [];
        foreach (array_keys($used) as $optionId) {
            $configuratorSettings[] = ['optionId' => $optionId];
        }

        return ['children' => $children, 'configuratorSettings' => $configuratorSettings];
    }

    /**
     * Every combination of one value per axis, in declaration order.
     *
     * Iterative rather than recursive: the axis count is data from {@see BikeCatalogue}, and a plain
     * fold is easier to read than a recursion whose base case is an empty product.
     *
     * @param array<string, list<string>> $axes
     *
     * @return list<array<string, string>>
     */
    private static function combinations(array $axes): array
    {
        $combinations = [[]];

        foreach ($axes as $group => $values) {
            $next = [];

            foreach ($combinations as $partial) {
                foreach ($values as $value) {
                    $next[] = [...$partial, $group => $value];
                }
            }

            $combinations = $next;
        }

        return $combinations;
    }

    /**
     * A product-number-safe form of an option value: `700x32` stays itself, `180 mm` becomes
     * `180-mm`, `Black` becomes `black`.
     */
    private static function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;

        return trim($slug, '-');
    }
}
