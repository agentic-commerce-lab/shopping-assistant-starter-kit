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
$config = (new Configuration())
    ->addPathToScan(__DIR__ . '/src', isDev: false)
    // Plan 1 only scaffolds the project and pins the Symfony AI stack; no src/ code
    // consumes symfony/ai-agent yet. A later task wires the agent/tool-calling layer that
    // will make this import real. Remove this ignore once that code lands and the
    // analyser finds genuine usage.
    ->ignoreErrorsOnPackage('symfony/ai-agent', [ErrorType::UNUSED_DEPENDENCY])
    // Task 4 imports Symfony\AI\Platform\* (PlatformInterface, Message, MessageBag, ...)
    // directly. That package is a real, permanent dependency of this code, not a
    // temporary gap — but composer.json intentionally requires only symfony/ai-agent and
    // symfony/ai-generic-platform (each pinned to 0.12.*), leaving ai-platform as their
    // shared transitive dependency. Declaring it directly would be the more conventional
    // fix; this ignore exists because this task was scoped to leave composer.json's
    // require block untouched.
    ->ignoreErrorsOnPackage('symfony/ai-platform', [ErrorType::SHADOW_DEPENDENCY])
    // ext-filter backs HostValidator's filter_var()/FILTER_VALIDATE_IP calls. It ships
    // enabled by default in virtually every PHP build; not declared in composer.json for
    // the same "leave require untouched" reason as above.
    ->ignoreErrorsOnExtension('ext-filter', [ErrorType::SHADOW_DEPENDENCY]);

// Scan tests as dev paths only when the directory exists (addPathToScan throws on a
// missing path, which would break the gate on projects without a tests/ directory).
if (is_dir(__DIR__ . '/tests')) {
    $config->addPathToScan(__DIR__ . '/tests', isDev: true);
}

return $config;
