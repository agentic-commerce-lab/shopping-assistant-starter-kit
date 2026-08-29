<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Trace\ConversationTurn;
use Swag\AssistantStarterKit\Core\Trace\DalConversationStore;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * Pins a review finding on Task 5: {@see \Swag\AssistantStarterKit\Core\Trace\ConversationScope::of()}
 * used to build the row's scope with `ShoppingMode::from()`, which throws `\ValueError` on a
 * `scope_type` no case recognises — turning a shopper-facing read into a 500. `ShoppingMode`'s own
 * docblock says a `commercial` case arrives with the Commercial bridge, so a row written by a newer
 * version and read by an older one is a real case, not a hypothetical one this test invents.
 *
 * Split from {@see ConversationScopeTest} rather than added to it: that class already sits at mago's
 * `too-many-methods` threshold (10) after Task 5's own extractions, and one more test there would trip
 * it again.
 */
final class ConversationUnrecognisedScopeTest extends TestCase
{
    use GuestShoppingContextFixture;

    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    public function testAnUnrecognisedScopeTypeYieldsNoHistoryRatherThanAnException(): void
    {
        $conversationRows = [];
        $eventRows = [];

        $store = new DalConversationStore(
            FakeConversationRepositories::conversations($conversationRows),
            FakeConversationRepositories::events($eventRows),
        );

        $token = $store->start($this->guest(), 'en-GB');
        $store->append(
            $token,
            $this->guest(),
            new ConversationTurn(role: ConversationTurn::ROLE_USER, prose: 'hi'),
            new TraceRecorder(),
        );

        // Simulates a row written by a future version under a scope this build's `ShoppingMode` has
        // no case for yet.
        $conversationRows[$token]['scopeType'] = 'commercial';

        self::assertSame([], $store->history($token, $this->guest()));
    }
}
