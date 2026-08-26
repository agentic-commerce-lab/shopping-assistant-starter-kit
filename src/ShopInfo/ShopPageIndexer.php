<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\ShopInfo;

/**
 * Indexing the shop's own legal pages — all of them, or one that just changed.
 *
 * **It does not stop at the first failure.** A shop has five legal pages, and refusing the rest
 * because one is empty would make one bad page look like a broken feature. Each is attempted, each
 * failure carries its own reason.
 *
 * An empty configured page is *skipped*, not failed: a shop that has not written its terms yet is not
 * an error a merchant can fix here, and a permanent red row would train them to ignore the column.
 */
final readonly class ShopPageIndexer
{
    public function __construct(
        private CmsLegalPages $pages,
        private DocumentIngestionFactory $ingestions,
    ) {}

    /**
     * Index every configured legal page of one sales channel.
     *
     * @return array{indexed: int, failed: list<array{name: string, reason: string}>, skipped: list<string>}
     */
    public function indexPages(string $salesChannelId, string $embeddingModel): array
    {
        $ingestion = $this->ingestions->forSalesChannel($salesChannelId, $embeddingModel);
        $indexed = 0;
        $failed = [];
        $skipped = [];

        foreach ($this->pages->forSalesChannel($salesChannelId) as $page) {
            if (trim($page['html']) === '') {
                // A configured page with nothing on it. Skipped rather than failed: an empty page is a
                // shop that has not written its terms yet, which is not an error the merchant can fix
                // here — and recording it as a failed document would put a red row under their nose
                // every time they open the screen.
                $skipped[] = $page['name'];

                continue;
            }

            try {
                $ingestion->ingestPage($page['name'], $page['html'], $salesChannelId);
                ++$indexed;
            } catch (\Throwable $failure) {
                $failed[] = ['name' => $page['name'], 'reason' => $failure->getMessage()];
            }
        }

        return ['indexed' => $indexed, 'failed' => $failed, 'skipped' => $skipped];
    }

    /**
     * Index one page again, for the automatic path.
     *
     * Returns false when there is nothing to do — the page is not one of this channel's legal pages,
     * or it is empty — so the caller can say "nothing indexed" rather than logging a failure for a
     * change that simply was not ours to act on.
     *
     * @throws \Throwable when indexing itself fails, so the queue can retry it
     */
    public function indexPage(string $salesChannelId, string $cmsPageId, string $embeddingModel): bool
    {
        $page = $this->pages->pageFor($salesChannelId, $cmsPageId);

        if ($page === null || trim($page['html']) === '') {
            return false;
        }

        $this->ingestions->forSalesChannel($salesChannelId, $embeddingModel)->ingestPage(
            $page['name'],
            $page['html'],
            $salesChannelId,
        );

        return true;
    }
}
