<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests;

use PHPUnit\Framework\TestCase;

/**
 * `@api` is this repository's own marker for "someone outside wrote against this". A symbol carrying
 * it that {@see DocumentedExtensionPointsTest}'s guide never names is a seam nobody can find.
 *
 * Its own file rather than a fourth method on that class: walking `src/` is the only check here that
 * needs a directory traversal, and folding it in put the class over mago's cyclomatic-complexity
 * threshold. Splitting is what this codebase does with that, rather than raising the bar.
 */
final class ApiSymbolsAreDocumentedTest extends TestCase
{
    public function testEveryApiSymbolIsInTheExtensionGuide(): void
    {
        $guide = file_get_contents(__DIR__ . '/../docs/extending.md');
        self::assertIsString($guide);

        $undocumented = [];

        foreach ($this->apiSymbols() as $symbol) {
            if (!str_contains($guide, $symbol)) {
                $undocumented[] = $symbol;
            }
        }

        self::assertSame(
            [],
            $undocumented,
            'Marked @api but absent from docs/extending.md: ' . implode(', ', $undocumented),
        );
    }

    /** @return list<string> */
    private function apiSymbols(): array
    {
        $symbols = [];

        foreach ($this->phpSources() as $path) {
            $source = file_get_contents($path);

            if (\is_string($source) && str_contains($source, '@api')) {
                $symbols[] = basename($path, '.php');
            }
        }

        self::assertNotEmpty($symbols, 'no @api symbols found - has src/ moved?');

        return $symbols;
    }

    /** @return list<string> */
    private function phpSources(): array
    {
        $paths = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            __DIR__ . '/../src',
            \FilesystemIterator::SKIP_DOTS,
        ));

        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }

        return $paths;
    }
}
