<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\GroundingOutputProcessor;
use Swag\AssistantStarterKit\Core\Commerce\Dto\BundleItem;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Trace\TraceEvent;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Output;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;

/**
 * A bundle's contents as **support**, so restating them is not an invention.
 *
 * The other half of the same change as {@see \Swag\AssistantStarterKit\Tests\Core\Grounding\DescriptionAuditListClaimTest}.
 * Once the extractor reads a contents list, every bundle answer becomes a scope-of-delivery claim —
 * and a shop that supplied the contents must not then have the assistant's correct answer reported
 * as unsupported. That is the failure ruling R85 warns about: a control that fires on right
 * behaviour teaches everyone to ignore it, and a bundle answered correctly is the common case.
 *
 * The contents are read off the retrieved card rather than a trace stage of their own, because the
 * card is what the summary was built from: {@see \Swag\AssistantStarterKit\Core\Tool\ToolProductSummary::of()}
 * emits the `bundle` key for exactly the cards registered here, so "supplied to the model" and
 * "on a retrieved card" are the same set.
 *
 * Asserted through the real processor for the reason its sibling gives: wiring is where this class
 * of feature dies quietly.
 */
final class GroundingOutputProcessorBundleContentsTest extends TestCase
{
    private const BUNDLE_ID = 'b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0';

    public function testRestatingTheContentsTheCardCarriesIsNotReported(): void
    {
        $trace = new TraceRecorder();

        $this->ground($trace, "The Roadside Repair Kit includes:\n- Mini Pump 120psi\n- Tyre Lever Set");

        self::assertSame([], $this->auditPayloads($trace));
    }

    public function testAnItemTheBundleDoesNotContainIsStillReported(): void
    {
        // The point of supplying the contents is that the audit can now tell the two apart. A
        // "Gear brush" is not in this kit and no product in the catalogue is called one.
        $trace = new TraceRecorder();

        $this->ground($trace, "The Roadside Repair Kit includes:\n- Mini Pump 120psi\n- Gear brush");

        self::assertSame(
            [['unsupportedFactClaims' => ['Gear brush'], 'descriptionsGiven' => 1]],
            $this->auditPayloads($trace),
        );
    }

    private function bundle(): ProductCard
    {
        return new ProductCard(
            id: self::BUNDLE_ID,
            parentId: null,
            name: 'Roadside Repair Kit',
            // Deliberately says nothing about the contents — the realistic case, because Shopware
            // renders the item list from `bundle_item` and a merchant has no reason to repeat it.
            description: 'Everything needed to fix a puncture at the roadside instead of walking home.',
            price: 73.08,
            currency: 'EUR',
            stock: 19,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/detail/' . self::BUNDLE_ID,
            imageUrl: null,
            bundleItems: [
                new BundleItem('Mini Pump 120psi', 1, true),
                new BundleItem('Tyre Lever Set', 1, true),
            ],
        );
    }

    private function ground(TraceRecorder $trace, string $prose): void
    {
        $renderer = new FactRenderer($trace);
        $renderer->registerRetrieved([$this->bundle()]);

        (new GroundingOutputProcessor($renderer, $trace))->processOutput(
            new Output('gpt-x', new TextResult($prose), new MessageBag()),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditPayloads(TraceRecorder $trace): array
    {
        return array_values(array_map(
            static fn(TraceEvent $event): array => $event->payload,
            array_filter(
                $trace->events(),
                static fn(TraceEvent $event): bool => (
                    $event->stage === 'claims.audit'
                    && \array_key_exists('unsupportedFactClaims', $event->payload)
                ),
            ),
        ));
    }
}
