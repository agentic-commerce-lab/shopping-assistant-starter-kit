<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Eval\Journey;
use Swag\AssistantStarterKit\Eval\JourneyRunner;
use Swag\AssistantStarterKit\Tests\Fixtures\Large\LargeCatalogFile;

/**
 * Drives every journey under tests/Journeys/ through a real LLM endpoint and checks it
 * against its own trace-based assertions. Skipped — never failed — unless
 * ASSISTANT_LLM_BASE_URL, ASSISTANT_LLM_API_KEY and ASSISTANT_LLM_MODEL are ALL set to a
 * non-empty value, so the full suite stays green in CI with no credentials configured.
 *
 * Ruling R43: the original `setUp()` checked only `ASSISTANT_LLM_BASE_URL`. With that
 * one variable set but `API_KEY` missing, the test would have attempted a real,
 * unauthenticated network call instead of skipping — and a stated acceptance criterion
 * for this suite is that it skips cleanly, not that it fails informatively. All three
 * are now checked here, and `getenv()` returning `false` (unset) and `''` (set but
 * empty) are both treated as "missing" — an empty credential is not a usable one.
 */
#[Group('eval')]
final class JourneyEvalTest extends TestCase
{
    protected function setUp(): void
    {
        if (
            !self::isConfigured(getenv('ASSISTANT_LLM_BASE_URL'))
            || !self::isConfigured(getenv('ASSISTANT_LLM_API_KEY'))
            || !self::isConfigured(getenv('ASSISTANT_LLM_MODEL'))
        ) {
            self::markTestSkipped(
                'Set ASSISTANT_LLM_BASE_URL, ASSISTANT_LLM_API_KEY and ASSISTANT_LLM_MODEL to run the eval suite.',
            );
        }
    }

    /** @return iterable<string, array{string}> */
    public static function journeys(): iterable
    {
        foreach (glob(__DIR__ . '/../Journeys/*.php') ?: [] as $path) {
            yield basename($path, '.php') => [$path];
        }
    }

    #[DataProvider('journeys')]
    public function testJourneyMeetsItsAssertions(string $path): void
    {
        $journey = Journey::fromFile($path);

        // setUp() already guarantees this is a non-empty string; re-checked here only
        // so the analyzer can narrow LlmSettings::$model's non-empty-string parameter
        // type without a pragma (the check between two separate method calls is not
        // something static analysis can see across, even though it always holds).
        $model = (string) getenv('ASSISTANT_LLM_MODEL');
        if ('' === $model) {
            self::markTestSkipped('ASSISTANT_LLM_MODEL must not be empty.');
        }

        $settings = new LlmSettings(
            baseUrl: (string) getenv('ASSISTANT_LLM_BASE_URL'),
            apiKey: (string) getenv('ASSISTANT_LLM_API_KEY'),
            model: $model,
        );

        // Which catalogue this journey runs against. `ASSISTANT_EVAL_CATALOG=large` swaps in the
        // generated one; anything else, including unset, keeps the twelve-product fixture every
        // expectation in tests/Journeys was written against. See spec decision S6 — the large run is
        // opt-in because the suite already costs ten minutes and real money.
        // The shop document a `shop_info_*` journey retrieves from. Passed unconditionally: a journey
        // that does not configure an embeddingModel never touches it, and one that does must not be
        // able to run without it — see JourneyAttempt::shopInfoFactories().
        $runner = new JourneyRunner(
            $settings,
            LargeCatalogFile::chosen(),
            null,
            __DIR__ . '/../Fixtures/shop_info_widerruf.txt',
        );
        $report = $runner->run($journey);

        if ($report->passed()) {
            self::assertTrue(true, $report->summary());

            return;
        }

        self::fail($report->summary());
    }

    private static function isConfigured(string|false $value): bool
    {
        return \is_string($value) && '' !== $value;
    }
}
