<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

// The deterministic suite (composer test) must keep running with no .env at all — that
// is the state of every CI run — so loading is entirely conditional on the file's
// presence. The eval suite (tests/Eval/JourneyEvalTest.php) is skipped, never failed,
// when its three ASSISTANT_LLM_* variables are unset; this only offers an alternative
// to exporting them by hand for a local, credentialed run.
//
// usePutenv(true) is required so getenv() — what JourneyEvalTest actually calls — sees
// the loaded values; Dotenv only populates $_ENV/$_SERVER without it.
//
// load(), not overload(): load() leaves an already-set real environment variable alone
// (Dotenv::populate()'s $overrideExistingVars stays false), so `env ASSISTANT_LLM_MODEL=x
// vendor/bin/phpunit` still overrides whatever a committed-looking .env would otherwise
// provide. overload() would let the file win instead, which is the wrong default for a
// one-off override.
$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    (new Dotenv())
        ->usePutenv(true)
        ->load($envFile);
}
