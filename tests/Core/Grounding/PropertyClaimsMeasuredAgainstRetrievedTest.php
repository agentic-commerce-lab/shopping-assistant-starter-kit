<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\Facet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * An attribute the model was *shown* is not an invention, even when the rendered card does not carry it.
 *
 * **Measured against the live shop, 2026-09-01.** Eight of eight property warnings in the trace log
 * were colours and sizes — `["Blue"]`, `["M","XL"]`, `["Olive"]`, `["Black","Red"]` — never a material.
 * The pattern: a search returns several variants of one family, the model correctly says "in both Black
 * and Blue" or "in sizes S, M, L and XL", and the shopper is then warned that the card below is
 * authoritative. The prose was right; the card simply showed one variant.
 *
 * It surfaced when card selection was narrowed to the products the prose names (one card per product
 * instead of the whole tool batch), which removed the accidental coverage that a long card list used to
 * provide.
 *
 * **So the measure moves from rendered to retrieved.** The audit asks "did the model state something it
 * was never given?", and the honest yardstick for that is what the tools handed it. Rendering is a
 * presentation decision made afterwards and cannot make a true statement false.
 *
 * What this does not weaken: a value in **no** retrieved card at all is still flagged, which is the
 * invention the audit exists for.
 */
final class PropertyClaimsMeasuredAgainstRetrievedTest extends TestCase
{
    public function testAnOptionFromAnotherRetrievedVariantIsNotFlagged(): void
    {
        $renderer = $this->renderer();

        // Both variants were retrieved; only the Black one is rendered, as the prose named the product
        // once and card selection now yields one card per product.
        $renderer->registerRetrieved([$this->glove('Black'), $this->glove('Blue')]);
        $renderer->render([$this->glove('Black')->id]);

        $flagged = $renderer->unbackedPropertiesInProse(
            'The Long Finger Gloves come in both Black and Blue.',
            $this->facets(),
        );

        self::assertSame([], $flagged);
    }

    /**
     * The invention case, unchanged: a colour no retrieved card carried stays flagged.
     */
    public function testAColourNoRetrievedCardCarriesIsStillFlagged(): void
    {
        $renderer = $this->renderer();

        $renderer->registerRetrieved([$this->glove('Black')]);
        $renderer->render([$this->glove('Black')->id]);

        $flagged = $renderer->unbackedPropertiesInProse('The Long Finger Gloves come in Red.', $this->facets());

        self::assertSame(['Red'], $flagged);
    }

    private function renderer(): FactRenderer
    {
        return new FactRenderer(new TraceRecorder());
    }

    private function facets(): FacetSet
    {
        return new FacetSet([
            new Facet('properties.Colour', FacetType::Terms, ['Black', 'Blue', 'Red']),
        ]);
    }

    private function glove(string $colour): ProductCard
    {
        return new ProductCard(
            id: str_pad(strtolower($colour), 32, 'a'),
            parentId: null,
            name: 'Long Finger Gloves',
            description: 'A light full-finger glove.',
            price: 29.9,
            currency: 'EUR',
            stock: 4,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/p/gloves',
            imageUrl: null,
            options: ['Colour' => $colour, 'Size' => 'M'],
        );
    }
}
