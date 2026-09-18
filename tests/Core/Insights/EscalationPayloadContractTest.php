<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Insights;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Insights\ConversationTrace;
use Swag\AssistantStarterKit\Core\Insights\InsightsAggregator;
use Swag\AssistantStarterKit\Core\Policy\AssistantConfig;
use Swag\AssistantStarterKit\Core\Tool\EscalateTool;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * The `escalate` payload has two owners, and for one release they disagreed in silence.
 *
 * {@see EscalateTool} writes it; {@see \Swag\AssistantStarterKit\Core\Insights\Metric\TurnHealth}
 * reads it to decide whether the administration shows "Shoppers asked for a person and the assistant
 * had nowhere to send them". The reader looked for a key named `destination`, which the writer has
 * never emitted, so its `?? ''` fallback matched every escalation and the count was identical to
 * `escalations` on every shop in the world. A correctly configured demo shop was told in red to go
 * and configure the thing it had configured.
 *
 * **Both sides had tests and both were green.** The reader's tests built the payload themselves,
 * with the key the reader expected — so they described a shape production never produced. That is
 * the specific hole this file closes: nothing here invents a payload. The tool runs, records, and
 * what it recorded is what the aggregator is handed.
 */
final class EscalationPayloadContractTest extends TestCase
{
    public function testAConfiguredShopIsNotReportedAsMisconfigured(): void
    {
        self::assertSame(0, self::withoutDestinationFor(new AssistantConfig(escalationUrl: '/contact')));
    }

    public function testAShopWithNoDestinationIsReportedAsMisconfigured(): void
    {
        // The other direction matters just as much: a metric that never fires is as useless as one
        // that always does, and only running both through the real tool can tell them apart.
        self::assertSame(1, self::withoutDestinationFor(new AssistantConfig()));
    }

    /**
     * One escalation, recorded by the real tool, counted by the real aggregator.
     */
    private static function withoutDestinationFor(AssistantConfig $config): int
    {
        $recorder = new TraceRecorder();
        (new EscalateTool($recorder, $config))(reason: 'order status question');

        $payload = $recorder->payload('escalate');
        // Not ceremony for the analyzer: if the tool ever stops recording the stage at all, both
        // assertions below would still read sensibly from an empty payload — and "no escalation was
        // recorded" would quietly become "an escalation with no destination".
        self::assertIsArray($payload, 'EscalateTool recorded no `escalate` stage at all.');

        $trace = new ConversationTrace(
            'c1',
            new \DateTimeImmutable(),
            [['seq' => 1, 'stage' => 'escalate', 'payload' => $payload]],
            [],
        );

        return InsightsAggregator::aggregate([$trace])->turns->escalationsWithoutDestination;
    }
}
