<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Core\Commerce\Fixture;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Commerce\Fixture\FixtureIndex;
use Swag\AssistantStarterKit\Core\Commerce\Fixture\FixtureProductDocuments;

/**
 * The fixture shape a scraped catalogue will carry, and the one thing it may not be allowed to fake.
 *
 * `DalProductDocuments` derives a document's extension from the media entity and filters on it, so
 * the two can never disagree in a real shop. A fixture free to declare its own extension could state
 * a combination the reader cannot produce — `pdf` on a `.docx` — and a journey built on it would
 * pass against a shape that does not exist. Deriving it from the URL keeps the fixture as honest as
 * the DAL.
 *
 * @phpstan-import-type FixtureProduct from FixtureIndex
 */
#[CoversClass(FixtureProductDocuments::class)]
final class FixtureProductDocumentsTest extends TestCase
{
    /**
     * A minimal fixture product carrying the documents under test. The shape is
     * `FixtureIndex`'s own, so this exercises exactly what a catalogue file produces.
     *
     * @param list<array{title: string, url: string}> $documents
     *
     * @return FixtureProduct
     */
    private static function product(array $documents): array
    {
        return [
            'id' => 'fx-017',
            'name' => 'YUASA Batterie YTZ10S',
            'description' => null,
            'price' => 89.90,
            'stock' => 7,
            'url' => '/detail/fx-017',
            'categoryPath' => [],
            'properties' => [],
            'variants' => [],
            'documents' => $documents,
        ];
    }

    public function testItDerivesTheExtensionFromTheUrl(): void
    {
        $documents = FixtureProductDocuments::ofProduct(self::product([
            ['title' => 'Technisches Datenblatt', 'url' => 'https://shop.example/media/ab/cd/datasheet.pdf'],
        ]));

        self::assertCount(1, $documents);
        self::assertSame(['Technisches Datenblatt'], array_map(static fn($d): string => $d->title, $documents));
        self::assertSame(['pdf'], array_map(static fn($d): string => $d->extension, $documents));
    }

    /**
     * Two of the real datasheets this was built against are `.PDF`, and the allowlist compares
     * lower-cased — so the fixture has to produce the same casing the DAL would.
     */
    public function testAnUppercaseExtensionIsNormalised(): void
    {
        $documents = FixtureProductDocuments::ofProduct(self::product([
            ['title' => 'SDB', 'url' => 'https://shop.example/media/40258592_SDB_Corexx_DE.PDF'],
        ]));

        self::assertSame(['pdf'], array_map(static fn($d): string => $d->extension, $documents));
    }

    /**
     * A query string is not part of the file name, and a media URL may well carry one.
     */
    public function testAQueryStringDoesNotBecomePartOfTheExtension(): void
    {
        $documents = FixtureProductDocuments::ofProduct(self::product([
            ['title' => 'Datenblatt', 'url' => 'https://shop.example/media/x.pdf?ts=1719307087'],
        ]));

        self::assertSame(['pdf'], array_map(static fn($d): string => $d->extension, $documents));
    }

    /**
     * Left empty rather than defaulted to `pdf`: a URL with no extension is a catalogue mistake,
     * and inventing the value the allowlist wants would hide it behind a passing test.
     */
    public function testAUrlWithNoExtensionGetsNone(): void
    {
        $documents = FixtureProductDocuments::ofProduct(self::product([
            ['title' => 'Datenblatt', 'url' => 'https://shop.example/media/datasheet'],
        ]));

        self::assertSame([''], array_map(static fn($d): string => $d->extension, $documents));
    }

    public function testNoDocumentsIsTheOrdinaryCase(): void
    {
        self::assertSame([], FixtureProductDocuments::ofProduct(self::product([])));
    }
}
