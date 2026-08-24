<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use Shopware\Core\Framework\Context;
use Swag\AssistantStarterKit\Core\Trace\Export\TraceExportSource;
use Swag\AssistantStarterKit\Entity\Conversation\ConversationEntity;
use Swag\AssistantStarterKit\Entity\TraceEvent\TraceEventCollection;

/**
 * Returns one conversation per id it is asked for, unless told to drop some.
 *
 * A recording double rather than a mock, like {@see RecordingTurnRunner}: the assertions are about
 * *what the controller did with what it got* — which ids it passed on, what it did with the ones
 * that came back — and a recorded value states that more legibly than expectation syntax.
 */
final class FakeTraceExportSource implements TraceExportSource
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    /** @var list<string> */
    public array $lastIds = [];

    /** How many of the requested ids to leave unresolved, so the skipped-count path can be driven. */
    public int $dropCount = 0;

    /**
     * @param list<string> $ids
     *
     * @return array{conversations: list<ConversationEntity>, salesChannelNames: array<string, string>}
     */
    public function load(array $ids, Context $context): array
    {
        $this->lastIds = $ids;

        $resolved = \array_slice($ids, 0, max(0, \count($ids) - $this->dropCount));

        return [
            'conversations' => array_map(static fn(string $id): ConversationEntity => self::conversation(
                $id,
            ), $resolved),
            'salesChannelNames' => [self::CHANNEL => 'Storefront'],
        ];
    }

    private static function conversation(string $id): ConversationEntity
    {
        $conversation = new ConversationEntity();
        $conversation->setId($id);
        $conversation->setSalesChannelId(self::CHANNEL);
        $conversation->setTurnCount(1);
        $conversation->setOutcome('product_shown');
        $conversation->setTotalMs(0);
        $conversation->setCreatedAt(new \DateTimeImmutable('2026-08-24T10:12:04+00:00'));
        $conversation->setEvents(new TraceEventCollection([]));

        return $conversation;
    }
}
