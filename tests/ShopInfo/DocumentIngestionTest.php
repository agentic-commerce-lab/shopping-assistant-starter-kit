<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\ShopInfo;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\ShopInfo\Chunker;
use Swag\AssistantStarterKit\Core\ShopInfo\Embedder;
use Swag\AssistantStarterKit\Core\ShopInfo\ExtractionFailed;
use Swag\AssistantStarterKit\Core\ShopInfo\Extractor\PlainExtractor;
use Swag\AssistantStarterKit\Core\ShopInfo\ExtractorChain;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoDocument;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoPassage;
use Swag\AssistantStarterKit\ShopInfo\DocumentIngestion;
use Swag\AssistantStarterKit\ShopInfo\InMemoryPassageStore;
use Swag\AssistantStarterKit\Tests\Core\ShopInfo\InMemoryDocumentRecords;
use Swag\AssistantStarterKit\Tests\Core\ShopInfo\StubEmbedder;

/**
 * The write path: file in, passages in the store, one generation at a time.
 *
 * Spec R8 is the assertion that earns this file — re-ingesting a document must leave exactly one
 * generation of its chunks. A merchant who uploads a corrected revocation notice without the old one
 * being removed has both in the store, retrieval can serve either, and nobody would ever see it.
 */
final class DocumentIngestionTest extends TestCase
{
    private const CHANNEL = '01a01b4af6567284ac9eeb3616598ac3';

    public function testItStoresOnePassagePerChunk(): void
    {
        $store = new InMemoryPassageStore();

        self::ingestion($store)->ingest('widerruf.txt', "Widerrufsfrist\n\nBinnen vierzehn Tagen.", self::CHANNEL);

        self::assertNotSame([], $store->query(self::vector(), self::CHANNEL, minScore: 0.0, limit: 10));
    }

    public function testReIngestingReplacesTheOldGeneration(): void
    {
        $store = new InMemoryPassageStore();
        $ingestion = self::ingestion($store);

        $ingestion->ingest('widerruf.txt', "Widerrufsfrist\n\nBinnen vierzehn Tagen.", self::CHANNEL);
        $before = \count($store->query(self::vector(), self::CHANNEL, minScore: 0.0, limit: 100));

        $ingestion->ingest('widerruf.txt', "Widerrufsfrist\n\nBinnen dreissig Tagen.", self::CHANNEL);
        $after = $store->query(self::vector(), self::CHANNEL, minScore: 0.0, limit: 100);

        self::assertCount($before, $after, 'the old generation must be gone, not accumulated');

        $texts = implode(' ', array_map(static fn(ShopInfoPassage $p): string => $p->text, $after));
        self::assertStringContainsString('dreissig', $texts);
        self::assertStringNotContainsString('vierzehn', $texts);
    }

    public function testAnUnextractableFileLandsInFailedStatusAndStoresNothing(): void
    {
        $store = new InMemoryPassageStore();
        $records = new InMemoryDocumentRecords();

        try {
            self::ingestion($store, $records)->ingest('groessen.xlsx', 'anything', self::CHANNEL);
            self::fail('expected ExtractionFailed');
        } catch (ExtractionFailed) {
            // The point: nothing was written. A half-ingested document is worse than none, because
            // it answers questions from a fragment.
            self::assertSame([], $store->query(self::vector(), self::CHANNEL, minScore: 0.0, limit: 100));
        }

        // And the merchant can see why, without the file.
        $document = $records->find(self::CHANNEL, 'groessen.xlsx');
        self::assertNotNull($document);
        self::assertSame(ShopInfoDocument::STATUS_FAILED, $document->status);
        self::assertStringContainsString('xlsx', $document->statusReason);
    }

    public function testOneChannelCannotSeeAnotherChannelsDocument(): void
    {
        $store = new InMemoryPassageStore();

        self::ingestion($store)->ingest('agb.txt', "AGB\n\nGilt fuer Kanal A.", self::CHANNEL);

        // Spec R12, asserted rather than assumed.
        self::assertSame([], $store->query(self::vector(), 'ffffffffffffffffffffffffffffffff', 0.0, 100));
    }

    public function testAnIndexedDocumentRecordsItsChunkCountAndWidth(): void
    {
        $records = new InMemoryDocumentRecords();

        self::ingestion(new InMemoryPassageStore(), $records)
            ->ingest('widerruf.txt', "Widerrufsfrist\n\nBinnen vierzehn Tagen.", self::CHANNEL);

        $document = $records->find(self::CHANNEL, 'widerruf.txt');
        self::assertNotNull($document);
        self::assertSame(ShopInfoDocument::STATUS_INDEXED, $document->status);
        self::assertSame(1, $document->chunkCount);
        self::assertSame(StubEmbedder::WIDTH, $document->dimension);
        // The extracted text is stored so re-indexing needs no access to the original file (R9).
        self::assertStringContainsString('vierzehn', $document->text);
    }

    /**
     * The dimension guard from the plan's *Two gaps*: a store built at one width must refuse vectors
     * of another rather than mix them, because a mixed store answers with whichever half it queried.
     */
    public function testAWidthChangeIsRefusedRatherThanMixedIntoTheStore(): void
    {
        $store = new InMemoryPassageStore();

        self::ingestion($store)->ingest('agb.txt', "AGB\n\nGilt hier.", self::CHANNEL);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/index/i');

        self::ingestion($store, embedder: new StubEmbedder(width: StubEmbedder::WIDTH + 8))
            ->ingest('widerruf.txt', "Widerruf\n\nBinnen vierzehn Tagen.", self::CHANNEL);
    }

    private static function ingestion(
        InMemoryPassageStore $store,
        ?InMemoryDocumentRecords $records = null,
        ?Embedder $embedder = null,
    ): DocumentIngestion {
        return new DocumentIngestion(
            new ExtractorChain([new PlainExtractor()]),
            new Chunker(),
            $embedder ?? new StubEmbedder(),
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
