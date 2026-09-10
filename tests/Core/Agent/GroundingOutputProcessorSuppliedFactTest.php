<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\GroundingOutputProcessor;
use Swag\AssistantStarterKit\Core\Commerce\Dto\CatalogScope;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Core\Grounding\FactRenderer;
use Swag\AssistantStarterKit\Core\Trace\TraceEvent;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;
use Symfony\AI\Agent\Output;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;

/**
 * The open-vocabulary audit reaches `claims.audit` through the real processor.
 *
 * Its own file rather than another case in {@see GroundingOutputProcessorPropertyClaimTest}, for the
 * reason that file gives for its own existence: `too-many-methods` is linted, and splitting is the
 * repo's answer.
 *
 * Worth asserting end to end and not only on
 * {@see \Swag\AssistantStarterKit\Core\Grounding\DescriptionAudit} directly, because the wiring is
 * where this class of feature dies quietly — `logTraces` was a silent no-op in production once, and
 * a detector nothing calls is indistinguishable from a clean corpus.
 */
final class GroundingOutputProcessorSuppliedFactTest extends TestCase
{
    /**
     * No description was handed over this turn, so a reply claiming what is in the box has nothing
     * behind it at all — the shape six of the corpus's eight invented-fact replies had.
     */
    public function testAnUnsupportedDeliveryClaimReachesTheTrace(): void
    {
        $trace = new TraceRecorder();

        $this->ground($trace, 'It is supplied with a frame bracket, so no extra hardware is needed.');

        self::assertSame(
            [['unsupportedFactClaims' => ['supplied with a frame bracket'], 'descriptionsGiven' => 0]],
            $this->auditPayloads($trace),
        );
    }

    /**
     * The silent half, and the one that decides whether a merchant can read the stage at all: a turn
     * that claimed nothing must leave no `unsupportedFactClaims` behind, or the field stops meaning
     * anything.
     */
    public function testACleanReplyRecordsNothing(): void
    {
        $trace = new TraceRecorder();

        $this->ground($trace, 'The Trail Jersey is a long sleeve jersey for trail riding.');

        self::assertSame([], $this->auditPayloads($trace));
    }

    private function ground(TraceRecorder $trace, string $prose): void
    {
        $gateway = FixtureCommerceGateway::fromFile(__DIR__ . '/../../Fixtures/catalog.json');
        $renderer = new FactRenderer($trace);

        $product = $gateway->product('fx-017', new CatalogScope());

        if ($product === null) {
            self::fail('fixture fx-017 is missing');
        }

        $renderer->registerRetrieved([$product]);

        (new GroundingOutputProcessor($renderer, $trace))->processOutput(
            new Output('gpt-x', new TextResult($prose), new MessageBag()),
        );
    }

    /**
     * Only the payloads this audit writes. `claims.audit` is shared — unbacked prices and
     * availability claims land there too — so filtering on the field is what keeps the assertion
     * about this feature.
     *
     * @return list<array<string, mixed>>
     */
    private function auditPayloads(TraceRecorder $trace): array
    {
        return array_values(array_filter(
            array_map(
                static fn(TraceEvent $event): array => $event->payload,
                array_filter($trace->events(), static fn(TraceEvent $event): bool => $event->stage === 'claims.audit'),
            ),
            static fn(array $payload): bool => \array_key_exists('unsupportedFactClaims', $payload),
        ));
    }
}
