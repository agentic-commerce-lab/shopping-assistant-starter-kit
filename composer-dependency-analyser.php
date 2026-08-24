<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;

// shipmonk/composer-dependency-analyser: detects unused + missing/shadow composer deps.
// Backs the `quality:depcheck` task. Adjust the scanned paths to the project's layout.
// See https://github.com/shipmonk-rnd/composer-dependency-analyser for the full config API.
//
// Add `->ignoreErrorsOnPackage('<vendor/pkg>', [ErrorType::UNUSED_DEPENDENCY])` (with
// `use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;`) only for a real finding —
// the analyser reports unmatched ignores as errors, so do not pre-declare ignores that do
// not yet apply.
//
// The symfony/ai-agent ignore that used to live here (Plan 1 pinned the package before any
// src/ code consumed it) is gone: Task 10's SearchProductsTool/GetProductTool now import
// Symfony\AI\Agent\Toolbox\Attribute\AsTool, so the dependency is genuinely used and the
// analyser no longer reports it as unused.
$config = (new Configuration())->addPathToScan(__DIR__ . '/src', isDev: false);

// shopware/core and shopware/storefront are both genuinely used now: SwagAssistantStarterKit
// extends Shopware\Core\Framework\Plugin, and AssistantController extends
// Shopware\Storefront\Controller\StorefrontController. The temporary UNUSED_DEPENDENCY ignore for
// shopware/storefront is gone — the analyser reported it as "never applied", which is exactly the
// signal the note asked the next reader to watch for.

// Scan tests as dev paths only when the directory exists (addPathToScan throws on a
// missing path, which would break the gate on projects without a tests/ directory).
if (is_dir(__DIR__ . '/tests')) {
    $config->addPathToScan(__DIR__ . '/tests', isDev: true);
}

// The analyser reads PHP, and `symfony/cache` is referenced from `services.xml`: the container
// instantiates `FilesystemAdapter` for the rate limiter's own storage pool, which is a production
// dependency the scanner cannot see. Without this it reports the package as dev-only, because the
// only PHP mentioning it is the test that pins the limiter's persistence.
//
// The limiter owns that pool rather than using `cache.app` for a measured reason — see the comment
// on `swag_assistant.rate_limiter_cache` in `services.xml`.
$config->ignoreErrorsOnPackage('symfony/cache', [
    \ShipMonk\ComposerDependencyAnalyser\Config\ErrorType::PROD_DEPENDENCY_ONLY_IN_DEV,
]);

return $config;
