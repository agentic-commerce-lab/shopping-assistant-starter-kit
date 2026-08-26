<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\ShopInfo;

use Swag\AssistantStarterKit\Core\ShopInfo\PassageStore;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoPassage;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\Component\Uid\Uuid;

/**
 * The translation between our passages and the library's documents, in both directions.
 *
 * Split from {@see AiStorePassageStore} so that class is only about talking to the store: this one
 * owns the metadata keys, the id scheme and the score conversion, which are the three things a
 * reader needs to see together to understand what a row in the table means.
 *
 * **The score conversion is the load-bearing line.** The library's score is a cosine *distance* —
 * identical vectors score `0`, orthogonal ones `1`, measured against MariaDB 11.8.8 — while
 * {@see PassageStore} promises a similarity where higher is better. `1 - distance` is the whole of
 * it, and getting the sign wrong would rank the least relevant passage first while every test that
 * only checks "some passage came back" still passed.
 */
final readonly class PassageDocuments
{
    /**
     * Namespace for deterministic passage ids.
     *
     * Ids are UUIDv5 of `documentId#index`, which makes a re-index idempotent: writing the same
     * generation twice updates rows rather than duplicating them, because the bridge's insert is an
     * upsert on the primary key. Spec R8's delete-then-add still runs — this is the second line of
     * defence, not a replacement for it.
     */
    private const ID_NAMESPACE = '5f0a3f4c-1f3f-4a9e-9a3a-8d2e6b4c7a10';

    /**
     * @param list<VectorDocument> $documents
     * @param positive-int         $width
     */
    private function __construct(
        public array $documents,
        public int $width,
    ) {}

    /**
     * @param list<ShopInfoPassage> $passages
     * @param list<list<float>>     $vectors
     *
     * @throws \RuntimeException when the counts disagree, a vector is missing, or the batch is ragged
     */
    public static function of(array $passages, array $vectors, string $salesChannelId): self
    {
        if (\count($passages) !== \count($vectors)) {
            throw new \RuntimeException(\sprintf(
                'Expected one vector per passage, got %d passages and %d vectors.',
                \count($passages),
                \count($vectors),
            ));
        }

        $width = \count($vectors[0] ?? []);
        $documents = [];

        foreach ($passages as $index => $passage) {
            $vector = $vectors[$index] ?? [];

            if (\count($vector) !== $width || $width < 1) {
                // A ragged batch means one chunk embedded to a different width than its siblings,
                // which the store would either reject or, worse, accept into a mixed table.
                throw new \RuntimeException(\sprintf(
                    'Vector %d is %d wide but the batch is %d wide.',
                    $index,
                    \count($vector),
                    $width,
                ));
            }

            $documents[] = new VectorDocument(
                id: self::idFor($passage->documentId, $index),
                vector: new Vector($vector),
                metadata: new Metadata([
                    'documentId' => $passage->documentId,
                    'documentName' => $passage->documentName,
                    'section' => $passage->section,
                    'text' => $passage->text,
                    'salesChannelId' => $salesChannelId,
                ]),
            );
        }

        return new self($documents, max(1, $width));
    }

    public static function toPassage(VectorDocument $document): ShopInfoPassage
    {
        $metadata = $document->getMetadata();

        return new ShopInfoPassage(
            self::stringAt($metadata, 'documentId'),
            self::stringAt($metadata, 'documentName'),
            self::stringAt($metadata, 'section'),
            self::stringAt($metadata, 'text'),
            // Distance in, similarity out. A missing score is treated as maximally distant rather
            // than as a perfect match, so an unexpected null cannot promote a passage.
            1.0 - ($document->getScore() ?? 1.0),
        );
    }

    private static function stringAt(Metadata $metadata, string $key): string
    {
        $value = $metadata->offsetExists($key) ? $metadata->offsetGet($key) : null;

        return \is_string($value) ? $value : '';
    }

    private static function idFor(string $documentId, int $index): string
    {
        return Uuid::v5(Uuid::fromString(self::ID_NAMESPACE), \sprintf('%s#%d', $documentId, $index))->toRfc4122();
    }
}
