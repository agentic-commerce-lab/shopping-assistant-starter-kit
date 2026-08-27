<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\ShopInfo;

use Swag\AssistantStarterKit\Core\ShopInfo\PassageStore;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Query\VectorQuery;

/**
 * {@see PassageStore} over `symfony/ai-store`'s MariaDB bridge, on the shop's own database.
 *
 * Lives outside `Core\` because it names library types, which `Core` may not — the same rule that
 * keeps Shopware out of `Core\Commerce`. {@see PassageDocuments} owns the mapping and the score
 * conversion; {@see ShopInfoVectorTable} owns the schema. What is left here is the store's own
 * behaviour: refuse a query the table cannot answer, and filter every query by its tenant.
 *
 * **The tenant filter is a bound SQL fragment, not string interpolation.** The bridge's filtering
 * option is a raw `where` clause with a separate `params` array — it has no structured filter, which
 * is where this differs from what the spec recorded. The sales-channel id goes through `params`, so
 * a channel id can never become SQL.
 */
final readonly class AiStorePassageStore implements PassageStore
{
    private const TENANT_FILTER = "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.salesChannelId')) = :salesChannelId";

    public function __construct(
        private ShopInfoVectorTable $table,
    ) {}

    /** @throws \Doctrine\DBAL\Exception */
    public function add(array $passages, array $vectors, string $salesChannelId): void
    {
        $batch = PassageDocuments::of($passages, $vectors, $salesChannelId);

        if ($batch->documents === []) {
            return;
        }

        $this->table->ensureWidth($batch->width);
        $this->table->store()->add($batch->documents);
    }

    /** @throws \Doctrine\DBAL\Exception */
    public function query(array $vector, string $salesChannelId, float $minScore, int $limit): array
    {
        $width = $this->table->dimension();

        if ($width === null || $limit < 1) {
            // Nothing has ever been indexed. Not an error: the merchant simply has no documents, and
            // the tool's threshold branch already knows how to say so.
            return [];
        }

        if ($width !== \count($vector)) {
            throw new \RuntimeException(\sprintf(
                'The shop information store holds %d-wide vectors but the question embedded to %d. '
                . 'The embedding model changed since indexing: index the documents again.',
                $width,
                \count($vector),
            ));
        }

        $documents = $this->table->store()->query(new VectorQuery(new Vector($vector)), [
            'limit' => $limit,
            // Similarity in, distance out: minScore 0.75 admits everything within 0.25 distance.
            'maxScore' => 1.0 - $minScore,
            'where' => self::TENANT_FILTER,
            'params' => ['salesChannelId' => $salesChannelId],
        ]);

        $passages = [];

        foreach ($documents as $document) {
            $passages[] = PassageDocuments::toPassage($document);
        }

        return $passages;
    }

    /** @throws \Doctrine\DBAL\Exception */
    public function deleteDocument(string $documentId): void
    {
        $this->table->deleteByDocument($documentId);
    }

    /** @throws \Doctrine\DBAL\Exception */
    public function dimension(): ?int
    {
        return $this->table->dimension();
    }
}
