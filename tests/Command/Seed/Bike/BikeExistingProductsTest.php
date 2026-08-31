<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed\Bike;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\Bike\BikeCatalogue;
use Swag\AssistantStarterKit\Command\Seed\Bike\BikeSeedPlan;

/**
 * Descriptive properties for products the shop already had.
 *
 * **Why the seeder reaches beyond its own catalogue.** It seeded 85 products with properties and left
 * the shop's own 16 `sk-*` products with none, which made the catalogue two-class in a way that shows
 * up directly in answers: asked for a helmet, the assistant described three `bk-*` helmets richly and
 * two `sk-*` ones as "available in White". Worse, a filter on `Terrain=Trail` would have dropped
 * `sk-101 Trail Helmet` — the single most trail-specific helmet in the shop — because it had no
 * attributes at all.
 *
 * **`fx-*` is deliberately excluded.** Those mirror `tests/Fixtures/catalog.json` verbatim, including
 * the prompt-injection description on `fx-017` and the mis-categorisation of `fx-021`
 * (`docs/demo-catalog/README.md`). They are test material; enriching them would destroy what they
 * test.
 */
final class BikeExistingProductsTest extends TestCase
{
    public function testEveryEnrichedProductUsesOnlyDeclaredGroupsAndValues(): void
    {
        $groups = BikeCatalogue::descriptiveGroups();
        $offenders = [];

        foreach (self::flattened() as [$number, $group, $value]) {
            if (!\in_array($value, $groups[$group] ?? [], true)) {
                $offenders[] = \sprintf('%s: %s = %s', $number, $group, $value);
            }
        }

        self::assertSame([], $offenders, "Undeclared property values:\n" . implode("\n", $offenders));
    }

    /**
     * The seeder must not touch the trap fixtures, and it must not touch its own products here either
     * — those carry their properties in `BikeProducts` and a second source would let the two disagree.
     */
    public function testItEnrichesOnlyTheShopsOwnProductsAndNoTrapsOrSeededOnes(): void
    {
        foreach (array_keys(BikeCatalogue::existingProductProperties()) as $number) {
            self::assertStringStartsWith('sk-', (string) $number, 'only the shop\'s own sk-* products');
        }
    }

    /**
     * `sk-101` is the reason this exists, so it is pinned by name: it must come out carrying the trail
     * terrain its description has always described and its data never did.
     */
    public function testTheTrailHelmetGetsTheTrailTerrainItAlwaysDeserved(): void
    {
        $trail = BikeCatalogue::existingProductProperties()['sk-101'] ?? [];

        self::assertContains('Trail', $trail['Terrain'] ?? []);
    }

    /**
     * The enrichments reach the plan as upsertable payloads — an id the shop already assigned, plus
     * the properties, and nothing else.
     *
     * **Nothing else is the point.** These products belong to the shop, not to this seeder. Sending a
     * name, a price or a category would overwrite a merchant's own data with the seeder's idea of it;
     * sending only `properties` adds what was missing and touches nothing that was there.
     */
    public function testEnrichmentsBecomeMinimalUpsertPayloads(): void
    {
        $plan = BikeSeedPlan::build(FakeShopTaxonomy::complete(), FakeShopTaxonomy::SALES_CHANNEL_ID);

        $byId = [];
        foreach ($plan->enrichments as $payload) {
            $byId[$payload['id']] = $payload;
        }

        self::assertCount(\count(BikeCatalogue::existingProductProperties()), $byId);

        $trail = $byId[FakeShopTaxonomy::productId('sk-101')] ?? null;
        self::assertIsArray($trail);
        self::assertSame(['id', 'properties'], array_keys($trail));
        self::assertNotSame([], $trail['properties']);
    }

    /**
     * A product the shop does not have is skipped rather than refused.
     *
     * This is the one place the seeder must *not* treat an unresolved reference as fatal, and the
     * asymmetry is deliberate. Everywhere else an unknown name means the catalogue was written against
     * a different shop and seeding it would corrupt this one. Here it means the merchant deleted a
     * product the enrichment list still mentions — their prerogative, and no reason to refuse to seed
     * the other eighty-five.
     */
    public function testAProductTheShopNoLongerHasIsSkippedRatherThanRefused(): void
    {
        $plan = BikeSeedPlan::build(FakeShopTaxonomy::withoutExistingProducts(), FakeShopTaxonomy::SALES_CHANNEL_ID);

        self::assertSame([], $plan->enrichments);
        self::assertNotSame([], $plan->products);
    }

    /**
     * Every (product, group, value) triple, flat.
     *
     * Flattened in a helper rather than nested in the assertion: three loops around an `in_array` put
     * the test class over mago's nesting budget, and the flat form reads as what it checks.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function flattened(): array
    {
        $triples = [];

        foreach (BikeCatalogue::existingProductProperties() as $number => $properties) {
            foreach ($properties as $group => $values) {
                $triples = [
                    ...$triples,
                    ...array_map(static fn(string $value): array => [
                        (string) $number,
                        (string) $group,
                        $value,
                    ], $values),
                ];
            }
        }

        return $triples;
    }
}
