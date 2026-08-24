<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace\Export;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Trace\Export\TraceJsonSerialiser;
use Swag\AssistantStarterKit\Entity\Conversation\ConversationEntity;
use Swag\AssistantStarterKit\Entity\TraceEvent\TraceEventCollection;
use Swag\AssistantStarterKit\Entity\TraceEvent\TraceEventEntity;

final class TraceJsonSerialiserTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    public function testAPayloadSurvivesVerbatim(): void
    {
        // The whole point of this format: whoever receives it sees what the pipeline recorded, not
        // a summary of it. The reason anyone asks for a trace is a question nobody anticipated.
        $json = self::decode(TraceJsonSerialiser::serialise([self::conversation()], []));

        self::assertSame(
            ['reported' => true, 'resolved' => 'a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2'],
            $json[0]['events'][0]['payload'],
        );
    }

    public function testItCarriesTheSameSummaryTheCsvDoes(): void
    {
        // One derivation, two formats: a developer reading the JSON and a merchant reading the CSV
        // must not have to reconcile two different durations for the same conversation.
        $json = self::decode(TraceJsonSerialiser::serialise([self::conversation()], [self::CHANNEL => 'Storefront']));

        self::assertSame('Storefront', $json[0]['summary']['salesChannel']);
        self::assertSame('Guest user', $json[0]['summary']['user']);
    }

    public function testTheTranscriptIsIncluded(): void
    {
        $json = self::decode(TraceJsonSerialiser::serialise([self::conversation()], []));

        self::assertSame([['role' => 'user', 'prose' => 'is this in stock?']], $json[0]['transcript']);
    }

    public function testEventsComeOutInTheOrderTheyWereRecorded(): void
    {
        // `seq` is the record of what happened when. A set sorted by anything else describes a turn
        // the pipeline did not run.
        $conversation = self::conversation();
        $conversation->setEvents(new TraceEventCollection([
            self::event(2, 'render', 90),
            self::event(0, 'page.context', 16),
            self::event(1, 'prompt', 20),
        ]));

        $json = self::decode(TraceJsonSerialiser::serialise([$conversation], []));

        self::assertSame(['page.context', 'prompt', 'render'], array_column($json[0]['events'] ?? [], 'stage'));
    }

    public function testItIsPrettyPrintedBecausePeopleReadIt(): void
    {
        self::assertStringContainsString("\n    ", TraceJsonSerialiser::serialise([self::conversation()], []));
    }

    public function testAnEmptySelectionIsAnEmptyArrayRatherThanAnObject(): void
    {
        // `[]` and `{}` are different documents to every JSON reader downstream.
        self::assertSame('[]', TraceJsonSerialiser::serialise([], []));
    }

    /**
     * Narrowed rather than annotated: `json_decode` returns `mixed`, and a docblock that merely
     * claims otherwise leaves every nested access unchecked.
     *
     * @return list<array{summary: array<string, mixed>, transcript: mixed, events: list<array<string, mixed>>}>
     */
    private static function decode(string $json): array
    {
        $decoded = json_decode($json, associative: true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        /** @var list<array{summary: array<string, mixed>, transcript: mixed, events: list<array<string, mixed>>}> $decoded */
        return $decoded;
    }

    private static function event(int $seq, string $stage, int $elapsedMs): TraceEventEntity
    {
        $event = new TraceEventEntity();
        $event->setId(bin2hex(random_bytes(16)));
        $event->setSeq($seq);
        $event->setStage($stage);
        $event->setElapsedMs($elapsedMs);
        $event->setPayload([]);

        return $event;
    }

    private static function conversation(): ConversationEntity
    {
        $event = new TraceEventEntity();
        $event->setId('11111111111111111111111111111111');
        $event->setSeq(0);
        $event->setStage('page.context');
        $event->setElapsedMs(16);
        $event->setPayload(['reported' => true, 'resolved' => 'a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2']);

        $conversation = new ConversationEntity();
        $conversation->setId('01a0337f413070afa3b29711739324a2');
        $conversation->setSalesChannelId(self::CHANNEL);
        $conversation->setTurnCount(1);
        $conversation->setOutcome('product_shown');
        $conversation->setTotalMs(3656);
        $conversation->setCreatedAt(new \DateTimeImmutable('2026-08-24T10:12:04+00:00'));
        $conversation->setTranscript([['role' => 'user', 'prose' => 'is this in stock?']]);
        $conversation->setEvents(new TraceEventCollection([$event]));

        return $conversation;
    }
}
