<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Trace\ConversationStore;
use Swag\AssistantStarterKit\Core\Trace\ConversationTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * The two fields a stored turn gained, so a re-hydrated conversation does not lose what it knew.
 *
 * Split from {@see ConversationStoreContractTest} (too-many-methods) rather than suppressed, and the
 * seam is the right one anyway: those assertions are about conversations and traces, these are about
 * one turn's own record of itself surviving a write and a read.
 */
final class ConversationTurnFieldsContractTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    private const BLUE_L_ID = 'a3a3a3a3a3a3a3a3a3a3a3a3a3a3a3a3';

    protected function store(): ConversationStore
    {
        return new InMemoryConversationStore();
    }

    public function testATurnKeepsItsTimestampAndWarningsAcrossAReadBack(): void
    {
        // Both fields exist for the same reason: the widget once displayed *now* for a four-minute-old
        // message, and restored "the Trail Jersey is available in Blue, size M" with no correction
        // beside the sold-out card.
        $store = $this->store();
        $token = $store->start(self::CHANNEL, 'en-GB');
        $written = new \DateTimeImmutable('2026-08-20T09:41:07+00:00');

        $store->append(
            $token,
            new ConversationTurn(
                role: ConversationTurn::ROLE_ASSISTANT,
                prose: 'Yes, the Trail Jersey is available in Blue, size M.',
                cardIds: [self::BLUE_L_ID],
                outcome: 'product_shown',
                createdAt: $written,
                warnings: ['unbackedAvailabilityClaims' => ['is available'], 'unbackedPrices' => []],
            ),
            new TraceRecorder(),
        );

        $turn = $this->onlyTurn($store->history($token, 20));

        self::assertSame($written->format(\DATE_ATOM), $turn->createdAt?->format(\DATE_ATOM));
        self::assertSame(['is available'], $turn->warnings['unbackedAvailabilityClaims'] ?? []);
        // An empty list is dropped rather than stored as an empty key.
        self::assertArrayNotHasKey('unbackedPrices', $turn->warnings);
    }

    public function testATurnStoredWithoutEitherFieldReadsBackEmpty(): void
    {
        // Rows written before these fields existed must not break a read. A shopper holding an older
        // token gets a message with no time — never an exception, and never a fabricated time.
        $store = $this->store();
        $token = $store->start(self::CHANNEL, 'en-GB');

        $store->append(
            $token,
            new ConversationTurn(role: ConversationTurn::ROLE_USER, prose: 'show me the trail jersey'),
            new TraceRecorder(),
        );

        $turn = $this->onlyTurn($store->history($token, 20));

        self::assertNull($turn->createdAt);
        self::assertSame([], $turn->warnings);
    }

    /**
     * @param list<ConversationTurn> $history
     */
    private function onlyTurn(array $history): ConversationTurn
    {
        $turn = $history[0] ?? null;

        // `self::fail()` returns `never`, so this narrows for the analyzer as well as asserting for
        // the reader.
        if (\count($history) !== 1 || !$turn instanceof ConversationTurn) {
            self::fail('the conversation should hold exactly one turn');
        }

        return $turn;
    }
}
