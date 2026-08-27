<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Tests\Fixtures\Fashion\FashionCatalogFile;

/**
 * The environment switch, tested where it lives.
 *
 * "The eval ran against the wrong catalogue" is the failure mode that would waste a ten-minute model
 * run and produce a report about a shop nobody has — which is why a string-to-string mapping gets its
 * own test class.
 *
 * **Nothing here calls `chosen()` for `fashion`.** That would generate 5.7 MB as a side effect of a
 * string assertion. The name and the path are asserted separately, and the first eval run is what
 * writes the file.
 */
final class EvalCatalogueTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('ASSISTANT_EVAL_CATALOG');
    }

    public function testItDefaultsToTheSmallCatalogue(): void
    {
        putenv('ASSISTANT_EVAL_CATALOG');

        self::assertSame(EvalCatalogue::SMALL, EvalCatalogue::chosenName());
        self::assertStringEndsWith('tests/Fixtures/catalog.json', EvalCatalogue::chosen());
    }

    public function testAnUnknownValueDefaultsToTheSmallCatalogueRatherThanFailing(): void
    {
        // A typo must not silently produce a large or fashion run, and must not break a suite that was
        // going to skip anyway for want of credentials.
        putenv('ASSISTANT_EVAL_CATALOG=fashon');

        self::assertSame(EvalCatalogue::SMALL, EvalCatalogue::chosenName());
        self::assertStringEndsWith('tests/Fixtures/catalog.json', EvalCatalogue::chosen());
    }

    public function testItNamesTheLargeCatalogue(): void
    {
        putenv('ASSISTANT_EVAL_CATALOG=large');

        self::assertSame(EvalCatalogue::LARGE, EvalCatalogue::chosenName());
    }

    public function testItNamesTheFashionCatalogue(): void
    {
        putenv('ASSISTANT_EVAL_CATALOG=fashion');

        self::assertSame(EvalCatalogue::FASHION, EvalCatalogue::chosenName());
        self::assertStringEndsWith('var/catalog-fashion.json', FashionCatalogFile::fashionPath());
    }

    public function testTheValueIsCaseInsensitiveAndTrimmed(): void
    {
        putenv('ASSISTANT_EVAL_CATALOG= FASHION ');

        self::assertSame(EvalCatalogue::FASHION, EvalCatalogue::chosenName());
    }

    public function testLargeSelectsTheGeneratedFile(): void
    {
        putenv('ASSISTANT_EVAL_CATALOG=large');

        self::assertStringEndsWith('var/catalog-large.json', EvalCatalogue::chosen());
    }
}
