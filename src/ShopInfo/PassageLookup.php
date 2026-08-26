<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\ShopInfo;

use Swag\AssistantStarterKit\Core\ShopInfo\EmbedderFactory;
use Swag\AssistantStarterKit\Core\ShopInfo\PassageStore;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoPassage;

/**
 * Embed a question and retrieve passages for it — the two halves of a lookup, which are never useful
 * apart.
 *
 * Retrieval always costs one embedding call, because the question has to be turned into a vector by
 * the same model that produced the vectors it is compared against. That is the one thing a caller
 * must not be able to get wrong, so the pairing lives here rather than at each call site.
 *
 * The threshold is a parameter rather than a constant of this class: the tool applies spec R3's
 * threshold, while the CLI passes `0.0` deliberately so it can print the rejected scores that R4
 * calibrates the threshold from.
 */
final readonly class PassageLookup
{
    public function __construct(
        private EmbedderFactory $embedders,
        private PassageStore $store,
    ) {}

    /**
     * @return list<ShopInfoPassage>
     *
     * @throws \RuntimeException when the provider cannot embed, or the store holds another width
     */
    public function retrieve(
        string $question,
        string $salesChannelId,
        string $embeddingModel,
        float $minScore,
        int $limit,
    ): array {
        $vectors = $this->embedders->forSalesChannel($salesChannelId, $embeddingModel)->embed([$question]);

        return $this->store->query($vectors[0] ?? [], $salesChannelId, $minScore, $limit);
    }

    /** So a deleted document leaves nothing retrievable, with no embedding call to do it. */
    public function deleteDocument(string $documentId): void
    {
        $this->store->deleteDocument($documentId);
    }
}
