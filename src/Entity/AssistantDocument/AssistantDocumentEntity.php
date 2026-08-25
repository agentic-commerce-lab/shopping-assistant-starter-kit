<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Entity\AssistantDocument;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoDocument;

/**
 * One uploaded shop document.
 *
 * `status` is one of {@see ShopInfoDocument}'s three constants. It is a string rather than an enum
 * column because a DAL `StringField` is what the table holds either way, and the constants are the
 * single place the vocabulary is defined.
 *
 * `dimension` records the vector width this document was indexed at. It is what makes a changed
 * embedding model diagnosable instead of merely broken: a document at 1536 in a store now expecting
 * 3072 is visible in the list rather than only in a failed query.
 */
class AssistantDocumentEntity extends Entity
{
    use EntityIdTrait;

    protected string $name;

    /**
     * The file extension, named `fileExtension` rather than `extension`.
     *
     * `Entity` inherits `ExtendableTrait::getExtension(string $name)` for Shopware's struct
     * extensions, so a `getExtension()` of our own is an incompatible override — the analyzer catches
     * it, and at runtime it would break anything reading struct extensions off a document.
     */
    protected string $fileExtension = '';

    protected string $salesChannelId;

    protected string $status = ShopInfoDocument::STATUS_PENDING;

    protected ?string $statusReason = null;

    protected int $chunkCount = 0;

    protected int $dimension = 0;

    protected ?string $text = null;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getFileExtension(): string
    {
        return $this->fileExtension;
    }

    public function setFileExtension(string $fileExtension): void
    {
        $this->fileExtension = $fileExtension;
    }

    public function getSalesChannelId(): string
    {
        return $this->salesChannelId;
    }

    public function setSalesChannelId(string $salesChannelId): void
    {
        $this->salesChannelId = $salesChannelId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getStatusReason(): ?string
    {
        return $this->statusReason;
    }

    public function setStatusReason(?string $statusReason): void
    {
        $this->statusReason = $statusReason;
    }

    public function getChunkCount(): int
    {
        return $this->chunkCount;
    }

    public function setChunkCount(int $chunkCount): void
    {
        $this->chunkCount = $chunkCount;
    }

    public function getDimension(): int
    {
        return $this->dimension;
    }

    public function setDimension(int $dimension): void
    {
        $this->dimension = $dimension;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function setText(?string $text): void
    {
        $this->text = $text;
    }
}
