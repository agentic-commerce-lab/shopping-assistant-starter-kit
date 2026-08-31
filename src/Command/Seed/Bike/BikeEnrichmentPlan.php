<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed\Bike;

/**
 * Properties for products the shop already had, as payloads that add and never overwrite.
 *
 * Its own class for the same reason {@see BikeTaxonomyPlan} is: {@see BikeSeedPlan} is about the
 * catalogue this seeder *creates*, and this is about the catalogue it *finds*. The two obey different
 * rules — most visibly, a missing reference is fatal there and skippable here — and mago reported the
 * combined class as too complex, which is that difference showing up as arithmetic.
 */
final class BikeEnrichmentPlan
{
    private function __construct() {}

    /**
     * Minimal upsert payloads for the products the shop already had: the id it assigned, plus the
     * properties it was missing.
     *
     * **Nothing else travels.** These products belong to the merchant, not to this seeder — sending a
     * name, a price or a category would overwrite their own data with the catalogue's idea of it.
     *
     * **A product the shop does not have is skipped, not refused**, and that asymmetry against every
     * other lookup in this seeder is deliberate. Elsewhere an unresolved name means the catalogue was
     * written against a different shop, and seeding would corrupt this one. Here it means the merchant
     * deleted a product this list still mentions — their prerogative, and no reason to refuse the other
     * eighty-five. An undeclared *property value* is still fatal, because that is the catalogue being
     * wrong rather than the shop having changed.
     *
     * @return list<array{id: string, properties: list<array{id: string}>}>
     */
    public static function build(BikeSeedContext $ctx, UnresolvedReferences $unresolved): array
    {
        $payloads = [];

        foreach (BikeCatalogue::existingProductProperties() as $number => $properties) {
            $id = $ctx->shop->productIdsByNumber[$number] ?? null;

            if (!\is_string($id)) {
                continue;
            }

            $resolved = OptionLookup::resolve($properties, $ctx->optionIds, $unresolved, 'property');

            if ($resolved === null || $resolved === []) {
                continue;
            }

            $payloads[] = ['id' => $id, 'properties' => $resolved];
        }

        return $payloads;
    }
}
