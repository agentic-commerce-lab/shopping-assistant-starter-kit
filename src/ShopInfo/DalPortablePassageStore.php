<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\ShopInfo;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Swag\AssistantStarterKit\Core\ShopInfo\PassageStore;
use Swag\AssistantStarterKit\Core\ShopInfo\ShopInfoPassage;

/**
 * Keeps passages in an ordinary table and compares them in PHP.
 *
 * **Not a lesser version of the MariaDB store — a different trade.** MySQL Community has no distance
 * function at any version (`DISTANCE()` is HeatWave-only), so similarity cannot be computed in SQL
 * there at all. What this gives up is the index; what it gains is running everywhere Shopware does,
 * exact rather than approximate results, and full float64 precision instead of the vector type's
 * float32.
 *
 * The corpus is a shop's legal pages and its own uploads — tens to low hundreds of passages — so the
 * scan is not a compromise at this size. It is also the arithmetic this project has always measured:
 * `Eval\ShopInfoFixture` runs the journeys over `InMemoryPassageStore`, which ranks the same way.
 *
 * `document_id` is stored and bound as the opaque string `PassageStore` promises, not as UUID bytes:
 * `AiStorePassageStore` stores it verbatim in the vector row's metadata and `InMemoryPassageStore`
 * compares it directly, so this store treats it the same way. Only `id` — the row's own surrogate
 * key, which never crosses the interface — is a UUID.
 */
final class DalPortablePassageStore implements PassageStore
{
    private const TABLE = 'swag_assistant_shop_info_passage';

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * @param list<ShopInfoPassage> $passages
     * @param list<list<float>>     $vectors
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function add(array $passages, array $vectors, string $salesChannelId): void
    {
        // Checked before the first insert, not per row: a half-written document is worse than a
        // refused one, because nothing downstream can tell it apart from a complete one.
        if (\count($passages) !== \count($vectors)) {
            throw new \RuntimeException(\sprintf(
                'Got %d passages and %d vectors; they must correspond one to one.',
                \count($passages),
                \count($vectors),
            ));
        }

        // A store must not hold two widths: it would answer from whichever half the query reached.
        // Same guard, same call shape as InMemoryPassageStore::add().
        StoreWidth::guard($this->dimension(), $vectors);

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');

        foreach ($passages as $index => $passage) {
            // The count check above guarantees this exists at runtime; the `??` is here only so the
            // analyser can see it too, the same idiom InMemoryPassageStore::add() uses.
            $vector = $vectors[$index] ?? throw new \RuntimeException('no vector for passage');

            $this->connection->executeStatement('INSERT INTO `'
            . self::TABLE
            . '` '
            . '(`id`, `document_id`, `document_name`, `sales_channel_id`, `section`, `text`, `vector`, `dimension`, `created_at`) '
            . 'VALUES (:id, :documentId, :documentName, :channel, :section, :text, :vector, :dimension, :createdAt)', [
                'id' => Uuid::randomBytes(),
                'documentId' => $passage->documentId,
                'documentName' => $passage->documentName,
                'channel' => $salesChannelId,
                'section' => $passage->section,
                'text' => $passage->text,
                'vector' => json_encode($vector, \JSON_THROW_ON_ERROR),
                'dimension' => \count($vector),
                'createdAt' => $now,
            ]);
        }
    }

    /**
     * @param list<float> $vector
     *
     * @return list<ShopInfoPassage>
     *
     * @throws \Doctrine\DBAL\Exception
     * @throws \RuntimeException        when the store holds passages and the query vector is a
     *                                  different width — mirrors `AiStorePassageStore::query()`, so
     *                                  a merchant reading a log cannot tell which store produced it
     */
    public function query(array $vector, string $salesChannelId, float $minScore, int $limit): array
    {
        $width = $this->dimension();

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

        // Every row for this channel, and no LIMIT: the ranking happens in PHP, so narrowing here
        // would discard candidates before anything had scored them. The channel filter is not an
        // optimisation — spec R12 — two channels have genuinely different terms.
        $rows = $this->connection->fetchAllAssociative('SELECT `document_id`, `document_name`, `section`, `text`, `vector` '
        . 'FROM `'
        . self::TABLE
        . '` WHERE `sales_channel_id` = :channel', ['channel' => $salesChannelId]);

        $candidates = [];

        foreach ($rows as $row) {
            $stored = json_decode((string) $row['vector'], true);

            // A row whose vector will not decode is skipped rather than fatal: one corrupt row must
            // not cost the shopper every other passage in the shop.
            if (!\is_array($stored)) {
                continue;
            }

            $candidates[] = [
                'passage' => new ShopInfoPassage(
                    (string) $row['document_id'],
                    (string) $row['document_name'],
                    (string) $row['section'],
                    (string) $row['text'],
                ),
                'vector' => array_map(static fn(mixed $value): float => (float) $value, array_values($stored)),
            ];
        }

        return PassageRanking::of($vector, $candidates, $minScore, $limit);
    }

    /** @throws \Doctrine\DBAL\Exception */
    public function deleteDocument(string $documentId): void
    {
        $this->connection->executeStatement('DELETE FROM `' . self::TABLE . '` WHERE `document_id` = :documentId', [
            'documentId' => $documentId,
        ]);
    }

    /**
     * The width the store currently holds, or null when it holds nothing.
     *
     * Read from the column rather than by decoding a vector, and from any row: `StoreWidth` already
     * guarantees one width per store, so the first row answers for all of them.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function dimension(): ?int
    {
        $dimension = $this->connection->fetchOne('SELECT `dimension` FROM `' . self::TABLE . '` LIMIT 1');

        return \is_numeric($dimension) ? (int) $dimension : null;
    }
}
