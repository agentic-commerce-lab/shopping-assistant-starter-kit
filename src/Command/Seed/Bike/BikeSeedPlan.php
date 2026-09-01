<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Command\Seed\Bike;

use Shopware\Core\Content\Product\Aggregate\ProductVisibility\ProductVisibilityDefinition;
use Swag\AssistantStarterKit\Command\Seed\SizeFamily;

/**
 * Turns {@see BikeCatalogue} plus the shop's own {@see ShopTaxonomy} into the three DAL payloads
 * {@see \Swag\AssistantStarterKit\Command\Seed\SeedWriter} writes.
 *
 * **Every unresolved reference is collected and thrown once, before anything is written.** This is
 * the same posture `ProductPlan::build()` takes with category paths, generalised to the three kinds
 * this seeder resolves — categories, property options and manufacturers — because this seeder writes
 * into a shop it did not create. A category renamed since the catalogue was written, or a
 * manufacturer deleted, would otherwise produce a payload with a null id: the DAL accepts that in
 * some positions and silently drops it in others, so the failure would surface as a product nobody
 * can find rather than as an error.
 *
 * **It extends the shop rather than duplicating it.** A property group the shop already has is not
 * re-created; only the option values it is missing are appended to it by id. A group it does not
 * have is created whole. Getting this wrong is not cosmetic: `CatalogVocabulary` puts the shop's
 * facet values in front of the model, so a second `Colour` group would teach the assistant two
 * spellings for one thing and split every colour search in half.
 *
 * @phpstan-import-type ProductSpec from BikeProducts
 */
final readonly class BikeSeedPlan
{
    /**
     * @param list<array<string, mixed>>              $categories
     * @param list<array<string, mixed>>              $propertyGroups
     * @param list<array<string, mixed>>              $products
     * @param list<array{id: string, properties: list<array{id: string}>}> $enrichments properties for
     *        products the shop already had — see {@see BikeCatalogue::existingProductProperties()}
     */
    private function __construct(
        public array $categories,
        public array $propertyGroups,
        public array $products,
        public array $enrichments,
    ) {}

    /**
     * @param bool $strict when false, unresolved references are left out instead of throwing — used
     *                     only by the test that exercises the "group the shop does not have" branch
     *                     in isolation
     */
    public static function build(
        ShopTaxonomy $shop,
        string $salesChannelId,
        bool $strict = true,
        BikeSeedMode $mode = BikeSeedMode::Seed,
    ): self {
        $unresolved = new UnresolvedReferences();
        $ctx = BikeSeedContext::resolve($shop, $salesChannelId);

        $products = [];
        foreach (BikeCatalogue::products() as $product) {
            $built = self::product($product, $ctx, $unresolved, $mode);

            if ($built !== null) {
                $products[] = $built;
            }
        }

        if ($strict && !$unresolved->isEmpty()) {
            throw new \RuntimeException(\sprintf(
                'The bike seed plan could not resolve %d reference(s) against this shop — no write '
                . 'has started. Either the catalogue names something this shop does not have, or the '
                . 'shop was changed since it was written: %s',
                $unresolved->count(),
                $unresolved->describe(),
            ));
        }

        return new self(
            BikeTaxonomyPlan::categories($shop),
            BikeTaxonomyPlan::propertyGroups($shop),
            $products,
            BikeEnrichmentPlan::build($ctx, $unresolved),
        );
    }

    /**
     * @param ProductSpec $product
     *
     * @return ?array<string, mixed>
     */
    private static function product(
        array $product,
        BikeSeedContext $ctx,
        UnresolvedReferences $unresolved,
        BikeSeedMode $mode,
    ): ?array {
        $resolved = ProductReferences::resolveAll($product, $ctx, $unresolved);

        if ($resolved === null) {
            return null;
        }

        $id = BikeSeedIds::product($product['number']);

        $payload = [
            'id' => $id,
            'productNumber' => $product['number'],
            'name' => $product['name'],
            'description' => $product['description'],
            'price' => SizeFamily::grossPrice($product['price'], $ctx->shop->tax->rate),
            'taxId' => $ctx->shop->tax->id,
            'manufacturerId' => $resolved->manufacturerId,
            'active' => true,
            'stock' => $product['stock'],
            'categories' => [['id' => $resolved->categoryId]],
            // What the assistant compares products by — see BikeCatalogue::descriptiveGroups() for
            // why a product without these is a product it can say nothing substantive about.
            'properties' => $resolved->properties,
        ];

        // Everything above is addressed by an id this seeder derives, so writing it twice is an update.
        // Everything below is not — see BikeSeedMode::writesFirstSeedStructures() for the two live
        // constraint violations that established the line, and for what `--update` therefore cannot do.
        if (!$mode->writesFirstSeedStructures()) {
            return $payload;
        }

        $payload['visibilities'] = [[
            'salesChannelId' => $ctx->salesChannelId,
            'visibility' => ProductVisibilityDefinition::VISIBILITY_ALL,
        ]];

        $axes = $product['variants'] ?? [];

        if ($axes === []) {
            return $payload;
        }

        $family = BikeVariantFamily::build(
            $id,
            $product['number'],
            SizeFamily::grossPrice($product['price'], $ctx->shop->tax->rate),
            $product['stock'],
            $axes,
            $ctx->optionIds,
        );
        $payload['children'] = $family['children'];
        $payload['configuratorSettings'] = $family['configuratorSettings'];

        return $payload;
    }
}
