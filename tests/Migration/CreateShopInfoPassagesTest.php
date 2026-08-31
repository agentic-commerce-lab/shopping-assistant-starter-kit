<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Migration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Migration\Migration1788393600CreateShopInfoPassages;
use Swag\AssistantStarterKit\PluginLifecycle\AssistantTableRemoval;

/**
 * The portable store's table.
 *
 * Unlike `swag_assistant_shop_info_vector`, which is created lazily because a `VECTOR` column's width
 * is fixed at creation and depends on the embedding model, this table's vector column is `JSON` and
 * has no width — so it can be an ordinary migration, which also means Doctrine can describe it and
 * uninstall can find it from the migration list rather than by remembering.
 */
final class CreateShopInfoPassagesTest extends TestCase
{
    /** @param list<string> $statements */
    private function connectionRecording(array &$statements): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 0;
            });

        return $connection;
    }

    public function testItCreatesTheTableWithTheColumnsTheStoreWrites(): void
    {
        $statements = [];
        (new Migration1788393600CreateShopInfoPassages())->update($this->connectionRecording($statements));

        $sql = implode("\n", $statements);

        foreach ([
            'swag_assistant_shop_info_passage',
            'document_id',
            'document_name',
            'sales_channel_id',
            'section',
            'text',
            'vector',
            'dimension',
        ] as $fragment) {
            self::assertStringContainsString($fragment, $sql);
        }
    }

    /**
     * `document_id` is `VARCHAR(64)`, deliberately not `BINARY(16)`. The id crosses
     * `PassageStore::deleteDocument(string $documentId)`, which promises no particular format, and
     * both existing implementations already treat it as an opaque string: `InMemoryPassageStore`
     * compares it directly, and `AiStorePassageStore` stores it verbatim in the vector row's
     * metadata. A `BINARY(16)` column would force a hex conversion the interface never promised —
     * and would only fail at runtime, as a bind mismatch, once the store that writes this table
     * lands. Asserting the bare substring `document_id` (as the previous test still does, for the
     * other columns) would pass identically whether this column were correct or reverted; this
     * assertion pins the declaration itself so that reversion fails here instead.
     *
     * `id` stays `BINARY(16)` on purpose — it is the table's own surrogate key and never crosses
     * `PassageStore`, so it gets the opposite treatment. Pinning both together keeps the contrast
     * between them legible instead of just documented in a docblock nobody re-reads.
     */
    public function testDocumentIdIsAnOpaqueStringWhileIdStaysTheTablesOwnSurrogateKey(): void
    {
        $statements = [];
        (new Migration1788393600CreateShopInfoPassages())->update($this->connectionRecording($statements));

        $sql = implode("\n", $statements);

        self::assertStringContainsString('`document_id` VARCHAR(64)', $sql);
        self::assertStringContainsString('`id` BINARY(16)', $sql);
    }

    /**
     * Every query filters by channel (spec R12): two channels genuinely have different terms, and a
     * query without that filter serves one shop's revocation notice in another.
     *
     * Asserting `sales_channel_id` and `KEY` as two independent substrings would pass even if the
     * channel index were dropped entirely, since both substrings exist in the SQL for unrelated
     * reasons (the column itself, and the `PRIMARY KEY` clause). Asserting the index declaration as
     * one string is what actually ties the guarantee to the index that provides it.
     */
    public function testTheChannelIsIndexedBecauseEveryQueryFiltersOnIt(): void
    {
        $statements = [];
        (new Migration1788393600CreateShopInfoPassages())->update($this->connectionRecording($statements));

        self::assertStringContainsString('KEY `idx.swag_assistant_shop_info_passage.channel` (`sales_channel_id`)', implode(
            "\n",
            $statements,
        ));
    }

    public function testDestructiveDoesNothing(): void
    {
        $statements = [];
        (new Migration1788393600CreateShopInfoPassages())->updateDestructive($this->connectionRecording($statements));

        self::assertSame([], $statements);
    }

    /**
     * The constraint from spec D7. A table added without this line survives an uninstall a merchant
     * performed to remove their data.
     */
    public function testUninstallRemovesTheNewTable(): void
    {
        self::assertContains('swag_assistant_shop_info_passage', AssistantTableRemoval::TABLES);
    }
}
