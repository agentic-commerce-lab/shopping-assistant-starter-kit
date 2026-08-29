<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Context\ShoppingContext;
use Swag\AssistantStarterKit\Core\Context\ShoppingMode;
use Swag\AssistantStarterKit\Core\Trace\ConversationStore;
use Swag\AssistantStarterKit\Core\Trace\ConversationTurn;
use Swag\AssistantStarterKit\Core\Trace\DalConversationStore;
use Swag\AssistantStarterKit\Core\Trace\ForeignConversationException;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * The defect this whole plan exists to close: `DalConversationStore` used to look a conversation up
 * by primary key and hand its transcript to whoever presented the token, with no owner check at all.
 *
 * Exercised against the **real** `DalConversationStore`, not `InMemoryConversationStore` — the
 * in-memory double is updated to the same match rule in this task too, but it is the DAL store's own
 * row-to-scope mapping (see {@see \Swag\AssistantStarterKit\Core\Trace\ConversationScope}) that this
 * suite has to prove, against repository doubles standing in for the two tables it reads and writes.
 *
 * `history()` and `append()` disagree on what a mismatch does, deliberately: a shopper-facing read
 * returns `[]` and says nothing about why — the same answer an unknown token gets, so neither
 * discloses which case it was — while `append()` throws {@see ForeignConversationException}. Reaching
 * that throw means the controller's own validation was skipped; a silent no-op there would drop a
 * shopper's turn invisibly instead of failing loudly for a developer.
 */
final class ConversationScopeTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    private const ALICE = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';

    private const BOB = 'b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0';

    /** @var array<string, array<string, mixed>> conversation rows, keyed by id */
    private array $conversationRows = [];

    /** @var list<array<string, mixed>> trace event rows, in insertion order */
    private array $eventRows = [];

    private function guest(): ShoppingContext
    {
        return new ShoppingContext(ShoppingMode::Guest, self::CHANNEL);
    }

    private function customer(string $id): ShoppingContext
    {
        return new ShoppingContext(ShoppingMode::Customer, self::CHANNEL, $id);
    }

    public function testAConversationStartedByOneCustomerIsInvisibleToAnother(): void
    {
        // The defect this whole plan exists to close.
        $store = $this->store();
        $token = $store->start($this->customer(self::ALICE), 'en-GB');
        $store->append(
            $token,
            $this->customer(self::ALICE),
            new ConversationTurn(role: ConversationTurn::ROLE_USER, prose: 'the blue one'),
            new TraceRecorder(),
        );

        self::assertSame([], $store->history($token, $this->customer(self::BOB)));
    }

    public function testTheOwnerReadsTheirOwnTranscript(): void
    {
        // The other half: scoping that also hides a conversation from its owner is not a fix.
        $store = $this->store();
        $token = $store->start($this->customer(self::ALICE), 'en-GB');
        $store->append(
            $token,
            $this->customer(self::ALICE),
            new ConversationTurn(role: ConversationTurn::ROLE_USER, prose: 'the blue one'),
            new TraceRecorder(),
        );

        self::assertCount(1, $store->history($token, $this->customer(self::ALICE)));
    }

    public function testAGuestTokenIsInvisibleAfterLoggingIn(): void
    {
        $store = $this->store();
        $token = $store->start($this->guest(), 'en-GB');
        $store->append(
            $token,
            $this->guest(),
            new ConversationTurn(role: ConversationTurn::ROLE_USER, prose: 'anything'),
            new TraceRecorder(),
        );

        self::assertSame([], $store->history($token, $this->customer(self::ALICE)));
    }

    public function testACustomerTokenIsInvisibleAfterLoggingOut(): void
    {
        $store = $this->store();
        $token = $store->start($this->customer(self::ALICE), 'en-GB');
        $store->append(
            $token,
            $this->customer(self::ALICE),
            new ConversationTurn(role: ConversationTurn::ROLE_USER, prose: 'anything'),
            new TraceRecorder(),
        );

        self::assertSame([], $store->history($token, $this->guest()));
    }

    public function testADeletedCustomersConversationDoesNotBecomeAGuestConversation(): void
    {
        // Spec 9.2: `customer_id` is ON DELETE SET NULL, so after the customer goes the row has a
        // null customer — but `scope_type` still says `customer`, and a guest must not inherit it.
        $store = $this->store();
        $token = $store->start($this->customer(self::ALICE), 'en-GB');
        $store->append(
            $token,
            $this->customer(self::ALICE),
            new ConversationTurn(role: ConversationTurn::ROLE_USER, prose: 'anything'),
            new TraceRecorder(),
        );

        // Simulates what Shopware's `ON DELETE SET NULL` foreign key does to a real row when the
        // customer it pointed at is deleted: `customerId` goes null, but `scopeType` still says
        // `customer`.
        $this->conversationRows[$token]['customerId'] = null;

        self::assertSame([], $store->history($token, $this->guest()));
    }

    public function testAppendingToAForeignConversationThrows(): void
    {
        // A backstop, not a shopper-facing path: reaching it means the controller skipped its own
        // validation, and a silent no-op would drop a shopper's turn invisibly.
        $store = $this->store();
        $token = $store->start($this->customer(self::ALICE), 'en-GB');

        $this->expectException(ForeignConversationException::class);

        $store->append(
            $token,
            $this->customer(self::BOB),
            new ConversationTurn(role: ConversationTurn::ROLE_USER, prose: 'anything'),
            new TraceRecorder(),
        );
    }

    public function testTraceEventsIgnoreScopeSoTheMerchantExportKeepsWorking(): void
    {
        // Administrative access is ACL-controlled and deliberately not scope-checked. `traceEvents()`
        // takes no `ShoppingContext` at all — a `new TraceRecorder()` records nothing on its own, so
        // this needs one real event or the assertion below would pass for the wrong reason.
        $store = $this->store();
        $token = $store->start($this->customer(self::ALICE), 'en-GB');

        $trace = new TraceRecorder();
        $trace->record('turn.end', ['outcome' => 'product_shown']);
        $store->append(
            $token,
            $this->customer(self::ALICE),
            new ConversationTurn(role: ConversationTurn::ROLE_USER, prose: 'anything'),
            $trace,
        );

        self::assertNotSame([], $store->traceEvents($token));
    }

    /**
     * The real `DalConversationStore`, over the two repository doubles {@see FakeConversationRepositories}
     * builds — see that class's own docblock for why constructing them is not done inline here.
     */
    private function store(): ConversationStore
    {
        $this->conversationRows = [];
        $this->eventRows = [];

        return new DalConversationStore(
            FakeConversationRepositories::conversations($this->conversationRows),
            FakeConversationRepositories::events($this->eventRows),
        );
    }
}
