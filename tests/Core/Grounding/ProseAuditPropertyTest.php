<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\Facet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Grounding\ProseAudit;

final class ProseAuditPropertyTest extends TestCase
{
    private function facets(): FacetSet
    {
        // `properties.` prefix, matching the real convention PropertyClaimExtractor now requires
        // (Task 5) — both FixtureFacetBuilder and DalFacetReader namespace true property-group
        // facets this way.
        return new FacetSet([
            new Facet('properties.Material', FacetType::Terms, ['Merino', 'Nylon']),
            new Facet('properties.Colour', FacetType::Terms, ['Blue']),
        ]);
    }

    /**
     * @param array<string, list<string>> $properties
     * @param array<string, string>       $options
     */
    private function card(array $properties, array $options = []): ProductCard
    {
        return new ProductCard(
            id: 'fx-001',
            parentId: null,
            name: 'Trail Jersey',
            description: null,
            price: 54.90,
            currency: 'EUR',
            stock: 5,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/p/fx-001',
            imageUrl: null,
            options: $options,
            properties: $properties,
        );
    }

    public function testAClaimTheCardBacksIsNotFlagged(): void
    {
        $unbacked = (new ProseAudit())->unbackedProperties(
            'It is made of Merino.',
            [$this->card(['Material' => ['Merino']])],
            '',
            $this->facets(),
        );

        self::assertSame([], $unbacked);
    }

    public function testAClaimNoRenderedCardBacksIsFlagged(): void
    {
        $unbacked = (new ProseAudit())->unbackedProperties(
            'It is made of Nylon.',
            [$this->card(['Material' => ['Merino']])],
            '',
            $this->facets(),
        );

        self::assertSame(['Nylon'], $unbacked);
    }

    public function testAValueTheShopperIntroducedIsNotFlagged(): void
    {
        // Ruling R85's exemption, applied to attribute claims: restating the shopper's own words is
        // not a claim by the model.
        $unbacked = (new ProseAudit())->unbackedProperties(
            'I searched for Nylon items as you asked, but found none in stock.',
            [$this->card(['Material' => ['Merino']])],
            'do you have anything in Nylon?',
            $this->facets(),
        );

        self::assertSame([], $unbacked);
    }

    public function testARealOptionValueIsNotFlagged(): void
    {
        // "Blue" lives in this card's `options` (a variant selection), not its `properties` — but a
        // real shop's facet layer merges both under the same properties.* namespace (Task 6's
        // finding, DalFacetReader::GROUPED_AGGREGATIONS), so PropertyClaimExtractor can extract it as
        // a candidate claim. Correctly restating a real option value must never be flagged.
        $unbacked = (new ProseAudit())->unbackedProperties(
            'It comes in Blue.',
            [$this->card(properties: [], options: ['Colour' => 'Blue'])],
            '',
            $this->facets(),
        );

        self::assertSame([], $unbacked);
    }
}
