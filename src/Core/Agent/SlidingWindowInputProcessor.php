<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\Agent;

use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessorInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\AI\Platform\Message\Role;

/**
 * Bounds how much conversation history reaches the model.
 *
 * Below {@see self::$threshold} non-system messages, the bag is left
 * untouched entirely — most turns never pay for this at all. Once a
 * conversation grows past that point, tool-role messages are dropped first:
 * their product ids are already reflected in the rendered cards from that
 * turn, so nothing shopper-facing is lost by removing them, and doing so
 * usually shrinks the bag enough on its own. Only if that is not enough does
 * this fall back to trimming the oldest remaining messages, down to
 * {@see self::$maxMessages}. The system message is never touched.
 */
final class SlidingWindowInputProcessor implements InputProcessorInterface
{
    public function __construct(
        private readonly int $maxMessages = 20,
        private readonly int $threshold = 40,
    ) {}

    public function processInput(Input $input): void
    {
        $bag = $input->getMessageBag();

        $systemMessages = [];
        $rest = [];
        foreach ($bag->getMessages() as $message) {
            if ($message->getRole() === Role::System) {
                $systemMessages[] = $message;

                continue;
            }

            $rest[] = $message;
        }

        if (\count($rest) < $this->threshold) {
            return;
        }

        while (\count($rest) > $this->maxMessages) {
            $toolIndex = self::firstToolMessageIndex($rest);

            if ($toolIndex !== null) {
                array_splice($rest, $toolIndex, length: 1);

                continue;
            }

            // No tool-role messages left to drop: fall back to trimming the oldest.
            array_shift($rest);
        }

        $input->setMessageBag(new MessageBag(...$systemMessages, ...$rest));
    }

    /**
     * @param list<MessageInterface> $messages
     */
    private static function firstToolMessageIndex(array $messages): ?int
    {
        foreach ($messages as $index => $message) {
            if ($message->getRole() === Role::ToolCall) {
                return $index;
            }
        }

        return null;
    }
}
