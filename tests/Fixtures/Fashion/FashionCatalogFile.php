<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures\Fashion;

use Swag\AssistantStarterKit\Tests\Fixtures\GeneratedCatalogue;

/**
 * Where the generated fashion catalogue lives, and generating it when it is absent or stale.
 *
 * Costs more than its large-catalogue sibling — 5.7 MB and ~3,600 products rather than 769 KB and 515 —
 * which is exactly why the stamp matters here: a rebuild on every eval run would be paid on every run,
 * and a stale file would be measured while the report named the new generator.
 */
final class FashionCatalogFile
{
    private function __construct() {}

    /** Absolute path to the generated catalogue, written when absent or stale. */
    public static function path(): string
    {
        return GeneratedCatalogue::ensure(
            self::fashionPath(),
            [
                __DIR__ . '/FashionCatalogGenerator.php',
                __DIR__ . '/FashionTaxonomy.php',
                __DIR__ . '/FashionTrapProducts.php',
                self::smallPath(),
            ],
            static fn(): string => (new FashionCatalogGenerator(self::smallPath()))->toJson(),
        );
    }

    /** Where the generated catalogue lives, without generating it — see `LargeCatalogFile::largePath()`. */
    public static function fashionPath(): string
    {
        return self::repositoryRoot() . '/var/catalog-fashion.json';
    }

    public static function smallPath(): string
    {
        return self::repositoryRoot() . '/tests/Fixtures/catalog.json';
    }

    private static function repositoryRoot(): string
    {
        return \dirname(__DIR__, levels: 3);
    }
}
