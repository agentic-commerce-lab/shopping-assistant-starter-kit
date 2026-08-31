<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command\Seed\Bike;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\Seed\Bike\BikeCatalogue;

/**
 * The descriptive properties, checked across all eighty-five products.
 *
 * Its own class rather than two more methods on {@see BikeCatalogueContentTest}: walking every
 * product's every group's every value is three nested loops, which put that class over mago's
 * per-class complexity budget. The split is the right one anyway — that file asks whether a product
 * is *writable*, this one asks whether it is *comparable*, and only the second question is what the
 * assistant's answers depend on.
 */
final class BikeCataloguePropertiesTest extends TestCase
{
    /**
     * Every product carries descriptive properties, and every one of them is declared.
     *
     * **Why this is required rather than optional.** The assistant compares products by the
     * `properties` {@see \Swag\AssistantStarterKit\Core\Tool\ToolProductSummary} hands the model —
     * `description` is deliberately withheld there. A seeded product with no properties is therefore
     * a product the assistant can say nothing substantive about: measured 2026-08-31 against the demo
     * shop, *"what is the difference between long finger gloves and winter gloves"* had nothing to
     * answer from, because this catalogue set variant axes only. An empty map is the failure, so an
     * absent key cannot be the passing case.
     */
    public function testEveryProductCarriesDescriptivePropertiesTheCatalogueDeclares(): void
    {
        $groups = BikeCatalogue::descriptiveGroups();

        foreach (BikeCatalogue::products() as $product) {
            $properties = $product['properties'] ?? [];

            self::assertNotSame([], $properties, $product['number'] . ' carries no descriptive properties');

            foreach ($properties as $group => $values) {
                self::assertArrayHasKey($group, $groups, $product['number'] . ' uses an undeclared group');

                $declared = $groups[$group] ?? [];
                self::assertIsArray($declared);

                foreach ($values as $value) {
                    self::assertContains(
                        $value,
                        $declared,
                        \sprintf('%s uses "%s", which group "%s" does not offer', $product['number'], $value, $group),
                    );
                }
            }
        }
    }

    /**
     * No product may exceed what the model will actually be shown.
     *
     * {@see \Swag\AssistantStarterKit\Core\Tool\BoundedProperties} caps a single product at six
     * groups and four values per group, and it truncates silently. Seeding past the cap would put
     * data in the shop that the assistant provably never sees, which is worse than not seeding it:
     * a reviewer reading the catalogue would believe it was available.
     */
    public function testNoProductExceedsThePropertyCapTheToolShapeApplies(): void
    {
        foreach (BikeCatalogue::products() as $product) {
            $properties = $product['properties'] ?? [];

            self::assertLessThanOrEqual(6, \count($properties), $product['number'] . ' has more groups than the cap');

            foreach ($properties as $group => $values) {
                self::assertLessThanOrEqual(
                    4,
                    \count($values),
                    \sprintf('%s has more than four values in "%s"', $product['number'], $group),
                );
            }
        }
    }

    /**
     * A material that is true of an entire product type is not an attribute, it is noise.
     *
     * **Measured against a live answer.** Asked for a helmet, the assistant told the shopper three of
     * five candidates were "made of polystyrene" — true, identical across all three, and worthless for
     * choosing. Every cycling helmet is EPS; every tyre and tube is rubber. The model named it because
     * it was in the data, and the reply read as a datasheet instead of advice.
     *
     * The rule is narrower than "must differ inside its category", which was the first attempt and was
     * wrong: `Material=Nylon` on all three jackets tells you nothing between jackets but a great deal
     * against a merino base layer. What disqualifies a value is being **trivially true of the whole
     * product type**, so it cannot discriminate anywhere.
     *
     * `Plastic` deliberately stays. It is weak, but `bk-mount-out-front` is Alloy where
     * `bk-mount-phone` is Plastic — there it does the work this test is about.
     */
    public function testTheMaterialGroupOffersNoValueThatIsTrueOfAWholeProductType(): void
    {
        $materials = BikeCatalogue::descriptiveGroups()['Material'] ?? [];
        self::assertIsArray($materials);

        self::assertNotContains('Polystyrene', $materials, 'every cycling helmet is EPS');
        self::assertNotContains('Rubber', $materials, 'every tyre and tube is rubber');
    }
}
