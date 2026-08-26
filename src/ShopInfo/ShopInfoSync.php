<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\ShopInfo;

use Swag\AssistantStarterKit\Core\ShopInfo\DocumentRecords;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoDocument;

/**
 * Indexing every document of a channel again, each from its own source.
 *
 * **Re-indexing reads from where the document came from.** A CMS document is read from the page again,
 * because a merchant who edited their revocation notice and pressed the button expects the new
 * wording. An uploaded document is re-indexed from its stored text, because the file is not kept
 * (spec R9). Getting this backwards would silently serve last week's terms.
 *
 * **It does not stop at the first failure.** A merchant may have twenty documents, and refusing the
 * rest because one is broken would make one bad document look like a broken feature. Each is
 * attempted, each failure carries its own reason, and the caller gets counts — which is what makes
 * "19 indexed, 1 failed" plus a row explaining which useful in the Administration.
 *
 * Indexing the shop's own pages lives in {@see ShopPageIndexer}: different unit of work, and Mago
 * bounds complexity per class.
 */
final readonly class ShopInfoSync
{
    public function __construct(
        private CmsLegalPages $pages,
        private DocumentIngestionFactory $ingestions,
        private DocumentRecords $records,
    ) {}

    /**
     * Index every document of one sales channel again, each from its own source.
     *
     * The action a merchant needs after changing the embedding model, which invalidates every vector
     * in the store — see `ShopInfoVectorTable` for why that is a refusal rather than a silent
     * mismatch.
     *
     * @return array{indexed: int, failed: list<array{name: string, reason: string}>, skipped: list<string>}
     */
    public function reindexAll(string $salesChannelId, string $embeddingModel): array
    {
        $documents = $this->records->all($salesChannelId);
        $fromPages = $this->pageHtmlByName($salesChannelId);
        $ingestion = $this->ingestions->forSalesChannel($salesChannelId, $embeddingModel);

        $indexed = 0;
        $failed = [];

        foreach ($documents as $document) {
            try {
                $this->reindexOne($ingestion, $document, $fromPages);
                ++$indexed;
            } catch (\Throwable $failure) {
                $failed[] = ['name' => $document->name, 'reason' => $failure->getMessage()];
            }
        }

        return ['indexed' => $indexed, 'failed' => $failed, 'skipped' => []];
    }

    /**
     * @param array<string, string> $fromPages
     *
     * @throws \Throwable
     */
    private function reindexOne(DocumentIngestion $ingestion, ShopInfoDocument $document, array $fromPages): void
    {
        $html = $document->source === ShopInfoDocument::SOURCE_CMS ? $fromPages[$document->name] ?? null : null;

        if ($html !== null && trim($html) !== '') {
            $ingestion->ingestPage($document->name, $html, $document->salesChannelId);

            return;
        }

        // Either an uploaded document, or a CMS document whose page has since been unconfigured or
        // emptied. The stored text is then the only copy there is, and re-indexing it keeps the
        // document working rather than deleting a merchant's content behind their back.
        $ingestion->reindex($document->id);
    }

    /**
     * @return array<string, string>
     */
    private function pageHtmlByName(string $salesChannelId): array
    {
        $byName = [];

        foreach ($this->pages->forSalesChannel($salesChannelId) as $page) {
            $byName[$page['name']] = $page['html'];
        }

        return $byName;
    }
}
