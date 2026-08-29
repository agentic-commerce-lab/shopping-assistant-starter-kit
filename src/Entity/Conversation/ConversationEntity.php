<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Entity\Conversation;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Swag\AssistantStarterKit\Core\Context\ShoppingMode;
use Swag\AssistantStarterKit\Entity\TraceEvent\TraceEventCollection;

/**
 * One shopper conversation, and the row the widget re-hydrates from.
 *
 * `$transcript` holds the turns as JSON — card **ids**, never cards. A stored price would be a fact
 * frozen at write time and wrong by the next page load; every figure is re-rendered from the
 * catalogue on read.
 */
// @mago-expect lint:too-many-methods
// @mago-expect lint:too-many-properties
// This class mirrors a database table, and its property and accessor count IS the table's column
// count — not a design this class chose. Task 4 added `scopeType`, `commercialEmployeeId` and
// `commercialOrganisationId` beside the nine columns that were already here, which is what pushed
// it past both thresholds. A same-shape trait split is available, but that would move members
// into a second file without reducing them: the total any reader has to hold in their head to
// understand what this entity carries stays identical, and the trait would exist solely to make
// the gate stop counting — evasion, not decomposition. The honest fix is the suppression.
class ConversationEntity extends Entity
{
    use EntityIdTrait;

    protected string $salesChannelId;

    /**
     * The shopper who started this conversation, or null for a guest.
     *
     * Null also means "was a customer, and that customer deleted their account": the foreign key is
     * `ON DELETE SET NULL`, so the two are indistinguishable on purpose.
     */
    protected ?string $customerId = null;

    protected ?CustomerEntity $customer = null;

    /** Which kind of shopper this conversation belongs to, so it can be compared against a presented scope. */
    protected string $scopeType = ShoppingMode::Guest->value;

    protected ?string $commercialEmployeeId = null;

    protected ?string $commercialOrganisationId = null;

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

    public function getCustomerId(): ?string
    {
        return $this->customerId;
    }

    public function setCustomerId(?string $customerId): void
    {
        $this->customerId = $customerId;
    }

    public function getCustomer(): ?CustomerEntity
    {
        return $this->customer;
    }

    public function setCustomer(?CustomerEntity $customer): void
    {
        $this->customer = $customer;
    }

    public function getScopeType(): string
    {
        return $this->scopeType;
    }

    public function setScopeType(string $scopeType): void
    {
        $this->scopeType = $scopeType;
    }

    public function getCommercialEmployeeId(): ?string
    {
        return $this->commercialEmployeeId;
    }

    public function setCommercialEmployeeId(?string $commercialEmployeeId): void
    {
        $this->commercialEmployeeId = $commercialEmployeeId;
    }

    public function getCommercialOrganisationId(): ?string
    {
        return $this->commercialOrganisationId;
    }

    public function setCommercialOrganisationId(?string $commercialOrganisationId): void
    {
        $this->commercialOrganisationId = $commercialOrganisationId;
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
