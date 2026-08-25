<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\ShopInfo;

use Swag\AssistantStarterKit\Core\ShopInfo\DocumentRecords;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoDocument;

/** @internal the document list, without a database */
final class InMemoryDocumentRecords implements DocumentRecords
{
    /** @var array<string, ShopInfoDocument> */
    private array $documents = [];

    public function save(ShopInfoDocument $document): void
    {
        $this->documents[$document->id] = $document;
    }

    public function find(string $salesChannelId, string $name): ?ShopInfoDocument
    {
        foreach ($this->documents as $document) {
            if ($document->salesChannelId === $salesChannelId && $document->name === $name) {
                return $document;
            }
        }

        return null;
    }

    public function all(string $salesChannelId): array
    {
        return array_values(array_filter(
            $this->documents,
            static fn(ShopInfoDocument $d): bool => $d->salesChannelId === $salesChannelId,
        ));
    }

    public function delete(string $documentId): void
    {
        unset($this->documents[$documentId]);
    }
}
