<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Trace;

use Swag\AssistantStarterKit\Core\Trace\ConversationStore;
use Swag\AssistantStarterKit\Core\Trace\ConversationTurn;
use Swag\AssistantStarterKit\Core\Trace\TraceEvent;
use Swag\AssistantStarterKit\Core\Trace\TraceRecorder;

/**
 * An in-memory {@see ConversationStore} for tests, held to the same contract test as the Shopware
 * implementation — which is the point: a double that is merely *plausible* would let the controller
 * tests pass against behaviour the real store does not have.
 */
final class InMemoryConversationStore implements ConversationStore
{
    /** @var array<string, list<ConversationTurn>> */
    private array $turns = [];

    /** @var array<string, list<TraceEvent>> */
    private array $events = [];

    private int $tokenCounter = 0;

    public function start(string $salesChannelId, string $locale): string
    {
        $this->tokenCounter++;
        $token = \sprintf('%032x', $this->tokenCounter);

        $this->turns[$token] = [];
        $this->events[$token] = [];

        return $token;
    }

    public function append(#[\SensitiveParameter] string $token, ConversationTurn $turn, TraceRecorder $trace): void
    {
        $this->turns[$token][] = $turn;

        foreach ($trace->events() as $event) {
            $this->events[$token][] = $event;
        }
    }

    public function history(#[\SensitiveParameter] string $token, int $limit = 20): array
    {
        return \array_slice($this->turns[$token] ?? [], -$limit);
    }

    public function traceEvents(#[\SensitiveParameter] string $token): array
    {
        return $this->events[$token] ?? [];
    }
}
