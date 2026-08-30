<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Context\ShoppingContext;
use Swag\AssistantStarterKit\Core\Context\ShoppingMode;
use Swag\AssistantStarterKit\Core\Trace\ConversationStore;
use Swag\AssistantStarterKit\Core\Trace\ConversationTurn;
use Swag\AssistantStarterKit\Core\Trace\DalConversationStore;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * The one case from {@see ConversationStoreContractTest} that has to run against **both**
 * implementations, not only the in-memory double: whether a foreign scope sees no history.
 *
 * Split into its own file rather than added as an eleventh method there: that file was already at
 * ten, and mago's `too-many-methods` budget fires at eleven — but the split is the right seam anyway,
 * because this test needs a harness ({@see FakeConversationRepositories}, built for
 * {@see ConversationScopeTest}) the rest of that file does not.
 *
 * **Why both implementations, specifically.** `DalConversationStore` compares scopes through
 * {@see \Swag\AssistantStarterKit\Core\Trace\ConversationScope::matches()};
 * `InMemoryConversationStore` compares them through {@see FakeConversationScope::matches()} — an
 * independently written copy of the same rule, kept separate only because the double has no row to
 * rebuild a {@see ShoppingContext} from. Every scope case before this one lived solely in
 * {@see ConversationScopeTest}, which only ever runs against the real store. That left a hole: someone
 * could simplify `FakeConversationScope::matches()` to `$stored !== null` and every test in this
 * repository would still pass — including {@see \Swag\AssistantStarterKit\Tests\Controller\AssistantControllerTest::testAForeignTokenGetsAFreshConversationInsteadOfThrowing()},
 * which is built entirely on `InMemoryConversationStore` and would then be asserting nothing about
 * scoping at all. Running the same assertion against both implementations in one test pins the two
 * rules to each other, so a rule that drifts on either side fails here first.
 */
final class ConversationStoreForeignScopeContractTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    private const ALICE = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';

    private const BOB = 'b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0';

    public function testAForeignScopeSeesNoHistory(): void
    {
        foreach ($this->implementations() as $label => $store) {
            $token = $store->start($this->customer(self::ALICE), 'en-GB');
            $store->append(
                $token,
                $this->customer(self::ALICE),
                new ConversationTurn(role: ConversationTurn::ROLE_USER, prose: 'the blue one'),
                new TraceRecorder(),
            );

            self::assertSame(
                [],
                $store->history($token, $this->customer(self::BOB)),
                \sprintf("%s: a foreign scope must not see another shopper's history", $label),
            );
        }
    }

    /**
     * @return iterable<string, ConversationStore>
     */
    private function implementations(): iterable
    {
        yield 'in-memory' => new InMemoryConversationStore();

        // Fresh row arrays per call, not shared across implementations or across test runs: the
        // state for `DalConversationStore` lives entirely in these doubles, and reusing rows would
        // mean the second implementation was not being tested independently of the first.
        $conversationRows = [];
        $eventRows = [];
        yield 'dal' => new DalConversationStore(
            FakeConversationRepositories::conversations($conversationRows),
            FakeConversationRepositories::events($eventRows),
        );
    }

    private function customer(string $id): ShoppingContext
    {
        return new ShoppingContext(ShoppingMode::Customer, self::CHANNEL, $id);
    }
}
