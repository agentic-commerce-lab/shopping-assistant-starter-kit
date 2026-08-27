<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\ShopInfo;

use Swag\AssistantStarterKit\Core\ShopInfo\Chunker;
use Swag\AssistantStarterKit\Core\ShopInfo\DocumentRecords;
use Swag\AssistantStarterKit\Core\ShopInfo\EmbedderFactory;
use Swag\AssistantStarterKit\Core\ShopInfo\ExtractorChain;
use Swag\AssistantStarterKit\Core\ShopInfo\PassageStore;

/**
 * {@see DocumentIngestion} for one sales channel.
 *
 * Ingestion needs an embedder and an embedder is per channel — the provider, key and model are all
 * per-channel settings — while everything else it needs is a singleton. This is the join, and it
 * exists so `DocumentIngestion` can keep taking a plain {@see \Swag\AssistantStarterKit\Core\ShopInfo\Embedder}
 * and stay testable with a stub instead of a factory of a stub.
 */
final readonly class DocumentIngestionFactory
{
    public function __construct(
        private ExtractorChain $extractor,
        private Chunker $chunker,
        private EmbedderFactory $embedders,
        private PassageStore $store,
        private DocumentRecords $records,
    ) {}

    public function forSalesChannel(string $salesChannelId, string $embeddingModel): DocumentIngestion
    {
        return new DocumentIngestion(
            $this->extractor,
            $this->chunker,
            $this->embedders->forSalesChannel($salesChannelId, $embeddingModel),
            $this->store,
            $this->records,
        );
    }
}
