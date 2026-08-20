<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Entity\TraceEvent;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Swag\AssistantStarterKit\Entity\Conversation\ConversationEntity;

/**
 * One pipeline stage of one turn.
 *
 * There is deliberately no `durationMs` (ruling R62): `TraceRecorder`'s events are
 * `{seq, stage, payload}` and carry no timing, so the column could only ever be written as 0 — and a
 * column that is always 0 is worse than an absent one, because someone will query it and believe the
 * answer.
 */
class TraceEventEntity extends Entity
{
    use EntityIdTrait;

    protected string $conversationId;

    protected int $seq = 0;

    protected string $stage;

    /** @var array<string, mixed>|null */
    protected ?array $payload = null;

    protected ?ConversationEntity $conversation = null;

    public function getConversationId(): string
    {
        return $this->conversationId;
    }

    public function setConversationId(string $conversationId): void
    {
        $this->conversationId = $conversationId;
    }

    public function getSeq(): int
    {
        return $this->seq;
    }

    public function setSeq(int $seq): void
    {
        $this->seq = $seq;
    }

    public function getStage(): string
    {
        return $this->stage;
    }

    public function setStage(string $stage): void
    {
        $this->stage = $stage;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPayload(): ?array
    {
        return $this->payload;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    public function setPayload(?array $payload): void
    {
        $this->payload = $payload;
    }

    public function getConversation(): ?ConversationEntity
    {
        return $this->conversation;
    }

    public function setConversation(?ConversationEntity $conversation): void
    {
        $this->conversation = $conversation;
    }
}
