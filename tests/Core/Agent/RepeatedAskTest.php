<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\RepeatedAsk;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

/**
 * The signal every one of the review's eight worst sessions shared: the shopper said it again.
 *
 * Recorded per turn so a merchant can query for it instead of reading 104 replies, which is how
 * those eight were actually found.
 */
#[CoversClass(RepeatedAsk::class)]
final class RepeatedAskTest extends TestCase
{
    private function history(string ...$asks): MessageBag
    {
        $bag = new MessageBag();

        foreach ($asks as $ask) {
            $bag->add(Message::ofUser($ask));
            $bag->add(Message::ofAssistant('Some reply.'));
        }

        return $bag;
    }

    public function testAVerbatimRetypeIsARepeat(): void
    {
        $found = RepeatedAsk::in('show me helmets in size M', $this->history('show me helmets in size M'));

        self::assertSame(1, $found['repeatedTurn'] ?? null);
        self::assertSame(1.0, $found['overlap'] ?? null);
    }

    /**
     * The common case is not a retype. Measured: &ldquo;show me helmets&rdquo; then &ldquo;which
     * helmets do you have&rdquo;, after the first answer showed three of sixteen.
     */
    public function testARephrasingOfTheSameAskIsARepeat(): void
    {
        $found = RepeatedAsk::in('which helmets do you have', $this->history('show me the helmets'));

        self::assertNotNull($found);
        self::assertSame(1, $found['repeatedTurn']);
    }

    public function testAnOrdinaryFollowUpIsNotARepeat(): void
    {
        self::assertNull(RepeatedAsk::in('and gloves?', $this->history('show me helmets in size M')));
    }

    public function testANewTopicIsNotARepeat(): void
    {
        self::assertNull(RepeatedAsk::in('what is your returns policy', $this->history('show me helmets')));
    }

    public function testTheFirstMessageOfAConversationIsNeverARepeat(): void
    {
        self::assertNull(RepeatedAsk::in('do you sell bikes?', new MessageBag()));
    }

    /**
     * It reports the closest earlier ask, not merely the first one that passed &mdash; the turn number
     * is the thing a merchant clicks through to.
     */
    public function testItReportsTheClosestEarlierAsk(): void
    {
        $found = RepeatedAsk::in('do you have the gravel helmet in medium', $this->history(
            'what is your returns policy',
            'do you have the gravel helmet in medium',
        ));

        self::assertSame(2, $found['repeatedTurn'] ?? null);
    }

    /**
     * Four languages in the corpus, so the word split cannot be an English one. &ldquo;Sättel&rdquo;
     * and &ldquo;Gelsattel&rdquo; are a real pair from session 080d022a.
     */
    public function testItWorksOnNonEnglishText(): void
    {
        $found = RepeatedAsk::in('welche sättel gibt es?', $this->history('was gibt es für sättel?'));

        self::assertNotNull($found);
    }

    public function testAMessageOfOnlyNoiseWordsReportsNothing(): void
    {
        self::assertNull(RepeatedAsk::in('do you?', $this->history('do you?')));
    }
}
