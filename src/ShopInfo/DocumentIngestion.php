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

        $document = new ShopInfoDocument(
            id: self::idFor($salesChannelId, $name),
            name: $name,
            extension: strtolower(pathinfo($name, \PATHINFO_EXTENSION)),
            salesChannelId: $salesChannelId,
            status: ShopInfoDocument::STATUS_PENDING,
        );

        try {
            $text = $this->extractor->extract($name, $bytes);
        } catch (\Throwable $failure) {
            $this->recordFailure($document, $failure);

            throw $failure;
        }

        return $this->write($document, $text);
    }

    /**
     * Index one of the shop's own CMS pages (spec R1).
     *
     * The HTML goes through the same extractor an uploaded `.html` file does — which is why HTML is in
     * R10's format list although nobody uploads it. `page.html` is a filename only in the sense the
     * extractor chain needs one to pick by extension; the document is named after the page.
     *
     * @return string the document id
     *
     * @throws \Throwable whatever failed, after recording it on the document
     */
    public function ingestPage(string $name, string $html, string $salesChannelId): string
    {
        $document = new ShopInfoDocument(
            id: self::idFor($salesChannelId, $name),
            name: $name,
            extension: 'html',
            salesChannelId: $salesChannelId,
            status: ShopInfoDocument::STATUS_PENDING,
            source: ShopInfoDocument::SOURCE_CMS,
        );

        try {
            $text = $this->extractor->extract('page.html', $html);
        } catch (\Throwable $failure) {
            $this->recordFailure($document, $failure);

            throw $failure;
        }

        return $this->write($document, $text);
    }

    /**
     * Index a document again from the text already stored for it (spec R9).
     *
     * The reason the extracted text is a column rather than a discarded intermediate: a merchant who
     * changes the embedding model has to re-index everything, and asking them to re-upload files from
     * months ago would make that a data-loss event instead of a maintenance task.
     *
     * @return string the document id
     *
     * @throws \RuntimeException when there is no such document, or nothing was ever extracted from it
     * @throws \Throwable        whatever failed, after recording it on the document
     */
    public function reindex(string $documentId): string
    {
        $document = $this->records->findById($documentId);

        if ($document === null) {
            throw new \RuntimeException(\sprintf('No shop information document with id "%s".', $documentId));
        }

        if (trim($document->text) === '') {
            // A document whose extraction failed has no text to index, and quietly succeeding here
            // would report it as indexed with no passages behind it.
            throw new \RuntimeException(\sprintf(
                'Document "%s" has no extracted text, so there is nothing to index. Upload it again.',
                $document->name,
            ));
        }

        return $this->write($document, $document->text);
    }

    /**
     * Chunk, embed, replace, record — the half {@see self::ingest()} and {@see self::reindex()} share.
     *
     * @throws \Throwable
     */
    private function write(ShopInfoDocument $document, string $text): string
    {
        try {
            $chunks = $this->chunker->chunk($text);
            $vectors = $this->embedder->embed(array_map(static fn(array $chunk): string => $chunk['text'], $chunks));

            $this->store->deleteDocument($document->id);
            $this->store->add(
                self::passagesFor($document->id, $document->name, $chunks),
                $vectors,
                $document->salesChannelId,
            );
        } catch (\Throwable $failure) {
            $this->recordFailure($document, $failure);

            throw $failure;
        }

        $this->records->save(new ShopInfoDocument(
            id: $document->id,
            name: $document->name,
            extension: $document->extension,
            salesChannelId: $document->salesChannelId,
            status: ShopInfoDocument::STATUS_INDEXED,
            chunkCount: \count($chunks),
            dimension: \count($vectors[0] ?? []),
            text: $text,
            // Carried through, because it decides where a re-index reads from: a CMS document is read
            // from the page again, an upload from this stored text.
            source: $document->source,
        ));

        return $document->id;
    }

    private function recordFailure(ShopInfoDocument $document, \Throwable $failure): void
    {
        $this->records->save(new ShopInfoDocument(
            id: $document->id,
            name: $document->name,
            extension: $document->extension,
            salesChannelId: $document->salesChannelId,
            status: ShopInfoDocument::STATUS_FAILED,
            statusReason: $failure->getMessage(),
            source: $document->source,
        ));
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
