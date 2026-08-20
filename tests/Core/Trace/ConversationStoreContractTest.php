<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Trace\ConversationStore;
use Swag\AssistantStarterKit\Core\Trace\ConversationTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * The contract every {@see ConversationStore} must satisfy.
 *
 * Written against the interface rather than an implementation on purpose: the controller's tests use
 * {@see InMemoryConversationStore}, and if that double were only *plausible* those tests would pass
 * against behaviour the Shopware store does not have. A subclass can point this at the DAL store
 * once an integration harness exists — the spec cut that for v0.
 */
final class ConversationStoreContractTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    private const BLUE_L_ID = 'a3a3a3a3a3a3a3a3a3a3a3a3a3a3a3a3';

    protected function store(): ConversationStore
    {
        return new InMemoryConversationStore();
    }

    public function testAConversationRoundTripsSoTheWidgetCanRehydrateAfterAPageLoad(): void
    {
        $store = $this->store();
        $token = $store->start(self::CHANNEL, 'en-GB');

        $store->append(
            $token,
            new ConversationTurn(role: ConversationTurn::ROLE_USER, prose: 'show me the trail jersey in blue, size L'),
            new TraceRecorder(),
        );
        $store->append(
            $token,
            new ConversationTurn(
                role: ConversationTurn::ROLE_ASSISTANT,
                prose: 'The Trail Jersey in Blue / L is available.',
                cardIds: [self::BLUE_L_ID],
                outcome: 'product_shown',
            ),
            new TraceRecorder(),
        );

        $history = $store->history($token);

        // Oldest first: the widget replays them in order, and "add that to my cart" only resolves
        // if the assistant turn carrying the card id is still there and still last.
        self::assertCount(2, $history);

        $shopper = $history[0] ?? null;
        $assistant = $history[1] ?? null;
        self::assertNotNull($shopper);
        self::assertNotNull($assistant);

        self::assertSame(ConversationTurn::ROLE_USER, $shopper->role);
        self::assertSame(ConversationTurn::ROLE_ASSISTANT, $assistant->role);
        self::assertSame([self::BLUE_L_ID], $assistant->cardIds);
        self::assertSame('product_shown', $assistant->outcome);
    }

    public function testAnUnknownTokenYieldsAnEmptyHistoryRatherThanAnError(): void
    {
        // A shopper with a stale sessionStorage token must get a fresh conversation, not a 500 on
        // page load — and a widget that breaks the page it is embedded in is worse than no widget.
        self::assertSame([], $this->store()->history('deadbeefdeadbeefdeadbeefdeadbeef'));
    }

    public function testEveryTraceEventOfATurnIsPersistedInSequenceOrder(): void
    {
        $store = $this->store();
        $token = $store->start(self::CHANNEL, 'en-GB');

        $trace = new TraceRecorder();
        $trace->record('guard.check', ['verdict' => 'allow']);
        $trace->record('retrieve', ['hits' => 3]);
        $trace->record('render', ['stockSource' => 'variant']);

        $store->append($token, new ConversationTurn(role: ConversationTurn::ROLE_ASSISTANT, prose: 'ok'), $trace);

        // A6: every turn produces a persisted trace with all pipeline stages. A store keeping only
        // the last event per stage would satisfy the letter and lose the turn.
        self::assertSame(
            ['guard.check', 'retrieve', 'render'],
            array_map(static fn($event): string => $event->stage, $store->traceEvents($token)),
        );
    }

    public function testAStageThatRanTwiceIsPersistedTwice(): void
    {
        // TraceRecorder::stages() de-duplicates (ruling R18); events() does not. A store built on
        // stages() would drop the second tool round, which is exactly where the tool-call budget
        // failures live — and the budget being exhausted was a live pilot blocker (R52).
        $store = $this->store();
        $token = $store->start(self::CHANNEL, 'en-GB');

        $trace = new TraceRecorder();
        $trace->record('tool.call', ['name' => 'search_products']);
        $trace->record('tool.call', ['name' => 'get_product']);

        $store->append($token, new ConversationTurn(role: ConversationTurn::ROLE_ASSISTANT, prose: 'ok'), $trace);

        self::assertCount(2, $store->traceEvents($token));
    }

    public function testTwoTurnsTracesAccumulateRatherThanReplacingEachOther(): void
    {
        // The multi-turn defect ruling R42 found in the eval harness, in its persistence form: if
        // turn 2 overwrote turn 1's events, an invention on turn 1 would be unreadable afterwards.
        $store = $this->store();
        $token = $store->start(self::CHANNEL, 'en-GB');

        $first = new TraceRecorder();
        $first->record('validate', ['inventedProductIds' => ['fx-999']]);
        $store->append($token, new ConversationTurn(role: ConversationTurn::ROLE_ASSISTANT, prose: 'one'), $first);

        $second = new TraceRecorder();
        $second->record('validate', ['inventedProductIds' => []]);
        $store->append($token, new ConversationTurn(role: ConversationTurn::ROLE_ASSISTANT, prose: 'two'), $second);

        self::assertCount(2, $store->traceEvents($token));
    }

    public function testTwoConversationsDoNotSeeEachOthersHistory(): void
    {
        $store = $this->store();
        $first = $store->start(self::CHANNEL, 'en-GB');
        $second = $store->start(self::CHANNEL, 'en-GB');

        $store->append(
            $first,
            new ConversationTurn(role: ConversationTurn::ROLE_USER, prose: 'mine'),
            new TraceRecorder(),
        );

        self::assertNotSame($first, $second);
        self::assertCount(1, $store->history($first));
        self::assertSame([], $store->history($second));
    }

    public function testHistoryIsBoundedAndKeepsTheMostRecentTurns(): void
    {
        // The context window is bounded, so history has to be too — and it must drop the OLDEST,
        // since "add that to my cart" refers to the newest card set.
        $store = $this->store();
        $token = $store->start(self::CHANNEL, 'en-GB');

        foreach (['one', 'two', 'three'] as $text) {
            $store->append(
                $token,
                new ConversationTurn(role: ConversationTurn::ROLE_USER, prose: $text),
                new TraceRecorder(),
            );
        }

        $history = $store->history($token, 2);

        self::assertCount(2, $history);
        self::assertSame(['two', 'three'], array_map(static fn($turn): string => $turn->prose, $history));
    }

    public function testTwoTurnsEventsDoNotCollideOnTheSameSequenceNumber(): void
    {
        // TraceRecorder restarts `seq` at 0 for every turn, because it is built fresh per turn — but
        // a store ordered by `seq` within a CONVERSATION then interleaves turns. Measured in the real
        // shop: one conversation had two different events both at seq 13, from different turns, and
        // reading the trace in order was impossible. A store must make `seq` monotonic per
        // conversation.
        $store = $this->store();
        $token = $store->start(self::CHANNEL, 'en-GB');

        $first = new TraceRecorder();
        $first->record('guard.check', ['verdict' => 'allow']);
        $first->record('turn.end', ['outcome' => 'product_shown']);
        $store->append($token, new ConversationTurn(role: ConversationTurn::ROLE_ASSISTANT, prose: 'one'), $first);

        $second = new TraceRecorder();
        $second->record('guard.check', ['verdict' => 'allow']);
        $second->record('turn.end', ['outcome' => 'cart_added']);
        $store->append($token, new ConversationTurn(role: ConversationTurn::ROLE_ASSISTANT, prose: 'two'), $second);

        $sequences = array_map(static fn($event): int => $event->seq, $store->traceEvents($token));

        self::assertCount(4, $sequences);
        self::assertSame($sequences, array_unique($sequences), 'sequence numbers collided across turns');
    }
}
