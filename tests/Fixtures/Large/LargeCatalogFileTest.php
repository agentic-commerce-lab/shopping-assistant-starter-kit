<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures\Large;

use PHPUnit\Framework\TestCase;

/**
 * The one part of this that touches disk, separated from the generator so the generator stays
 * testable without a filesystem — and tested here because "the eval ran against the wrong
 * catalogue" is the failure mode that would waste a ten-minute model run.
 */
final class LargeCatalogFileTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('ASSISTANT_EVAL_CATALOG');

        // No test may leave a poisoned cache behind. Two tests below deliberately write a junk
        // catalogue, and a junk catalogue whose stamp still matches it is exactly what `path()` is
        // built to trust — so an interrupted run could hand 24 lines of `{"products": []}` to a
        // ten-minute eval as if it were the fixture. Dropping the stamp makes the next `path()`
        // rebuild whatever it finds, which is the one cleanup that cannot itself be skipped by a
        // failing assertion earlier in the test.
        $stamp = LargeCatalogFile::stampPath(LargeCatalogFile::largePath());

        if (is_file($stamp)) {
            unlink($stamp);
        }
    }

    public function testItDefaultsToTheSmallCatalogue(): void
    {
        putenv('ASSISTANT_EVAL_CATALOG');

        self::assertStringEndsWith('tests/Fixtures/catalog.json', LargeCatalogFile::chosen());
    }

    public function testAnUnknownValueDefaultsToTheSmallCatalogueRatherThanFailing(): void
    {
        // A typo must not silently produce a large run, and must not break a suite that was going
        // to skip anyway for want of credentials.
        putenv('ASSISTANT_EVAL_CATALOG=larg');

        self::assertStringEndsWith('tests/Fixtures/catalog.json', LargeCatalogFile::chosen());
    }

    public function testLargeSelectsTheGeneratedFileAndWritesIt(): void
    {
        putenv('ASSISTANT_EVAL_CATALOG=large');

        $path = LargeCatalogFile::chosen();

        self::assertStringEndsWith('var/catalog-large.json', $path);
        self::assertFileExists($path);

        /** @var array{products: list<array<string, mixed>>} $decoded */
        $decoded = json_decode(
            (string) file_get_contents($path),
            associative: true,
            depth: 512,
            flags: \JSON_THROW_ON_ERROR,
        );

        self::assertArrayHasKey('products', $decoded);
        self::assertGreaterThan(500, \count($decoded['products']));
    }

    public function testWritingTwiceProducesTheSameBytes(): void
    {
        $first = file_get_contents(LargeCatalogFile::path());

        if (is_file(LargeCatalogFile::path())) {
            unlink(LargeCatalogFile::path());
        }

        self::assertSame($first, file_get_contents(LargeCatalogFile::path()));
    }

    /**
     * The staleness check, which is the difference between a measurement and a misleading one.
     *
     * A cached file keyed only on existence survives a change to the generator, so a Task 5 run
     * would quietly measure the previous catalogue while the report named the current generator.
     * Rewriting the stamp stands in for that edit — it is exactly what an edited generator produces,
     * a stamp that no longer describes the file beside it — and the next `path()` must rebuild.
     */
    public function testAStampFromADifferentGeneratorRebuildsTheFile(): void
    {
        $path = LargeCatalogFile::path();
        $expected = (string) file_get_contents($path);

        file_put_contents($path . '.stamp', 'a stamp from a different generator');
        file_put_contents($path, '{"products": []}');

        self::assertSame($expected, file_get_contents(LargeCatalogFile::path()));
    }

    /**
     * The other half of the same behaviour: a stamp that still matches must NOT rebuild.
     *
     * Without this, a `path()` that rebuilt unconditionally would pass the test above while
     * rewriting 769 KB on each of a run's hundred-odd calls — and the staleness check would be
     * decoration rather than a cache.
     */
    public function testAMatchingStampIsLeftAlone(): void
    {
        $path = LargeCatalogFile::path();

        // A marker only a rebuild would remove. The stamp is untouched, so nothing should.
        file_put_contents($path, '{"products": []}');

        self::assertSame('{"products": []}', file_get_contents(LargeCatalogFile::path()));

        // Leave a correct catalogue behind: an eval run reads this file.
        unlink($path . '.stamp');
        self::assertGreaterThan(500_000, (int) filesize(LargeCatalogFile::path()));
    }
}
