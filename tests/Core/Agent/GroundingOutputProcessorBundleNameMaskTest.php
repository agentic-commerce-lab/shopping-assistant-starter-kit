<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\GroundingOutputProcessor;
use Swag\AssistantStarterKit\Core\Commerce\Dto\BundleItem;
use Swag\AssistantStarterKit\Core\Commerce\Dto\Facet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetSet;
use Swag\AssistantStarterKit\Core\Commerce\Dto\FacetType;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Trace\TraceEvent;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Output;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;

/**
 * A bundle member whose **name contains a facet value** must not be read as a property claim.
 *
 * **Measured live on 2026-09-10, and it is a false positive this change itself introduced.** Asked
 * *"Show me your bundles"*, the assistant listed the Tubeless Conversion Kit's contents — *"Tubeless
 * Rim Tape, Valve Set, …"* — and `claims.audit` reported `unbackedPropertyClaims: ["Rim"]`, because
 * `Rim` is one of this catalogue's two `Brake system` values. The reply claimed nothing about brakes;
 * it named a product.
 *
 * {@see \Swag\AssistantStarterKit\Core\Grounding\ProductNameMask} exists for exactly this collision
 * and already strips the names of every **retrieved card** before the property audit reads the
 * prose. A bundle's members are not retrieved cards — only the bundle itself is — so their names
 * went unmasked, and every member name holding a facet value became a claim the shop could not
 * back. Once the server hands over a name, that name is the server's, and masking it is the same
 * rule applied to the same kind of thing.
 *
 * Ruling R85 again: this fires on the assistant answering a bundle question correctly, which with
 * bundles is the common case.
 */
final class GroundingOutputProcessorBundleNameMaskTest extends TestCase
{
    private static function facets(): FacetSet
    {
        return new FacetSet([
            new Facet('properties.Brake system', FacetType::Terms, ['Rim', 'Disc']),
            new Facet('properties.Material', FacetType::Terms, ['Steel', 'Alloy', 'Nylon']),
        ]);
    }

    private function bundle(): ProductCard
    {
        return new ProductCard(
            id: 'b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0',
            parentId: null,
            name: 'Tubeless Conversion Kit',
            description: 'Converts one tubeless-ready 700c wheelset to run without inner tubes.',
            price: 45.72,
            currency: 'EUR',
            stock: 0,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/detail/b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0',
            imageUrl: null,
            bundleItems: [
                new BundleItem('Tubeless Rim Tape 25mm', 1, true),
                new BundleItem('Tubeless Valve Set', 1, true),
            ],
        );
    }

    public function testAFacetValueInsideAMemberNameIsNotAPropertyClaim(): void
    {
        $trace = new TraceRecorder();

        $this->ground($trace, "The Tubeless Conversion Kit contains:\n- Tubeless Rim Tape 25mm\n- Tubeless Valve Set");

        self::assertSame([], $this->propertyFindings($trace));
    }

    /**
     * The counterpart, so the mask is not mistaken for a blanket exemption: a brake-system claim
     * the reply makes on its own account, about a card that does not carry it, is still reported.
     */
    public function testARealPropertyClaimBesideABundleIsStillReported(): void
    {
        $trace = new TraceRecorder();

        $this->ground($trace, 'The Tubeless Conversion Kit is for Disc brake wheels only.');

        self::assertSame([['unbackedPropertyClaims' => ['Disc']]], $this->propertyFindings($trace));
    }

    private function ground(TraceRecorder $trace, string $prose): void
    {
        $renderer = new FactRenderer($trace);
        $renderer->registerRetrieved([$this->bundle()]);

        (new GroundingOutputProcessor($renderer, $trace, self::facets()))->processOutput(
            new Output('gpt-x', new TextResult($prose), new MessageBag()),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function propertyFindings(TraceRecorder $trace): array
    {
        return array_values(array_map(
            static fn(TraceEvent $event): array => $event->payload,
            array_filter(
                $trace->events(),
                static fn(TraceEvent $event): bool => (
                    $event->stage === 'claims.audit'
                    && \array_key_exists('unbackedPropertyClaims', $event->payload)
                ),
            ),
        ));
    }
}
