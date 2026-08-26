<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Core\ShopInfo;

/**
 * Where the document list lives, as `Core` is allowed to see it.
 *
 * A port rather than Shopware's `EntityRepository` for the same reason {@see PassageStore} is not the
 * library's store: it keeps a Shopware type out of `Core`, and it makes ingestion testable without a
 * database. Faking `EntityRepository` means constructing DAL internals; faking this is ten lines.
 */
interface DocumentRecords
{
    public function save(ShopInfoDocument $document): void;

    public function find(string $salesChannelId, string $name): ?ShopInfoDocument;

    /**
     * By id, for re-indexing.
     *
     * Re-indexing works from {@see ShopInfoDocument::$text} rather than the original file (spec R9),
     * so the admin surface needs to reach a document by the only identifier it shows in a table row.
     */
    public function findById(string $documentId): ?ShopInfoDocument;

    /** @return list<ShopInfoDocument> */
    public function all(string $salesChannelId): array;

    public function delete(string $documentId): void;
}
