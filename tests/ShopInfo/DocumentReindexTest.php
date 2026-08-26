<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\ShopInfo;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\ShopInfo\Chunker;
use Swag\AssistantStarterKit\Core\ShopInfo\Extractor\PlainExtractor;
use Swag\AssistantStarterKit\Core\ShopInfo\ExtractorChain;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoDocument;
use Swag\AssistantStarterKit\ShopInfo\DocumentIngestion;
use Swag\AssistantStarterKit\ShopInfo\InMemoryPassageStore;
use Swag\AssistantStarterKit\Tests\Core\ShopInfo\InMemoryDocumentRecords;
use Swag\AssistantStarterKit\Tests\Core\ShopInfo\StubEmbedder;

/**
 * Re-indexing from the text already stored, which is what makes spec R9's `text` column load-bearing.
 *
 * A merchant who changes the embedding model has to index everything again — the store refuses mixed
 * widths, deliberately. If that required re-uploading files from months ago it would be a data-loss
 * event rather than a maintenance task, so the extracted text is kept and this is the path that uses
 * it.
 *
 * Split from {@see DocumentIngestionTest} because Mago bounds methods per class.
 */
final class DocumentReindexTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    public function testReIndexingUsesTheStoredTextAndNeedsNoFile(): void
    {
        $store = new InMemoryPassageStore();
        $records = new InMemoryDocumentRecords();
        $ingestion = self::ingestion($store, $records);

        $id = $ingestion->ingest('widerruf.txt', "Widerrufsfrist\n\nBinnen vierzehn Tagen.", self::CHANNEL);
        $store->deleteDocument($id);

        // Spec R9: the file is long gone, and the stored text is what makes this possible at all.
        $ingestion->reindex($id);

        self::assertNotSame([], $store->query(self::vector(), self::CHANNEL, minScore: 0.0, limit: 10));
    }

    public function testReIndexingAnUnknownDocumentSaysSoRatherThanDoingNothing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no shop information document/i');

        self::ingestion(new InMemoryPassageStore())->reindex('ffffffffffffffffffffffffffffffff');
    }

    /**
     * A document whose extraction failed has no text, so re-indexing it must refuse.
     *
     * Succeeding quietly would flip its status to `indexed` with no passages behind it — a document
     * the merchant believes is searchable and which can never match anything.
     */
    public function testReIndexingADocumentWithNoExtractedTextRefuses(): void
    {
        $records = new InMemoryDocumentRecords();
        $records->save(new ShopInfoDocument(
            id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            name: 'scan.pdf',
            extension: 'pdf',
            salesChannelId: self::CHANNEL,
            status: ShopInfoDocument::STATUS_FAILED,
            statusReason: 'no text layer',
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/nothing to index/i');

        self::ingestion(new InMemoryPassageStore(), $records)->reindex('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
    }

    private static function ingestion(
        InMemoryPassageStore $store,
        ?InMemoryDocumentRecords $records = null,
    ): DocumentIngestion {
        return new DocumentIngestion(
            new ExtractorChain([new PlainExtractor()]),
            new Chunker(),
            new StubEmbedder(),
            $store,
            $records ?? new InMemoryDocumentRecords(),
        );
    }

    /** @return list<float> */
    private static function vector(): array
    {
        return StubEmbedder::vectorFor('Widerrufsfrist');
    }
}
