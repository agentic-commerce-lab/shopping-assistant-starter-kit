<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\GroundingOutputProcessor;
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
     * @param list<string> $registerIds
     *
     * @return array{0: FactRenderer, 1: TraceRecorder}
     */
    private function process(string $prose, array $registerIds): array
    {
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
        $trace = new TraceRecorder();
        $renderer = new FactRenderer($trace);

        foreach ($registerIds as $id) {
            $product = $gateway->product($id);
            self::assertNotNull($product);
            $renderer->registerRetrieved([$product]);
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
}
