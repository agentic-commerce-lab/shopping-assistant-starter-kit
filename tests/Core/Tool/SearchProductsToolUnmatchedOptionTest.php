<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Tool;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Grounding\VariantResolver;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Policy\BlocklistFilter;
use Swag\AssistantStarterKit\Core\Retrieval\FacetProbe;
use Swag\AssistantStarterKit\Core\Retrieval\QueryBuilder;
use Swag\AssistantStarterKit\Core\Retrieval\UnmatchedOptionRetry;
use Swag\AssistantStarterKit\Core\Tool\NoMatchOrientation;
use Swag\AssistantStarterKit\Core\Tool\SearchProductsTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * What happens when an option filter eliminates everything.
 *
 * *"Is that bottle cage available in blue?"* used to return nothing at all: `fx-017` has no
 * `Colour` property, `Blue` is a real value elsewhere in the catalogue so the filter was
 * applied rather than dropped, and the only product matching the words was filtered out. The
 * shopper got silence, which `VISION.md` names as the one answer that cannot turn *"your AI is
 * bad"* into *"your products are missing attribute X"*.
 */
final class SearchProductsToolUnmatchedOptionTest extends TestCase
{
    private TraceRecorder $trace;

    /** @param non-empty-string $name */
    public function __construct(string $name)
    {
        parent::__construct($name);

        $this->trace = new TraceRecorder();
    }

    private function tool(): SearchProductsTool
    {
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
        $this->trace = new TraceRecorder();

        return new SearchProductsTool(
            $gateway,
            new FacetProbe($gateway, $this->trace),
            new QueryBuilder(),
            new VariantResolver($gateway, $this->trace),
            new BlocklistFilter(),
            new FactRenderer($this->trace),
            $this->trace,
            new AssistantConfig(),
        );
    }

    public function testAProductWithNoSuchOptionGroupIsReturnedWithANoteInsteadOfNothing(): void
    {
        $result = $this->tool()(term: 'bottle cage', options: [['Colour', 'Blue']]);

        self::assertSame(['fx-017'], self::ids($result));
        self::assertSame(UnmatchedOptionRetry::NOTE, $result['note'] ?? null);
    }

    /** The retry is a trace stage, not a silent second query. */
    public function testTheRetryIsRecordedInTheTrace(): void
    {
        $this->tool()(term: 'bottle cage', options: [['Colour', 'Blue']]);

        $events = array_values(array_filter(
            $this->trace->events(),
            static fn($event): bool => 'retrieve.without_options' === $event->stage,
        ));

        self::assertCount(1, $events);
        self::assertSame(['properties.Colour'], $events[0]->payload['droppedFields'] ?? null);
        self::assertSame(1, $events[0]->payload['hits'] ?? null);
    }

    /**
     * A price bound the shopper stated is not collateral damage.
     *
     * `fx-017` costs 12.90, so under a 5.00 ceiling the honest answer stays "nothing" — the
     * retry must remove the colour clause and only the colour clause.
     */
    public function testAPriceBoundSurvivesTheRetry(): void
    {
        $result = $this->tool()(term: 'bottle cage', priceMax: 5.0, options: [['Colour', 'Blue']]);

        self::assertSame([], self::ids($result));
        // The orientation note, not NO_MATCH_NOTE: this gateway can read its own tree, so the
        // empty reply carries the shop's departments and the note that refers to them. Both notes
        // forbid concluding the shop has none of a thing — asserted below — and NO_MATCH_NOTE is
        // still what a gateway without a tree reader gets.
        self::assertSame(NoMatchOrientation::NOTE, $result['note'] ?? null);
    }

    /**
     * The case that caught a false claim in this feature's own first draft.
     *
     * `Chartreuse` is not a value any product has, but `Colour` IS a group this catalogue has,
     * so the filter is applied rather than dropped and the retry fires exactly as it does for a
     * colourless product. The jersey family DOES record a colour — just not that one — so a
     * note asserting "the shop records no such option" would be false here. The note therefore
     * states the mismatch and points at each product's own listed options instead.
     */
    public function testAnUnknownValueInAKnownGroupAlsoRetriesAndTheNoteDoesNotClaimTheReason(): void
    {
        $result = $this->tool()(term: 'jersey', options: [['Colour', 'Chartreuse']]);

        self::assertNotSame([], self::ids($result));
        self::assertSame(UnmatchedOptionRetry::NOTE, $result['note'] ?? null);
        self::assertStringNotContainsString('records no such option for them', UnmatchedOptionRetry::NOTE);
        self::assertStringContainsString('each one lists the options', UnmatchedOptionRetry::NOTE);
    }

    /** A selection that DOES match must not trigger a retry — nothing was eliminated. */
    public function testAMatchingSelectionDoesNotRetry(): void
    {
        $result = $this->tool()(term: 'jersey', options: [['Colour', 'Blue'], ['Size', 'M']]);

        self::assertSame(['fx-026-blue-m'], self::ids($result));
        self::assertArrayNotHasKey('note', $result);
        self::assertSame(
            [],
            array_values(array_filter(
                $this->trace->events(),
                static fn($event): bool => 'retrieve.without_options' === $event->stage,
            )),
        );
    }

    /** No options at all means nothing to drop, so an empty result stays empty. */
    public function testAnEmptyResultWithNoOptionsIsUnchanged(): void
    {
        $result = $this->tool()(term: 'nonexistent gizmo');

        self::assertSame([], self::ids($result));
        // The orientation note, not NO_MATCH_NOTE: this gateway can read its own tree, so the
        // empty reply carries the shop's departments and the note that refers to them. Both notes
        // forbid concluding the shop has none of a thing — asserted below — and NO_MATCH_NOTE is
        // still what a gateway without a tree reader gets.
        self::assertSame(NoMatchOrientation::NOTE, $result['note'] ?? null);
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<string>
     */
    private static function ids(array $result): array
    {
        $products = $result['products'] ?? [];
        self::assertIsArray($products);

        $ids = [];
        foreach ($products as $product) {
            self::assertIsArray($product);
            $id = $product['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }
}
