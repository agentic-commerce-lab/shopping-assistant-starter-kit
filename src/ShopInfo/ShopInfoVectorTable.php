<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\ShopInfo;

use Doctrine\DBAL\Connection;
use Symfony\AI\Store\Bridge\MariaDb\Distance;
use Symfony\AI\Store\Bridge\MariaDb\Store;

/**
 * The vector table itself: where it is, how wide it is, and how a document leaves it.
 *
 * Separate from {@see AiStorePassageStore} because that class maps passages and this one owns schema.
 * Keeping them apart is also what holds each inside Mago's per-class complexity budget.
 *
 * **Why the table is created lazily rather than in a migration.** A `VECTOR` column has a fixed
 * width, and the width is decided by whichever embedding model the merchant configured — which, at
 * plugin-install time, is usually none. A migration would therefore have to guess (the library
 * guesses 1536), and a merchant on a 3072-wide model would get insert failures from a table created
 * before anyone knew what it was for. So the table is created on the first write, at the width the
 * configured model actually produced.
 *
 * **That is also this plugin's answer to `schema_filter`.** Doctrine cannot describe a `VECTOR`
 * column and would fail trying to manage one. It never sees this table: the library creates it with
 * raw DDL, it has no DAL entity so `dal:validate` does not know it, and Shopware evolves its own
 * schema through migrations rather than `doctrine:schema:update`. Nothing to filter, because nothing
 * inspects it.
 */
final readonly class ShopInfoVectorTable
{
    public const TABLE = 'swag_assistant_shop_info_vector';

    private const INDEX = 'swag_assistant_shop_info_vector_idx';

    private const FIELD = 'embedding';

    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * The library's store, pointed at this table.
     *
     * `Distance::Cosine` rather than the library's `Euclidean` default: cosine is the metric
     * embedding models are trained for, and the one spec R4's calibration assumes.
     *
     * @throws \Doctrine\DBAL\Exception propagated deliberately, following the migrations'
     *         precedent: a store that cannot reach its own database must fail loudly rather than
     *         degrade into a retrieval that silently finds nothing
     */
    public function store(): Store
    {
        return Store::fromDbal($this->connection, self::TABLE, self::INDEX, self::FIELD, Distance::Cosine);
    }

    /**
     * The column's width, or null when the table does not exist yet.
     *
     * Read from `information_schema`, which reports a vector column's `COLUMN_TYPE` as `vector(1536)`
     * — verified against MariaDB 11.8.8. The column definition is the authority rather than a sample
     * row, because it is what an `INSERT` is checked against.
     *
     * @return positive-int|null
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function dimension(): ?int
    {
        $type = $this->connection->fetchOne(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column',
            ['table' => self::TABLE, 'column' => self::FIELD],
        );

        if (!\is_string($type) || preg_match('/\((\d+)\)/', $type, $matches) !== 1) {
            return null;
        }

        $width = (int) $matches[1];

        return $width > 0 ? $width : null;
    }

    /**
     * Make sure the table exists and is `$dimension` wide, or explain why it cannot be.
     *
     * The empty-table case is widened silently on purpose: a merchant who changed the embedding model
     * and deleted their documents has nothing to lose, and refusing there would leave them with no
     * way forward short of manual SQL. With rows still in it, the refusal names both widths, because
     * the only correct action is re-indexing everything and the merchant has to know that.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function ensureWidth(int $dimension): void
    {
        if ($dimension < 1) {
            throw new \RuntimeException(
                'The embedding model returned a vector with no dimensions; there is nothing to store.',
            );
        }

        $current = $this->dimension();

        if ($current === $dimension) {
            return;
        }

        if ($current !== null && !$this->isEmpty()) {
            throw new \RuntimeException(\sprintf(
                'The shop information store holds %d-wide vectors but the configured embedding model '
                . 'produces %d. Changing the embedding model invalidates every indexed document: '
                . 'delete them and index them again.',
                $current,
                $dimension,
            ));
        }

        if ($current !== null) {
            $this->store()->drop();
        }

        $this->store()->setup(['dimensions' => $dimension]);
    }

    /** @throws \Doctrine\DBAL\Exception */
    public function isEmpty(): bool
    {
        return $this->connection->fetchOne(\sprintf('SELECT 1 FROM %s LIMIT 1', self::TABLE)) === false;
    }

    /**
     * Every row of one document (spec R8).
     *
     * A direct `DELETE` on the metadata rather than the library's `remove()`, which takes ids only.
     * Going through it would mean either selecting the ids first or trusting a stored chunk count to
     * still be right — and a stale count leaves orphaned passages that retrieval can still serve,
     * which is precisely the failure R8 exists to prevent.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function deleteByDocument(string $documentId): void
    {
        if ($this->dimension() === null) {
            return;
        }

        $this->connection->executeStatement(
            \sprintf(
                "DELETE FROM %s WHERE JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.documentId')) = :documentId",
                self::TABLE,
            ),
            ['documentId' => $documentId],
        );
    }
}
