<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Command;

use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Command\ProbeTurnRunner;
use Swag\AssistantStarterKit\Core\Commerce\FixtureCommerceGateway;
use Swag\AssistantStarterKit\Tests\LlmEnvironmentGuard;
use Swag\AssistantStarterKit\Tests\Support\UsesCatalogFixture;

/**
 * Where `swag:assistant:probe` looks for its model credentials, which until now was one of the three
 * places they can be.
 *
 * {@see \Swag\AssistantStarterKit\Core\Config\EnvironmentValue} exists because `getenv()` reads the
 * real process environment and nothing else, while Symfony's runtime boots Dotenv with
 * `usePutenv(false)` — so a variable an operator writes into `.env` or `.env.local` lands in `$_ENV`
 * and `$_SERVER` and stays invisible to `getenv()`. That class fixed the storefront path on
 * 2026-09-01 and its own docblock recorded what was left: *"`Command\ProbeTurnRunner` reads the same
 * three variables through `getenv()` alone and has the same bug; it should come here too."*
 *
 * The symptom was the confusing kind rather than the silent one. The shop answers, the orb renders,
 * and the developer tool built to diagnose that same shop refuses to run:
 *
 *     $ bin/console swag:assistant:probe --ask="do you sell tyres"
 *     Not configured: ASSISTANT_LLM_BASE_URL, ASSISTANT_LLM_API_KEY, ASSISTANT_LLM_MODEL.
 *
 * with all three sitting in the shop's own `.env`. `composer run test:eval` could never have caught
 * it: `Shopware\Core\TestBootstrapper` is the one caller in the stack that does
 * `(new Dotenv())->usePutenv()`, which is exactly the false confidence that kept this open.
 *
 * The guard is not decoration — this suite runs on machines that have real credentials in all three
 * sources, and a test that only cleared `putenv()` would pass in CI and fail here.
 */
final class ProbeEnvironmentSourcesTest extends TestCase
{
    use LlmEnvironmentGuard;
    use UsesCatalogFixture;

    protected function setUp(): void
    {
        $this->clearLlmEnvironment();
    }

    protected function tearDown(): void
    {
        $this->restoreLlmEnvironment();
    }

    /** With nothing set anywhere, all three are missing — the baseline the other two rest on. */
    public function testNothingSetAnywhereReportsAllThree(): void
    {
        self::assertSame(ProbeTurnRunner::REQUIRED_ENV, $this->runner()->missingEnvironment());
    }

    /**
     * The reported case. `.env` reaches `$_ENV` and `$_SERVER` and never `getenv()`, so a probe that
     * read only the process environment declared a fully configured shop unconfigured.
     */
    public function testCredentialsFromADotEnvFileAreFound(): void
    {
        foreach (ProbeTurnRunner::REQUIRED_ENV as $name) {
            $_ENV[$name] = 'from-dot-env';
            $_SERVER[$name] = 'from-dot-env';
        }

        self::assertSame([], $this->runner()->missingEnvironment());
    }

    /**
     * And a blank value still counts as missing, whichever source it came from: `EnvironmentValue`
     * falls through a blank the same way it falls through an absent one, because an empty credential
     * is not a usable one (the ruling `JourneyEvalTest` records as R43, applied to the same three
     * variables here).
     */
    public function testABlankValueInAFileIsStillMissing(): void
    {
        foreach (ProbeTurnRunner::REQUIRED_ENV as $name) {
            $_ENV[$name] = '   ';
        }

        self::assertSame(ProbeTurnRunner::REQUIRED_ENV, $this->runner()->missingEnvironment());
    }

    private function runner(): ProbeTurnRunner
    {
        return new ProbeTurnRunner(FixtureCommerceGateway::fromFile(self::catalogFixturePath()));
    }
}
