<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Entity\InsightFinding;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Swag\AssistantStarterKit\Entity\Conversation\ConversationEntity;
use Swag\AssistantStarterKit\Entity\InsightRun\InsightRunEntity;

class InsightFindingEntity extends Entity
{
    use EntityIdTrait;

    protected string $runId;

    protected ?string $conversationId = null;

    protected string $type;

    protected string $severity = 'info';

    protected string $summary = '';

    protected ?string $quote = null;

    protected ?string $suggestion = null;

    protected ?InsightRunEntity $run = null;

    protected ?ConversationEntity $conversation = null;

    public function getRunId(): string
    {
        return $this->runId;
    }

    public function setRunId(string $runId): void
    {
        $this->runId = $runId;
    }

    public function getConversationId(): ?string
    {
        return $this->conversationId;
    }

    public function setConversationId(?string $conversationId): void
    {
        $this->conversationId = $conversationId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function setSeverity(string $severity): void
    {
        $this->severity = $severity;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function setSummary(string $summary): void
    {
        $this->summary = $summary;
    }

    public function getQuote(): ?string
    {
        return $this->quote;
    }

    public function setQuote(?string $quote): void
    {
        $this->quote = $quote;
    }

    public function getSuggestion(): ?string
    {
        return $this->suggestion;
    }

    public function setSuggestion(?string $suggestion): void
    {
        $this->suggestion = $suggestion;
    }

    public function getRun(): ?InsightRunEntity
    {
        return $this->run;
    }

    public function setRun(?InsightRunEntity $run): void
    {
        $this->run = $run;
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
