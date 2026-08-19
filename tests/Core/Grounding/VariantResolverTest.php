<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Grounding;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductQuery;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Commerce\Dto\VariantSelection;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\VariantResolver;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Cards under test come from `search()`, not `product()`: `search()` returns one
 * sellable unit per variant (Ruling R22), so a search for "Trail Jersey" hands the
 * resolver three cards — fx-026-blue-l, fx-026-black-m, fx-026-blue-m — that all
 * share `parentId === 'fx-026'`. A synthetic single "parent card carrying
 * aggregate stock 15" never occurs in the real pipeline and was the wrong input
 * shape to test against.
 */
final class VariantResolverTest extends TestCase
{
    private function gateway(): FixtureCommerceGateway
    {
        return FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
    }

    /** @return list<ProductCard> */
    private function trailJerseySearchResults(FixtureCommerceGateway $gateway): array
    {
        return $gateway->search(new ProductQuery(term: 'Trail Jersey'), new CatalogScope());
    }

    public function testNarrowsSearchResultsToTheSelectedVariant(): void
    {
        $gateway = $this->gateway();
        $trace = new TraceRecorder();

        $cards = $this->trailJerseySearchResults($gateway);
        self::assertCount(3, $cards, 'sanity check: the search must hand over all three sellable units of the family');

        $resolved = (new VariantResolver($gateway, $trace))->resolve($cards, [
            new VariantSelection('Blue'),
            new VariantSelection('M'),
        ]);

        // De-duplication assertion (Ruling R23): all three input cards share
        // parentId 'fx-026', so resolving the same selections against each of
        // them yields the same variant three times over. Without de-duplication
        // by resulting id, the shopper would see fx-026-blue-m listed three
        // times; exactly one must survive.
        self::assertCount(1, $resolved);

        $card = $resolved[0];
        self::assertNotNull($card);
        self::assertSame('fx-026-blue-m', $card->id);
        self::assertSame(0, $card->stock);
        self::assertSame(StockSource::Variant, $card->stockSource);
        self::assertSame(49.90, $card->price);
    }

    public function testKeepsTheOriginalCardsWhenTheSelectionIsAmbiguous(): void
    {
        $gateway = $this->gateway();
        $trace = new TraceRecorder();

        $cards = $this->trailJerseySearchResults($gateway);

        $resolved = (new VariantResolver($gateway, $trace))->resolve($cards, [new VariantSelection('Blue')]);

        self::assertCount(
            3,
            $resolved,
            'Blue alone matches both M and L — resolveVariant returns null for every card, so nothing is fabricated or dropped',
        );

        $resolvedIds = array_map(static fn(ProductCard $card): string => $card->id, $resolved);
        $resolvedSources = array_map(static fn(ProductCard $card): StockSource => $card->stockSource, $resolved);

        $inputIds = array_map(static fn(ProductCard $card): string => $card->id, $cards);
        $inputSources = array_map(static fn(ProductCard $card): StockSource => $card->stockSource, $cards);

        self::assertSame($inputIds, $resolvedIds);
        self::assertSame($inputSources, $resolvedSources);
    }

    public function testRecordsEachResolutionAttemptInTheTrace(): void
    {
        $gateway = $this->gateway();
        $trace = new TraceRecorder();

        $cards = $this->trailJerseySearchResults($gateway);

        (new VariantResolver($gateway, $trace))->resolve($cards, [
            new VariantSelection('Black'),
            new VariantSelection('M'),
        ]);

        $payload = $trace->payload('variant.resolve');
        self::assertNotNull($payload);

        $attempts = $payload['attempts'] ?? null;
        self::assertIsArray($attempts);

        $first = $attempts[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('fx-026', $first['parentId'] ?? null);
        self::assertSame('fx-026-black-m', $first['variantId'] ?? null);
        self::assertTrue($first['priceRefetched'] ?? null);
    }

    public function testLeavesNonVariantCardsUntouched(): void
    {
        $gateway = $this->gateway();
        $trace = new TraceRecorder();

        $simple = $gateway->product('fx-017');
        self::assertNotNull($simple);

        $resolved = (new VariantResolver($gateway, $trace))->resolve([$simple], [new VariantSelection('Blue')]);

        $card = $resolved[0];
        self::assertNotNull($card);
        self::assertSame('fx-017', $card->id);
    }
}
