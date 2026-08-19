<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

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

// shopware/core is genuinely used — SwagAssistantStarterKit extends Shopware\Core\Framework\Plugin.
// shopware/storefront is not, yet: the first consumer is the storefront controller, which extends
// Shopware\Storefront\Controller\StorefrontController. It is required now rather than later because
// resolving the Shopware tree against the pinned Symfony 7.4 and Symfony AI 0.12 versions is the
// risky part and it is now proven; deferring the package would only repeat that risk.
// REMOVE THIS IGNORE when the storefront controller lands.
$config->ignoreErrorsOnPackage('shopware/storefront', [ErrorType::UNUSED_DEPENDENCY]);

// Scan tests as dev paths only when the directory exists (addPathToScan throws on a
// missing path, which would break the gate on projects without a tests/ directory).
if (is_dir(__DIR__ . '/tests')) {
    $config->addPathToScan(__DIR__ . '/tests', isDev: true);
}

return $config;
