<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\ShopInfo;

use Swag\AssistantStarterKit\Core\ShopInfo\Chunker;
use Swag\AssistantStarterKit\Core\ShopInfo\DocumentRecords;
use Swag\AssistantStarterKit\Core\ShopInfo\Embedder;
use Swag\AssistantStarterKit\Core\ShopInfo\ExtractorChain;
use Swag\AssistantStarterKit\Core\ShopInfo\PassageStore;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoDocument;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoPassage;

/**
 * A file becomes passages: extract, chunk, embed, replace. The only writer.
 *
 * **The order is the whole of spec R8.** The previous generation is deleted immediately before the
 * new one is written, and nothing else sits between those two steps. Any failure in between leaves
 * the document with no passages at all — which the `failed` status then explains — whereas the
 * reverse order can leave two generations in the store with no way for anyone to notice: retrieval
 * would answer from whichever it reached, and both look like valid passages.
 *
 * **The document id is derived from the sales channel and the file name**, so "the same document" is
 * well defined and re-uploading replaces rather than accumulates. It is per channel because two
 * channels genuinely have different terms (spec R12), so the same file name in two channels is two
 * documents.
 *
 * Every failure is recorded on the document before it is rethrown. A merchant needs to know that a
 * scan has no text layer, and the exception alone reaches a log nobody reads.
 */
final readonly class DocumentIngestion
{
    public function __construct(
        private ExtractorChain $extractor,
        private Chunker $chunker,
        private Embedder $embedder,
        private PassageStore $store,
        private DocumentRecords $records,
    ) {}

    /**
     * @return string the document id
     *
     * @throws \Throwable whatever failed, after recording it on the document
     */
    public function ingest(string $filename, string $bytes, string $salesChannelId): string
    {
        $name = basename($filename);
        $id = self::idFor($salesChannelId, $name);
        $extension = strtolower(pathinfo($name, \PATHINFO_EXTENSION));

        try {
            $text = $this->extractor->extract($name, $bytes);
            $chunks = $this->chunker->chunk($text);
            $vectors = $this->embedder->embed(array_map(static fn(array $chunk): string => $chunk['text'], $chunks));

            $this->store->deleteDocument($id);
            $this->store->add(self::passagesFor($id, $name, $chunks), $vectors, $salesChannelId);
        } catch (\Throwable $failure) {
            $this->records->save(new ShopInfoDocument(
                id: $id,
                name: $name,
                extension: $extension,
                salesChannelId: $salesChannelId,
                status: ShopInfoDocument::STATUS_FAILED,
                statusReason: $failure->getMessage(),
            ));

            throw $failure;
        }

        $this->records->save(new ShopInfoDocument(
            id: $id,
            name: $name,
            extension: $extension,
            salesChannelId: $salesChannelId,
            status: ShopInfoDocument::STATUS_INDEXED,
            chunkCount: \count($chunks),
            dimension: \count($vectors[0] ?? []),
            text: $text,
        ));

        return $id;
    }

    /** Both the passages and the record, so a deleted document leaves nothing retrievable. */
    public function remove(string $documentId): void
    {
        $this->store->deleteDocument($documentId);
        $this->records->delete($documentId);
    }

    /**
     * @param list<array{section: string, text: string}> $chunks
     *
     * @return list<ShopInfoPassage>
     */
    private static function passagesFor(string $id, string $name, array $chunks): array
    {
        $passages = [];

        foreach ($chunks as $chunk) {
            $passages[] = new ShopInfoPassage($id, $name, $chunk['section'], $chunk['text']);
        }

        return $passages;
    }

    /**
     * Deterministic, so re-uploading the same file name replaces its passages rather than adding a
     * second copy nobody can see. 32 hex characters, which is also a valid Shopware id.
     */
    private static function idFor(string $salesChannelId, string $name): string
    {
        return md5($salesChannelId . '#' . strtolower($name));
    }
}
