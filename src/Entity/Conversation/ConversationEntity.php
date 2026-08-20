<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Entity\Conversation;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Swag\AssistantStarterKit\Entity\TraceEvent\TraceEventCollection;

/**
 * One shopper conversation, and the row the widget re-hydrates from.
 *
 * `$transcript` holds the turns as JSON — card **ids**, never cards. A stored price would be a fact
 * frozen at write time and wrong by the next page load; every figure is re-rendered from the
 * catalogue on read.
 */
class ConversationEntity extends Entity
{
    use EntityIdTrait;

    protected string $salesChannelId;

    protected ?string $locale = null;

    protected int $turnCount = 0;

    protected ?string $outcome = null;

    protected int $totalMs = 0;

    /** @var array<int, mixed>|null */
    protected ?array $transcript = null;

    protected ?TraceEventCollection $events = null;

    public function getSalesChannelId(): string
    {
        return $this->salesChannelId;
    }

    public function setSalesChannelId(string $salesChannelId): void
    {
        $this->salesChannelId = $salesChannelId;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale): void
    {
        $this->locale = $locale;
    }

    public function getTurnCount(): int
    {
        return $this->turnCount;
    }

    public function setTurnCount(int $turnCount): void
    {
        $this->turnCount = $turnCount;
    }

    public function getOutcome(): ?string
    {
        return $this->outcome;
    }

    public function setOutcome(?string $outcome): void
    {
        $this->outcome = $outcome;
    }

    public function getTotalMs(): int
    {
        return $this->totalMs;
    }

    public function setTotalMs(int $totalMs): void
    {
        $this->totalMs = $totalMs;
    }

    /**
     * @return array<int, mixed>|null
     */
    public function getTranscript(): ?array
    {
        return $this->transcript;
    }

    /**
     * @param array<int, mixed>|null $transcript
     */
    public function setTranscript(?array $transcript): void
    {
        $this->transcript = $transcript;
    }

    public function getEvents(): ?TraceEventCollection
    {
        return $this->events;
    }

    public function setEvents(TraceEventCollection $events): void
    {
        $this->events = $events;
    }
}
