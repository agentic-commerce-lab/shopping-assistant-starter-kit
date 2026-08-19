<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Agent;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Agent\SlidingWindowInputProcessor;
use Symfony\AI\Agent\Input;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

final class SlidingWindowInputProcessorTest extends TestCase
{
    public function testKeepsTheSystemMessageAndTheMostRecentTurns(): void
    {
        $messages = [Message::forSystem('rules')];
        for ($i = 0; $i < 40; ++$i) {
            $messages[] = Message::ofUser('turn ' . $i);
        }

        $input = new Input('gpt-x', new MessageBag(...$messages));
        (new SlidingWindowInputProcessor(maxMessages: 10, threshold: 20))->processInput($input);

        $kept = $input->getMessageBag();
        self::assertNotNull($kept->getSystemMessage());
        self::assertLessThanOrEqual(11, \count($kept->getMessages()));
    }

    public function testLeavesShortConversationsUntouched(): void
    {
        $input = new Input('gpt-x', new MessageBag(Message::forSystem('rules'), Message::ofUser('hello')));

        (new SlidingWindowInputProcessor(maxMessages: 10, threshold: 20))->processInput($input);

        self::assertCount(2, $input->getMessageBag()->getMessages());
    }
}
