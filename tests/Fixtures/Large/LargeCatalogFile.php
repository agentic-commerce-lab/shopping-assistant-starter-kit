<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Fixtures\Large;

use Swag\AssistantStarterKit\Tests\Fixtures\GeneratedCatalogue;

/**
 * Where the generated large catalogue lives, and generating it when it is absent or stale.
 *
 * `var/` is already gitignored, which is spec decision S2's other half: the generator is the reviewable
 * artefact and the file is a build output. Regenerating it must therefore be free, and it is — 6 ms of
 * pure arithmetic over a 12-product JSON, measured.
 *
 * **The environment switch used to live here** as `chosen()`. It moved to
 * {@see \Swag\AssistantStarterKit\Tests\Fixtures\EvalCatalogue} when a third catalogue arrived: a
 * class named after one catalogue is the wrong place to decide between three. The write-and-stamp
 * mechanics moved to {@see GeneratedCatalogue} at the same time, for the same reason.
 */
final class LargeCatalogFile
{
    private function __construct() {}

    /** Absolute path to the generated catalogue, written when absent or stale. */
    public static function path(): string
    {
        return GeneratedCatalogue::ensure(
            self::largePath(),
            [
                __DIR__ . '/LargeCatalogGenerator.php',
                __DIR__ . '/ScaleTrapProducts.php',
                __DIR__ . '/ScaleTrap.php',
                self::smallPath(),
            ],
            static fn(): string => (new LargeCatalogGenerator(self::smallPath()))->toJson(),
        );
    }

    /**
     * Where the generated catalogue lives, without generating it.
     *
     * {@see self::path()} is the same location plus the guarantee that the file there is current. Split
     * so a caller that only needs to name the file — a cleanup, a `.gitignore` check — does not write
     * 769 KB as a side effect of asking.
     */
    public static function largePath(): string
    {
        return self::repositoryRoot() . '/var/catalog-large.json';
    }

    public static function smallPath(): string
    {
        return self::repositoryRoot() . '/tests/Fixtures/catalog.json';
    }

    /** Delegated so callers that hold a `LargeCatalogFile` path do not have to know who writes it. */
    public static function stampPath(string $path): string
    {
        return GeneratedCatalogue::stampPath($path);
    }

    private static function repositoryRoot(): string
    {
        return \dirname(__DIR__, levels: 3);
    }
}
