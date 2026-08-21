<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\GroundingOutputProcessor;
use Swag\AssistantStarterKit\Core\Commerce\Dto\ProductCard;
use Swag\AssistantStarterKit\Core\Commerce\Dto\StockSource;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Output;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;

/**
 * One turn must produce one grounding decision, and the trace must say so once.
 *
 * **The defect, read off a live trace of one turn with three tool calls:**
 *
 * ```
 * 17  validate / 18  grounding.select / 19  render    16274ms
 * 20  validate / 21  grounding.select / 22  render    16274ms
 * 23  validate / 24  grounding.select / 25  render    16274ms
 * 26  validate / 27  grounding.select / 28  render    16275ms
 * ```
 *
 * Identical payloads, the same millisecond, four times over — nine of that turn's thirty rows were
 * repetition, in the one view a merchant reads to understand what happened.
 *
 * The cause is in the framework, not here. `Toolbox\AgentProcessor::handleToolCallsCallback()`
 * resolves a tool round by calling `Agent::call()` **recursively**, and every nested call runs the
 * whole output-processor chain again. This processor is registered after the toolbox's, so at each
 * of the four nesting levels it is handed the same already-final text and records the same decision.
 *
 * Nothing was corrupted by it: the audits assign rather than append, so the last write won and the
 * shopper saw the right thing. It cost wasted prose audits and a trace nobody could read.
 */
final class GroundingOutputProcessorOncePerTurnTest extends TestCase
{
    private const CARD_ID = 'a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2';

    private static function card(): ProductCard
    {
        return new ProductCard(
            id: self::CARD_ID,
            parentId: null,
            name: 'Trail Jersey',
            description: null,
            price: 74.9,
            currency: 'EUR',
            stock: 3,
            stockSource: StockSource::Product,
            deliveryTime: null,
            url: '/detail/' . self::CARD_ID,
            imageUrl: null,
        );
    }

    private static function resultFor(string $prose): Output
    {
        return new Output('test-model', new TextResult($prose), new MessageBag());
    }

    /** @return list<string> */
    private static function stages(TraceRecorder $trace): array
    {
        return array_map(static fn($event): string => $event->stage, $trace->events());
    }

    public function testTheSameFinalResultIsGroundedOnceHoweverOftenTheFrameworkOffersIt(): void
    {
        $trace = new TraceRecorder();
        $renderer = new FactRenderer($trace);
        $renderer->registerRetrieved([self::card()]);

        $processor = new GroundingOutputProcessor($renderer, $trace);
        $output = self::resultFor('I found the Trail Jersey in Blue, size M.');

        // Four nesting levels of the tool loop, every one handed the same final text.
        $processor->processOutput($output);
        $processor->processOutput($output);
        $processor->processOutput($output);
        $processor->processOutput($output);

        $stages = self::stages($trace);

        self::assertSame(1, \count(array_filter($stages, static fn($s): bool => 'grounding.select' === $s)));
        self::assertSame(1, \count(array_filter($stages, static fn($s): bool => 'render' === $s)));
        self::assertSame(1, \count(array_filter($stages, static fn($s): bool => 'validate' === $s)));
    }

    /** The decision itself is unchanged — skipping repeats must not skip the work. */
    public function testTheCardIsStillRendered(): void
    {
        $trace = new TraceRecorder();
        $renderer = new FactRenderer($trace);
        $renderer->registerRetrieved([self::card()]);

        (new GroundingOutputProcessor($renderer, $trace))->processOutput(self::resultFor('I found the Trail Jersey.'));

        self::assertSame(
            [self::CARD_ID],
            array_map(static fn(ProductCard $card): string => $card->id, $renderer->renderedCards()),
        );
    }

    /**
     * The guard is per turn, not per object.
     *
     * Production builds a fresh bundle per HTTP request, so this only matters to the eval harness,
     * which drives several turns through one bundle. The guard keys on
     * {@see FactRenderer::turnSequence()} rather than on a reset the caller has to remember, so the
     * harness needs no special case — advancing the turn is something the runner already does.
     */
    public function testANewTurnGroundsAgain(): void
    {
        $trace = new TraceRecorder();
        $renderer = new FactRenderer($trace);
        $renderer->registerRetrieved([self::card()]);

        $processor = new GroundingOutputProcessor($renderer, $trace);

        $renderer->registerShopperMessage('first question');
        $processor->processOutput(self::resultFor('First turn.'));

        // What the runner does at the start of every turn — no reset call, no plumbing.
        $renderer->registerShopperMessage('second question');
        $processor->processOutput(self::resultFor('Second turn.'));

        self::assertSame(
            2,
            \count(array_filter(self::stages($trace), static fn($s): bool => 'grounding.select' === $s)),
        );
    }
}
