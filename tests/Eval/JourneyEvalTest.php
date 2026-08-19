<?php

declare(strict_types=1);

namespace Swag\AssistantStarterKit\Tests\Eval;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Swag\AssistantStarterKit\Core\Llm\LlmSettings;
use Swag\AssistantStarterKit\Eval\Journey;
use Swag\AssistantStarterKit\Eval\JourneyRunner;

/**
 * Drives every journey under tests/Journeys/ through a real LLM endpoint and checks it
 * against its own trace-based assertions. Skipped — not failed — unless
 * ASSISTANT_LLM_BASE_URL, ASSISTANT_LLM_API_KEY and ASSISTANT_LLM_MODEL are all set, so
 * the full suite stays green in CI with no credentials configured.
 */
#[Group('eval')]
final class JourneyEvalTest extends TestCase
{
    protected function setUp(): void
    {
        if (false === getenv('ASSISTANT_LLM_BASE_URL')) {
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

        $model = (string) getenv('ASSISTANT_LLM_MODEL');
        if ('' === $model) {
            self::fail('ASSISTANT_LLM_MODEL must not be empty when ASSISTANT_LLM_BASE_URL is set.');
        }

        $settings = new LlmSettings(
            baseUrl: (string) getenv('ASSISTANT_LLM_BASE_URL'),
            apiKey: (string) getenv('ASSISTANT_LLM_API_KEY'),
            model: $model,
        );

        $runner = new JourneyRunner($settings, __DIR__ . '/../Fixtures/catalog.json');
        $report = $runner->run($journey);

        if ($report->passed()) {
            self::assertTrue(true, $report->summary());

            return;
        }

        self::fail($report->summary());
    }
}
