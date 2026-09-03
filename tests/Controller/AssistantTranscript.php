<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use PHPUnit\Framework\Assert;

/**
 * Reads one turn out of a `GET /assistant/history` payload.
 *
 * Its own class rather than a private method in each endpoint test that needs it: two tests now
 * assert that a link survives a page reload, and the base case is already at Mago's per-class method
 * limit. The loop exists at all because the analyzer treats a decoded JSON payload as `mixed` all the
 * way down, so the narrowing has to happen somewhere — once, here.
 */
final readonly class AssistantTranscript
{
    /**
     * @param array<array-key, mixed> $messages
     *
     * @return array<string, mixed>
     */
    public static function firstAssistantTurn(array $messages): array
    {
        foreach ($messages as $message) {
            Assert::assertIsArray($message);

            if (($message['role'] ?? null) === 'assistant') {
                $turn = [];
                foreach ($message as $key => $value) {
                    $turn[(string) $key] = $value;
                }

                return $turn;
            }
        }

        Assert::fail('the transcript holds no assistant turn');
    }
}
