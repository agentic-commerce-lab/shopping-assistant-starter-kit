<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\GroundingOutputProcessor;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Output;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;

final class GroundingOutputProcessorTest extends TestCase
{
    /**
     * @param list<string> $registerIds ids retrieved in a single tool-call batch
     *
     * @return array{0: FactRenderer, 1: TraceRecorder}
     */
    private function process(string $prose, array $registerIds): array
    {
        return $this->processBatches($prose, [$registerIds]);
    }

    /**
     * @param list<list<string>> $batches each inner list is registered via its own
     *                                     registerRetrieved() call, mirroring one tool
     *                                     call's survivors — the LAST inner list is what
     *                                     lastRetrievedBatch() will return
     *
     * @return array{0: FactRenderer, 1: TraceRecorder}
     */
    private function processBatches(string $prose, array $batches): array
    {
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
        $trace = new TraceRecorder();
        $renderer = new FactRenderer($trace);

        foreach ($batches as $batchIds) {
            $cards = [];
            foreach ($batchIds as $id) {
                $product = $gateway->product($id, new CatalogScope());
                self::assertNotNull($product);
                $cards[] = $product;
            }
            $renderer->registerRetrieved($cards);
        }

        $output = new Output('gpt-x', new TextResult($prose), new MessageBag());
        (new GroundingOutputProcessor($renderer, $trace))->processOutput($output);

        return [$renderer, $trace];
    }

    public function testDropsAnIdTheModelInvented(): void
    {
        [$renderer, $trace] = $this->process('Try fx-017 or fx-999.', ['fx-017']);

        self::assertSame(
            ['fx-017'],
            array_map(static fn(ProductCard $card): string => $card->id, $renderer->renderedCards()),
        );

        $payload = $trace->payload('validate');
        self::assertNotNull($payload);
        self::assertSame(['fx-999'], $payload['inventedProductIds']);
    }

    public function testRendersThePriceFromTheRecordNotTheProse(): void
    {
        [$renderer] = $this->process('fx-017 costs about twenty euros.', ['fx-017']);

        $card = $renderer->renderedCards()[0] ?? null;
        self::assertNotNull($card);
        self::assertSame(12.90, $card->price);
    }

    public function testFlagsACurrencyFigureNoCardBacks(): void
    {
        [$renderer] = $this->process('fx-017 is just EUR 1.29 today!', ['fx-017']);

        self::assertContains('1.29', $renderer->unbackedPrices());
    }

    public function testSkipsAndRecordsWhenTheResultIsNotText(): void
    {
        $trace = new TraceRecorder();
        $renderer = new FactRenderer($trace);

        $output = new Output(
            'gpt-x',
            new ToolCallResult([new ToolCall('call-1', 'search_products', [])]),
            new MessageBag(),
        );
        (new GroundingOutputProcessor($renderer, $trace))->processOutput($output);

        self::assertSame(['skipped' => 'non-text result'], $trace->payload('render'));
        self::assertSame([], $renderer->renderedCards());
    }

    /**
     * The live-run regression: a natural reply that names no product id at all must
     * still render a card, sourced from the last tool batch — not from scraping prose
     * that was never designed to carry a card selector.
     */
    public function testProseNamingNoIdRendersTheLastToolBatch(): void
    {
        [$renderer, $trace] = $this->process('The Alloy Bottle Cage is a good match for your bike.', ['fx-017']);

        self::assertSame(
            ['fx-017'],
            array_map(static fn(ProductCard $card): string => $card->id, $renderer->renderedCards()),
        );

        $payload = $trace->payload('grounding.select');
        self::assertNotNull($payload);
        self::assertSame('last_tool_batch', $payload['source']);
        self::assertSame(['fx-017'], $payload['selectedIds']);
    }

    public function testProseNamingOneValidIdOutOfABatchOfThreeRendersOnlyThatId(): void
    {
        [$renderer, $trace] = $this->process('I would go with fx-007 here.', ['fx-017', 'fx-007', 'fx-008']);

        self::assertSame(
            ['fx-007'],
            array_map(static fn(ProductCard $card): string => $card->id, $renderer->renderedCards()),
        );

        $payload = $trace->payload('grounding.select');
        self::assertNotNull($payload);
        self::assertSame('prose', $payload['source']);
        self::assertSame(['fx-007'], $payload['selectedIds']);
    }

    public function testProseNamingOnlyAnInventedIdRecordsItAndRendersTheLastBatch(): void
    {
        [$renderer, $trace] = $this->process('I found fx-999 for you.', ['fx-017']);

        $validatePayload = $trace->payload('validate');
        self::assertNotNull($validatePayload);
        self::assertSame(['fx-999'], $validatePayload['inventedProductIds']);

        $renderedIds = array_map(static fn(ProductCard $card): string => $card->id, $renderer->renderedCards());
        self::assertSame(['fx-017'], $renderedIds);
        self::assertNotContains('fx-999', $renderedIds);

        $selectPayload = $trace->payload('grounding.select');
        self::assertNotNull($selectPayload);
        self::assertSame('last_tool_batch', $selectPayload['source']);
    }

    public function testProseNamingAValidIdFromAnEarlierBatchRendersThatIdNotTheLastBatch(): void
    {
        [$renderer, $trace] = $this->processBatches('fx-017 is still my recommendation.', [
            ['fx-017'],
            ['fx-007', 'fx-008'],
        ]);

        self::assertSame(
            ['fx-017'],
            array_map(static fn(ProductCard $card): string => $card->id, $renderer->renderedCards()),
        );

        $payload = $trace->payload('grounding.select');
        self::assertNotNull($payload);
        self::assertSame('prose', $payload['source']);
        self::assertSame(['fx-017'], $payload['selectedIds']);
    }
}
