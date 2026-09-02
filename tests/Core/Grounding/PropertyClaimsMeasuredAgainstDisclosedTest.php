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
 * An option value the shop **told** the model about is the shop's claim, not the model's.
 *
 * ## The fourth source, and why it was missing
 *
 * {@see PropertyClaimsMeasuredAgainstRetrievedTest} moved this audit's yardstick from rendered cards
 * to retrieved ones on 2026-09-01, for a measured reason: a search returns several variants, the model
 * correctly says "in sizes S, M, L and XL", one card renders, and the shopper was told the card
 * overrode the sentence.
 *
 * The same argument has a step this project had not taken. Retrieval is no longer the only way the
 * shop hands the model option values. Two others exist and both are deliberate:
 *
 * - `search_products` returns a `families` block for every family narrowing truncated — see
 *   {@see \Swag\AssistantStarterKit\Core\Tool\TruncatedFamilies}, built precisely so a 30-variant
 *   family is describable when only five of its members fit the window.
 * - the viewing line names the open product's whole family — see
 *   {@see \Swag\AssistantStarterKit\Core\Prompt\ViewingContext}, so *"what sizes is this in?"* is
 *   answerable without a tool call.
 *
 * Neither reached the audit, so the values they disclose were flagged as inventions.
 *
 * ## What that cost, measured on the live shop 2026-09-02
 *
 * Asked *"what sizes is this available in?"* on the size-L variant of *A-Line Bag 3317*, the assistant
 * answered `XS, S, M, L, XL` — exactly right — and the reply carried
 * `unbackedPropertyClaims: ['M', 'XL', 'XS']`, which `render.js` turns into a note telling the shopper
 * the card below is the one that applies. The card shows one size.
 *
 * Worse than a false positive, it was **arbitrary**. The same true sentence reached by a plain search
 * flagged only `['XL']`, because that search happened to return four *other* A-Line Bags carrying
 * `S`, `L`, `XS` and `M`, and the flat backed-value set accepted them as cover. Whether the shopper saw
 * a correction depended on which unrelated products the search returned.
 *
 * ## The limit this does not lift
 *
 * Backing is a flat set of values with no notion of which product a claim was about — see
 * {@see \Swag\AssistantStarterKit\Core\Grounding\BackedPropertyValues}. So the coincidence above is
 * only half gone: `XL` is now backed for the right reason, and `S` would still be backed by an
 * unrelated bag if no disclosure had been made. Attributing each claim to a product is a much larger
 * change than this, and is deliberately not attempted here.
 */
final class PropertyClaimsMeasuredAgainstDisclosedTest extends TestCase
{
    public function testASizeTheShopDisclosedIsNotFlagged(): void
    {
        // The live defect, as one call: the shopper is on the L variant, the shop put the family's
        // whole size run in the prompt, and the model read it back correctly.
        $renderer = $this->renderer();

        $renderer->registerRetrieved([$this->bag('L')]);
        $renderer->render([$this->bag('L')->id]);

        $flagged = $renderer->unbackedPropertiesInProse(
            'The A-Line Bag 3317 comes in XS, S, M, L and XL.',
            $this->facets(),
            disclosedOptions: ['XS', 'S', 'M', 'L', 'XL'],
        );

        self::assertSame([], $flagged);
    }

    public function testTheInventionCaseIsUnchanged(): void
    {
        // What the audit exists for, and the reason this is an exemption rather than a switch: a value
        // in no retrieved card AND in no disclosure is still the model's own claim.
        $renderer = $this->renderer();

        $renderer->registerRetrieved([$this->bag('L')]);
        $renderer->render([$this->bag('L')->id]);

        $flagged = $renderer->unbackedPropertiesInProse(
            'The A-Line Bag 3317 also comes in Olive.',
            $this->facets(),
            disclosedOptions: ['XS', 'S', 'M', 'L', 'XL'],
        );

        self::assertSame(['Olive'], $flagged);
    }

    public function testDisclosingNothingLeavesTheAuditExactlyAsItWas(): void
    {
        // The default has to be the old behaviour, or every caller that does not disclose becomes a
        // silent change in what gets flagged.
        $renderer = $this->renderer();

        $renderer->registerRetrieved([$this->bag('L')]);
        $renderer->render([$this->bag('L')->id]);

        $flagged = $renderer->unbackedPropertiesInProse('The A-Line Bag 3317 comes in XL.', $this->facets());

        self::assertSame(['XL'], $flagged);
    }

    public function testTheExemptionIsCaseInsensitiveLikeEveryOtherBackedValue(): void
    {
        // `BackedPropertyValues` lowercases its keys so a claim matches a card's value regardless of
        // casing. A disclosure joining that same set must not be the one source that needs the
        // catalogue's exact spelling to work.
        $renderer = $this->renderer();

        $renderer->registerRetrieved([$this->bag('L')]);

        $flagged = $renderer->unbackedPropertiesInProse(
            'It also comes in Olive.',
            $this->facets(),
            disclosedOptions: ['olive'],
        );

        self::assertSame([], $flagged);
    }

    private function renderer(): FactRenderer
    {
        return new FactRenderer(new TraceRecorder());
    }

    private function facets(): FacetSet
    {
        return new FacetSet([
            new Facet('properties.Size', FacetType::Terms, ['XS', 'S', 'M', 'L', 'XL']),
            new Facet('properties.Colour', FacetType::Terms, ['Olive']),
        ]);
    }

    private function bag(string $size): ProductCard
    {
        return new ProductCard(
            id: str_pad(strtolower($size), 32, 'a'),
            parentId: 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1',
            name: 'A-Line Bag 3317',
            description: null,
            price: 39.9,
            currency: 'EUR',
            stock: 4,
            stockSource: StockSource::Variant,
            deliveryTime: null,
            url: '/p/a-line-bag-3317',
            imageUrl: null,
            options: ['Size' => $size],
        );
    }
}
