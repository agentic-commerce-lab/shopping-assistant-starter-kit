<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\ShopInfo;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Swag\AssistantStarterKit\Core\ShopInfo\DocumentRecords;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoDocument;
use Swag\AssistantStarterKit\Entity\AssistantDocument\AssistantDocumentEntity;

/**
 * {@see DocumentRecords} over the DAL. Outside `Core\` because it names Shopware types.
 *
 * `upsert()` rather than `create()`: the id is derived from the channel and the file name, so
 * re-indexing the same document is an update of one row. That is spec R8 seen from the record side —
 * the same reason the table has a unique key on that pair.
 *
 * `Context::createDefaultContext()` because ingestion runs from a CLI or an admin request, neither of
 * which carries a sales-channel context, and this table is not channel-scoped in the DAL sense: the
 * channel is a column it filters on, not a context it lives in.
 */
final readonly class DalDocumentRecords implements DocumentRecords
{
    public function __construct(
        private EntityRepository $documents,
    ) {}

    public function save(ShopInfoDocument $document): void
    {
        $this->documents->upsert([[
            'id' => $document->id,
            'name' => $document->name,
            'fileExtension' => $document->extension,
            'salesChannelId' => $document->salesChannelId,
            'status' => $document->status,
            'statusReason' => $document->statusReason,
            'chunkCount' => $document->chunkCount,
            'dimension' => $document->dimension,
            'text' => $document->text,
        ]], Context::createDefaultContext());
    }

    public function find(string $salesChannelId, string $name): ?ShopInfoDocument
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('salesChannelId', $salesChannelId))
            ->addFilter(new EqualsFilter('name', $name))
            ->setLimit(1);

        $entity = $this->documents->search($criteria, Context::createDefaultContext())->first();

        return $entity instanceof AssistantDocumentEntity ? self::toDocument($entity) : null;
    }

    public function all(string $salesChannelId): array
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));

        $documents = [];

        foreach ($this->documents->search($criteria, Context::createDefaultContext()) as $entity) {
            if ($entity instanceof AssistantDocumentEntity) {
                $documents[] = self::toDocument($entity);
            }
        }

        return $documents;
    }

    public function delete(string $documentId): void
    {
        $this->documents->delete([['id' => $documentId]], Context::createDefaultContext());
    }

    private static function toDocument(AssistantDocumentEntity $entity): ShopInfoDocument
    {
        return new ShopInfoDocument(
            id: $entity->getId(),
            name: $entity->getName(),
            extension: $entity->getFileExtension(),
            salesChannelId: $entity->getSalesChannelId(),
            status: $entity->getStatus(),
            statusReason: $entity->getStatusReason() ?? '',
            chunkCount: $entity->getChunkCount(),
            dimension: $entity->getDimension(),
            text: $entity->getText() ?? '',
        );
    }
}
