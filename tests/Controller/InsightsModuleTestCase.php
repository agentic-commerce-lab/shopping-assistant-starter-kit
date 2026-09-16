<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Reading the insights Administration module's files from PHP.
 *
 * **There is no PHP seam into the Administration.** The dashboard is a Vue component compiled by
 * Vite; nothing in this process can mount it, render it or resolve a `$tc()` through it. So the two
 * test classes built on this base make text assertions over the module's own files, and they say so
 * rather than letting a green bar imply the page was looked at. A browser does that job, and a
 * passing suite here is not a substitute — the `storefront-js-needs-rebuild-not-just-cache-clear`
 * lesson records a feature that silently did not render for a day while every test passed.
 *
 * A base class rather than one test class with fifteen methods: mago's pre-commit lint rejected the
 * single class on `too-many-methods`, `kan-defect` and class-level `cyclomatic-complexity` at once.
 * Splitting it by subject — structure in {@see InsightsDashboardAssetsTest}, wording in
 * {@see InsightsDashboardLabelsTest} — was the fix. Loosening the thresholds was not considered.
 */
abstract class InsightsModuleTestCase extends TestCase
{
    protected const MODULE = __DIR__ . '/../../src/Resources/app/administration/src/module/swag-assistant-insights';

    protected const PAGE = self::MODULE . '/page/swag-assistant-insights-dashboard';

    protected static function twig(): string
    {
        return self::read(self::PAGE . '/swag-assistant-insights-dashboard.html.twig');
    }

    /**
     * @return array<mixed>
     */
    protected static function snippets(string $locale): array
    {
        $file = self::read(self::MODULE . '/snippet/' . $locale . '.json');
        $decoded = json_decode($file, true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded, $locale . ' must decode to an object');

        return $decoded;
    }

    /**
     * The string at a dotted path, or `''` when the path does not reach one.
     *
     * `''` rather than an exception so a failure reads as "this label is missing", with the key in
     * the message, instead of an array-access notice raised from inside a helper.
     *
     * @param array<mixed> $tree
     */
    protected static function leaf(array $tree, string $path): string
    {
        $node = $tree;

        foreach (explode('.', $path) as $segment) {
            if (!\is_array($node) || !\array_key_exists($segment, $node)) {
                return '';
            }

            $node = $node[$segment];
        }

        return \is_string($node) ? $node : '';
    }

    protected static function read(string $path): string
    {
        $contents = file_get_contents($path);

        self::assertIsString($contents, $path . ' must exist');

        return $contents;
    }
}
